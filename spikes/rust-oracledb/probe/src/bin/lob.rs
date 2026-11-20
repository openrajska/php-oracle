//! Throwaway probe for the LOB half of the "LOB streaming and execute_batch
//! errors" ticket (#10) of the rust-oracledb spike.
//!
//! Reads the `fx_lobs` fixture (fixtures/sql/05_lob.sql) inline and through
//! LOB locators, streams a 16 MiB and a 100 MiB CLOB/BLOB in and out in
//! chunks while watching the process's peak RSS, and checks every byte
//! against the deterministic generator the fixture documents. Same tags as
//! the other probes:
//!
//!   PASS / FAIL  the value read back matches / does not match the expectation
//!   ERR          the call returned an error although a value was expected
//!   OBS          no fixed expectation: the line records what the driver does
//!
//! Lines starting with `~` carry timing and memory for the case above them.
//! Peak RSS is VmHWM from /proc/self/status, reset before each measured case
//! by writing 5 to /proc/self/clear_refs.
//!
//! Leaves nothing behind: every write is rolled back at the end.
//!
//! Run inside ddev: `cd spikes/rust-oracledb/probe && ddev cargo run -q --release --bin lob`.

use oracledb::{Connection, DB_TYPE_BLOB, DB_TYPE_CLOB, DB_TYPE_NUMBER, Lob};
use std::fmt::Debug;
use std::io::{self, Read, Seek, SeekFrom, Write};
use std::time::Instant;

type R<T> = Result<T, Box<dyn std::error::Error>>;

struct Tally {
    pass: u32,
    fail: u32,
    err: u32,
    obs: u32,
}

impl Tally {
    fn check<T: Debug>(&mut self, id: &str, what: &str, expected: &str, got: R<T>) {
        match got {
            Ok(v) => {
                let s = format!("{v:?}");
                if s == expected {
                    self.pass += 1;
                    println!("PASS {id:<5} {what} => {s}");
                } else {
                    self.fail += 1;
                    println!("FAIL {id:<5} {what} => {s} (expected {expected})");
                }
            }
            Err(e) => {
                self.err += 1;
                println!("ERR  {id:<5} {what} => {}", one_line(&e.to_string()));
            }
        }
    }

    fn observe<T: Debug>(&mut self, id: &str, what: &str, got: R<T>) {
        self.obs += 1;
        match got {
            Ok(v) => println!("OBS  {id:<5} {what} => Ok({v:?})"),
            Err(e) => println!("OBS  {id:<5} {what} => Err({})", one_line(&e.to_string())),
        }
    }

    /// Checks the (bytes, equal) of a measured case and prints its stats.
    fn measured(&mut self, id: &str, what: &str, expected: &str, got: R<Measured>) {
        let stats = got.as_ref().ok().map(|m| m.stats.clone());
        self.check(id, what, expected, got.map(|m| m.outcome));
        if let Some(s) = stats {
            println!("~          {s}");
        }
    }
}

fn one_line(s: &str) -> String {
    s.lines().next().unwrap_or("").to_string()
}

fn connect() -> R<Connection> {
    let config = oracledb::Config::default()
        .set_credentials(&std::env::var("ORACLE_USER")?, &std::env::var("ORACLE_PASSWORD")?)
        .set_connect_string(&std::env::var("ORACLE_CONNECT_STRING")?)?;
    Ok(oracledb::connect(config)?)
}

// ---------------------------------------------------------------------------
// Memory
// ---------------------------------------------------------------------------

fn status_mib(field: &str) -> f64 {
    let s = std::fs::read_to_string("/proc/self/status").unwrap_or_default();
    s.lines()
        .find(|l| l.starts_with(field))
        .and_then(|l| l.split_whitespace().nth(1))
        .and_then(|kb| kb.parse::<f64>().ok())
        .map(|kb| kb / 1024.0)
        .unwrap_or(f64::NAN)
}

/// Resets the peak RSS (VmHWM) to the current RSS.
fn reset_peak() {
    let _ = std::fs::write("/proc/self/clear_refs", "5");
}

/// Runs `f`, returning its value and "time, MiB/s, round trips, RSS" stats.
struct Meter {
    start: Instant,
    rss_before: f64,
}

impl Meter {
    fn start() -> Self {
        reset_peak();
        Self { start: Instant::now(), rss_before: status_mib("VmRSS:") }
    }

    fn stats(&self, bytes: u64, calls: u64) -> String {
        let secs = self.start.elapsed().as_secs_f64();
        format!(
            "{:.2} s, {:.0} MiB/s, {calls} LOB calls, RSS {:.1} MiB before, peak {:.1} MiB",
            secs,
            bytes as f64 / MIB as f64 / secs,
            self.rss_before,
            status_mib("VmHWM:"),
        )
    }
}

struct Measured {
    outcome: (u64, bool),
    stats: String,
}

// ---------------------------------------------------------------------------
// Deterministic fixture content (fixtures/sql/README.md, "fx_lobs")
// ---------------------------------------------------------------------------

const MIB: u64 = 1 << 20;
const LINES_PER_MIB: u64 = 16384;
/// UTF-8 bytes per CLOB line (64 characters).
const LINE_BYTES: u64 = 71;

fn text_line(n: u64) -> String {
    format!("{n:08} ação café 日本 {}\n", "x".repeat(41))
}

/// The CLOB that `fx_lob.generate(id, mib)` writes, as a byte stream.
struct TextGen {
    next: u64,
    last: u64,
    line: Vec<u8>,
    pos: usize,
}

impl TextGen {
    fn mib(mib: u64) -> Self {
        Self { next: 1, last: mib * LINES_PER_MIB, line: Vec::new(), pos: 0 }
    }

    /// The next run of whole lines, about `max_bytes` long: complete UTF-8,
    /// as each CLOB `write()` call needs.
    fn whole_lines(&mut self, max_bytes: usize) -> Option<String> {
        if self.next > self.last {
            return None;
        }
        let mut s = String::with_capacity(max_bytes + LINE_BYTES as usize);
        while self.next <= self.last && (s.is_empty() || s.len() + (LINE_BYTES as usize) <= max_bytes) {
            s.push_str(&text_line(self.next));
            self.next += 1;
        }
        Some(s)
    }
}

impl Read for TextGen {
    fn read(&mut self, out: &mut [u8]) -> io::Result<usize> {
        if self.pos == self.line.len() {
            if self.next > self.last {
                return Ok(0);
            }
            self.line = text_line(self.next).into_bytes();
            self.next += 1;
            self.pos = 0;
        }
        let n = out.len().min(self.line.len() - self.pos);
        out[..n].copy_from_slice(&self.line[self.pos..self.pos + n]);
        self.pos += n;
        Ok(n)
    }
}

/// The BLOB that `fx_lob.generate(id, mib)` writes: byte k is k mod 256.
struct BinGen {
    k: u64,
    end: u64,
}

impl BinGen {
    fn mib(mib: u64) -> Self {
        Self { k: 0, end: mib * MIB }
    }
}

impl Read for BinGen {
    fn read(&mut self, out: &mut [u8]) -> io::Result<usize> {
        let n = out.len().min((self.end - self.k) as usize);
        for (i, b) in out[..n].iter_mut().enumerate() {
            *b = ((self.k + i as u64) % 256) as u8;
        }
        self.k += n as u64;
        Ok(n)
    }
}

/// Streams `src` through a `buf_size` buffer, comparing every chunk with
/// `expected`. Holds at most two buffers, never the whole value.
fn verify_stream(src: &mut impl Read, expected: &mut impl Read, buf_size: usize) -> R<Measured> {
    let meter = Meter::start();
    let mut buf = vec![0u8; buf_size];
    let mut want = vec![0u8; buf_size];
    let (mut bytes, mut calls, mut equal) = (0u64, 0u64, true);
    loop {
        let n = src.read(&mut buf)?;
        if n == 0 {
            break;
        }
        calls += 1;
        bytes += n as u64;
        if equal && (expected.read_exact(&mut want[..n]).is_err() || buf[..n] != want[..n]) {
            equal = false;
        }
    }
    if equal && expected.read(&mut want[..1])? != 0 {
        equal = false; // the LOB ended early
    }
    Ok(Measured { outcome: (bytes, equal), stats: meter.stats(bytes, calls) })
}

/// Compares a whole in-memory value with the generator.
fn equals_stream(value: &[u8], expected: &mut impl Read) -> bool {
    let mut want = vec![0u8; 1 << 16];
    for chunk in value.chunks(1 << 16) {
        if expected.read_exact(&mut want[..chunk.len()]).is_err() || chunk != &want[..chunk.len()] {
            return false;
        }
    }
    expected.read(&mut want[..1]).map(|n| n == 0).unwrap_or(false)
}

// ---------------------------------------------------------------------------
// Fetch helpers
// ---------------------------------------------------------------------------

/// Row `id` of fx_lobs with LOBs fetched inline (the default).
fn inline(c: &Connection, id: i64) -> R<(Option<String>, Option<Vec<u8>>)> {
    let mut row = c.query_row("select text_value, binary_value from fx_lobs where id = :1", &[&id])?;
    Ok((row.take(0)?, row.take(1)?))
}

/// Row `id` of fx_lobs as LOB locators.
fn locators(c: &Connection, id: i64, for_update: bool) -> R<(Option<Lob>, Option<Lob>)> {
    let sql = if for_update {
        "select text_value, binary_value from fx_lobs where id = :1 for update"
    } else {
        "select text_value, binary_value from fx_lobs where id = :1"
    };
    let mut row = c.statement(sql)?.fetch_lobs().build()?.query_row(&[&id])?;
    Ok((row.take(0)?, row.take(1)?))
}

fn both(c: &Connection, id: i64, for_update: bool) -> R<(Lob, Lob)> {
    match locators(c, id, for_update)? {
        (Some(t), Some(b)) => Ok((t, b)),
        _ => Err("NULL LOB".into()),
    }
}

/// Server-side check of row `a` against row `b`: (length of a's CLOB,
/// dbms_lob.compare of the CLOBs, length of a's BLOB, compare of the BLOBs).
fn compare_rows(c: &Connection, a: i64, b: i64) -> R<(i64, i64, i64, i64)> {
    let row = c.query_row(
        "select dbms_lob.getlength(a.text_value), dbms_lob.compare(a.text_value, b.text_value),
                dbms_lob.getlength(a.binary_value), dbms_lob.compare(a.binary_value, b.binary_value)
           from fx_lobs a, fx_lobs b where a.id = :1 and b.id = :2",
        &[&a, &b],
    )?;
    Ok((row.get(0)?, row.get(1)?, row.get(2)?, row.get(3)?))
}

fn insert_empty(c: &Connection, id: i64) -> R<()> {
    c.execute(
        "insert into fx_lobs (id, label, text_value, binary_value) values (:1, 'probe', empty_clob(), empty_blob())",
        &[&id],
    )?;
    Ok(())
}

fn hex(b: &[u8]) -> String {
    b.iter().map(|x| format!("{x:02X}")).collect()
}

/// Number of temporary LOBs the current session holds (needs SELECT on
/// v$temporary_lobs and v$session).
fn temp_lob_count(c: &Connection) -> R<i64> {
    Ok(c.query_row(
        "select cache_lobs + nocache_lobs + abstract_lobs from v$temporary_lobs
          where sid = sys_context('USERENV', 'SID')",
        &[],
    )?
    .get(0)?)
}

/// A writer that only passes complete UTF-8 to a CLOB: it holds back an
/// incomplete trailing sequence until the next write. The workaround for
/// `io::copy` and other byte-oriented callers that split characters.
struct Utf8Boundary<W: Write> {
    inner: W,
    tail: Vec<u8>,
}

impl<W: Write> Write for Utf8Boundary<W> {
    fn write(&mut self, buf: &[u8]) -> io::Result<usize> {
        let mut data = std::mem::take(&mut self.tail);
        data.extend_from_slice(buf);
        let cut = match std::str::from_utf8(&data) {
            Ok(_) => data.len(),
            Err(e) if e.error_len().is_none() => e.valid_up_to(), // incomplete tail
            Err(e) => return Err(io::Error::new(io::ErrorKind::InvalidData, e)),
        };
        if cut > 0 {
            self.inner.write_all(&data[..cut])?;
        }
        self.tail = data[cut..].to_vec();
        Ok(buf.len())
    }

    fn flush(&mut self) -> io::Result<()> {
        if !self.tail.is_empty() {
            return Err(io::Error::new(io::ErrorKind::InvalidData, "incomplete UTF-8 at end of CLOB"));
        }
        self.inner.flush()
    }
}

const SMALL: &str = "Hello, LOB! Ação 日本";
const BIG_CLOB_BYTES: u64 = 16 * LINES_PER_MIB * LINE_BYTES; // 18,612,224
const HUGE: u64 = 100; // MiB for the streaming cases

fn main() -> R<()> {
    let c = connect()?;
    let mut t = Tally { pass: 0, fail: 0, err: 0, obs: 0 };

    println!("== Inline fetch, small values (default: LOBs come back as String / Vec<u8>) ==");
    t.check("I1", "row 1 small: CLOB text, BLOB hex", &format!("({:?}, {:?})", Some(SMALL), Some("DEADBEEF")), (|| {
        let (s, b) = inline(&c, 1)?;
        Ok((s, b.map(|b| hex(&b))))
    })());
    t.check("I2", "row 2 empty_clob / empty_blob", "(Some(\"\"), Some(\"\"))", (|| {
        let (s, b) = inline(&c, 2)?;
        Ok((s, b.map(|b| hex(&b))))
    })());
    t.check("I2b", "server-side: row 2 is not NULL (text_value / binary_value IS NOT NULL, lengths)", "(1, 1, 0, 0)", (|| {
        let row = c.query_row(
            "select nvl2(text_value, 1, 0), nvl2(binary_value, 1, 0), dbms_lob.getlength(text_value), dbms_lob.getlength(binary_value) from fx_lobs where id = 2",
            &[],
        )?;
        Ok((row.get::<i64>(0)?, row.get::<i64>(1)?, row.get::<i64>(2)?, row.get::<i64>(3)?))
    })());
    t.check("I3", "row 3 NULL", "(None, None)", inline(&c, 3));

    println!("== Locators (fetch_lobs) ==");
    t.check("L1", "row 1 via locators: read_to_string, read_to_end", &format!("({:?}, {:?})", SMALL, "DEADBEEF"), (|| {
        let (mut tl, mut bl) = both(&c, 1, false)?;
        let mut s = String::new();
        tl.read_to_string(&mut s)?;
        let mut b = Vec::new();
        bl.read_to_end(&mut b)?;
        Ok((s, hex(&b)))
    })());
    t.check("L2", "row 2 empty via locators: sizes, read_to_string, read_to_end len", "(0, 0, \"\", 0)", (|| {
        let (mut tl, mut bl) = both(&c, 2, false)?;
        let mut s = String::new();
        tl.read_to_string(&mut s)?;
        let mut b = Vec::new();
        bl.read_to_end(&mut b)?;
        Ok((tl.get_size()?, bl.get_size()?, s, b.len()))
    })());
    t.check("L3", "row 3 NULL via locators is None", "(true, true)", (|| {
        let (tl, bl) = locators(&c, 3, false)?;
        Ok((tl.is_none(), bl.is_none()))
    })());
    t.check("L4", "row 4 (16 MiB) sizes: CLOB in UCS-2 units, BLOB in bytes", "(16777216, 16777216)", (|| {
        let (mut tl, mut bl) = both(&c, 4, false)?;
        Ok((tl.get_size()?, bl.get_size()?))
    })());
    t.observe("L5", "row 4 chunk sizes (CLOB, BLOB)", (|| {
        let (mut tl, mut bl) = both(&c, 4, false)?;
        Ok((tl.get_chunk_size()?, bl.get_chunk_size()?))
    })());

    println!("== Streaming read, 16 MiB fixture (row 4), 64 KiB buffer ==");
    t.measured("S1", "CLOB streamed: UTF-8 bytes, every byte matches", &format!("({BIG_CLOB_BYTES}, true)"), (|| {
        let (mut tl, _) = both(&c, 4, false)?;
        verify_stream(&mut tl, &mut TextGen::mib(16), 64 << 10)
    })());
    t.measured("S2", "BLOB streamed: bytes, every byte matches", &format!("({}, true)", 16 * MIB), (|| {
        let (_, mut bl) = both(&c, 4, false)?;
        verify_stream(&mut bl, &mut BinGen::mib(16), 64 << 10)
    })());
    t.check("S3", "CLOB seek to line 100000 (offset in UCS-2 units) and read 64 characters", &format!("{:?}", text_line(100_000)), (|| {
        let (mut tl, _) = both(&c, 4, false)?;
        tl.seek(SeekFrom::Start((100_000 - 1) * 64 + 1))?;
        let mut buf = [0u8; 64 * 3];
        let n = tl.read(&mut buf)?;
        Ok(String::from_utf8(buf[..n].to_vec())?)
    })());
    t.check("S4", "BLOB seek to 1-based offset 1,000,001 and read 4 bytes", "[64, 65, 66, 67]", (|| {
        let (_, mut bl) = both(&c, 4, false)?;
        bl.seek(SeekFrom::Start(1_000_001))?;
        let mut buf = [0u8; 4];
        bl.read_exact(&mut buf)?;
        Ok(buf)
    })());
    t.observe("S5", "CLOB read with a 2-byte buffer through line 1 (results until the first error)", (|| {
        let (mut tl, _) = both(&c, 4, false)?;
        let mut buf = [0u8; 2];
        let mut got = String::new();
        for _ in 0..20 {
            match tl.read(&mut buf) {
                Ok(n) => got.push_str(&String::from_utf8_lossy(&buf[..n])),
                Err(e) => return Ok(format!("{got:?} then Err({e})")),
            }
        }
        Ok(format!("{got:?}"))
    })());
    t.observe("S6", "temporary CLOB 'a😀b' (supplemental char = 2 UCS-2 units): size, then reads with a 3-byte buffer", (|| {
        let mut l = c.create_lob(DB_TYPE_CLOB)?;
        l.write_all("a😀b".as_bytes())?;
        let size = l.get_size()?;
        l.seek(SeekFrom::Start(1))?;
        let mut buf = [0u8; 3];
        let mut reads = Vec::new();
        for _ in 0..5 {
            match l.read(&mut buf) {
                Ok(0) => break,
                Ok(n) => reads.push(format!("{:?}", String::from_utf8_lossy(&buf[..n]))),
                Err(e) => {
                    reads.push(format!("Err({e})"));
                    break;
                }
            }
        }
        Ok((size, reads))
    })());
    t.check("S7", "same temporary CLOB read whole with read_to_string", "\"a😀b\"", (|| {
        let mut l = c.create_lob(DB_TYPE_CLOB)?;
        l.write_all("a😀b".as_bytes())?;
        l.seek(SeekFrom::Start(1))?;
        let mut s = String::new();
        l.read_to_string(&mut s)?;
        Ok(s)
    })());

    let emoji = "😀".repeat(200_000); // 400,000 UCS-2 units, 800,000 UTF-8 bytes
    t.observe("S8", "temporary CLOB of 200,000 '😀' streamed with a 64 KiB buffer (21,845 units per read)", (|| {
        let mut l = c.create_lob(DB_TYPE_CLOB)?;
        l.write_all(emoji.as_bytes())?;
        l.seek(SeekFrom::Start(1))?;
        let mut buf = vec![0u8; 64 << 10];
        let mut total = 0usize;
        loop {
            match l.read(&mut buf) {
                Ok(0) => return Ok(format!("{total} bytes, no error")),
                Ok(n) => total += n,
                Err(e) => return Ok(format!("Err({e}) after {total} bytes")),
            }
        }
    })());
    t.check("S9", "same CLOB through a reader that retries a split surrogate with one unit less: (bytes, equal)", "(800000, true)", (|| {
        let mut l = c.create_lob(DB_TYPE_CLOB)?;
        l.write_all(emoji.as_bytes())?;
        l.seek(SeekFrom::Start(1))?;
        let mut buf = vec![0u8; 64 << 10];
        let mut got = Vec::new();
        loop {
            // A failed read leaves the offset alone, so asking for one UCS-2
            // unit less (3 bytes less of buffer) drops the lone high surrogate.
            let n = match l.read(&mut buf) {
                Err(e) if e.kind() == io::ErrorKind::InvalidData => {
                    let len = buf.len() - 3;
                    l.read(&mut buf[..len])?
                }
                r => r?,
            };
            if n == 0 {
                break;
            }
            got.extend_from_slice(&buf[..n]);
        }
        Ok((got.len(), got == emoji.as_bytes()))
    })());

    println!("== Buffer size vs throughput, 16 MiB fixture (row 4) ==");
    for (id, kib) in [("T1", 8u64), ("T2", 64), ("T3", 1024), ("T4", 8192)] {
        t.measured(id, &format!("BLOB streamed with a {kib} KiB buffer"), &format!("({}, true)", 16 * MIB), (|| {
            let (_, mut bl) = both(&c, 4, false)?;
            verify_stream(&mut bl, &mut BinGen::mib(16), (kib << 10) as usize)
        })());
    }
    for (id, kib) in [("T5", 64u64), ("T6", 1024), ("T7", 8192)] {
        t.measured(id, &format!("CLOB streamed with a {kib} KiB buffer"), &format!("({BIG_CLOB_BYTES}, true)"), (|| {
            let (mut tl, _) = both(&c, 4, false)?;
            verify_stream(&mut tl, &mut TextGen::mib(16), (kib << 10) as usize)
        })());
    }

    println!("== Streaming write and read, {HUGE} MiB (row 5 = fx_lob.generate reference, row 6 = written by the client) ==");
    t.observe("H0", &format!("server-side fx_lob.generate(5, {HUGE}) (reference, seconds)"), (|| {
        let start = Instant::now();
        c.execute("begin fx_lob.generate(5, :1); end;", &[&(HUGE as i64)])?;
        Ok(format!("{:.1}", start.elapsed().as_secs_f64()))
    })());
    t.measured("H1", &format!("CLOB {HUGE} MiB written in ~1 MiB runs of whole lines: (characters, all written)"), &format!("({}, true)", HUGE * MIB), (|| {
        insert_empty(&c, 6)?;
        let (mut tl, _) = both(&c, 6, true)?;
        let meter = Meter::start();
        let mut gen_ = TextGen::mib(HUGE);
        let (mut bytes, mut calls) = (0u64, 0u64);
        while let Some(s) = gen_.whole_lines(1 << 20) {
            tl.write_all(s.as_bytes())?;
            bytes += s.len() as u64;
            calls += 1;
        }
        Ok(Measured { outcome: (tl.get_size()? as u64, bytes == HUGE * LINES_PER_MIB * LINE_BYTES), stats: meter.stats(bytes, calls) })
    })());
    t.measured("H2", &format!("BLOB {HUGE} MiB written in 1 MiB chunks: (bytes, all written)"), &format!("({}, true)", HUGE * MIB), (|| {
        let (_, mut bl) = both(&c, 6, true)?;
        let meter = Meter::start();
        let mut gen_ = BinGen::mib(HUGE);
        let mut buf = vec![0u8; MIB as usize];
        let (mut bytes, mut calls) = (0u64, 0u64);
        loop {
            let n = gen_.read(&mut buf)?;
            if n == 0 {
                break;
            }
            bl.write_all(&buf[..n])?;
            bytes += n as u64;
            calls += 1;
        }
        Ok(Measured { outcome: (bl.get_size()? as u64, bytes == HUGE * MIB), stats: meter.stats(bytes, calls) })
    })());
    t.check("H3", "server-side: row 6 vs row 5 (CLOB length, compare, BLOB length, compare)", &format!("({0}, 0, {0}, 0)", HUGE * MIB), compare_rows(&c, 6, 5));
    t.measured("H4", &format!("CLOB {HUGE} MiB read back streamed, 1 MiB buffer: (UTF-8 bytes, every byte matches)"), &format!("({}, true)", HUGE * LINES_PER_MIB * LINE_BYTES), (|| {
        let (mut tl, _) = both(&c, 6, false)?;
        verify_stream(&mut tl, &mut TextGen::mib(HUGE), 1 << 20)
    })());
    t.measured("H5", &format!("BLOB {HUGE} MiB read back streamed, 1 MiB buffer"), &format!("({}, true)", HUGE * MIB), (|| {
        let (_, mut bl) = both(&c, 6, false)?;
        verify_stream(&mut bl, &mut BinGen::mib(HUGE), 1 << 20)
    })());

    println!("== Write paths and binds ==");
    t.observe("W1", "CLOB write() of half a multibyte character", (|| {
        let mut l = c.create_lob(DB_TYPE_CLOB)?;
        Ok(l.write(&"ç".as_bytes()[..1])?)
    })());
    t.observe("W2", "io::copy of 16 KiB of '日本…' into a CLOB (8 KiB copy buffer splits a character)", (|| {
        let mut l = c.create_lob(DB_TYPE_CLOB)?;
        let text = "日本".repeat(16384 / 6);
        io::copy(&mut io::Cursor::new(text.as_bytes()), &mut l)?;
        Ok(l.get_size()?)
    })());
    t.check("W3", "same io::copy through a Utf8Boundary writer: (size, read back equal)", &format!("({}, true)", 2 * (16384 / 6)), (|| {
        let mut l = c.create_lob(DB_TYPE_CLOB)?;
        let text = "日本".repeat(16384 / 6);
        {
            let mut w = Utf8Boundary { inner: &mut l, tail: Vec::new() };
            io::copy(&mut io::Cursor::new(text.as_bytes()), &mut w)?;
            w.flush()?;
        }
        l.seek(SeekFrom::Start(1))?;
        let mut s = String::new();
        l.read_to_string(&mut s)?;
        Ok((l.get_size()?, s == text))
    })());
    t.check("W4", "temporary CLOB/BLOB: write 16 MiB each, sizes", &format!("({0}, {0})", 16 * MIB), (|| {
        let mut tl = c.create_lob(DB_TYPE_CLOB)?;
        let mut gen_ = TextGen::mib(16);
        while let Some(s) = gen_.whole_lines(1 << 20) {
            tl.write_all(s.as_bytes())?;
        }
        let mut bl = c.create_lob(DB_TYPE_BLOB)?;
        io::copy(&mut BinGen::mib(16), &mut bl)?;
        Ok((tl.get_size()?, bl.get_size()?))
    })());
    t.check("W5", "insert with a 16 MiB String and a 16 MiB Vec<u8> bound inline, then row 7 vs row 4 on the server", &format!("({0}, 0, {0}, 0)", 16 * MIB), (|| {
        let mut text = Vec::new();
        TextGen::mib(16).read_to_end(&mut text)?;
        let text = String::from_utf8(text)?;
        let mut bin = Vec::new();
        BinGen::mib(16).read_to_end(&mut bin)?;
        c.execute(
            "insert into fx_lobs (id, label, text_value, binary_value) values (7, 'probe', :1, :2)",
            &[&text, &bin],
        )?;
        compare_rows(&c, 7, 4)
    })());
    t.observe("W6", "PL/SQL CLOB IN parameter with a String: lengths for 32767, 32768, 1 MiB and 16 MiB characters", (|| {
        let mut out = Vec::new();
        for n in [32767usize, 32768, 1 << 20, 16 << 20] {
            let s = "a".repeat(n);
            let r = c
                .execute_named("begin :n := fx_lob.text_length(:t); end;", &[("n", &DB_TYPE_NUMBER), ("t", &s)])
                .and_then(|mut r| r.out_bind_data().get::<i64>("n"));
            out.push(match r {
                Ok(v) => format!("{n}: {v}"),
                Err(e) => format!("{n}: Err({})", one_line(&e.to_string())),
            });
        }
        Ok(out)
    })());
    t.observe("W7", "PL/SQL BLOB IN parameter with a Vec<u8>: lengths for 32767, 32768 and 16 MiB bytes", (|| {
        let mut out = Vec::new();
        for n in [32767usize, 32768, 16 << 20] {
            let b = vec![7u8; n];
            let r = c
                .execute_named("begin :n := fx_lob.binary_length(:b); end;", &[("n", &DB_TYPE_NUMBER), ("b", &b)])
                .and_then(|mut r| r.out_bind_data().get::<i64>("n"));
            out.push(match r {
                Ok(v) => format!("{n}: {v}"),
                Err(e) => format!("{n}: Err({})", one_line(&e.to_string())),
            });
        }
        Ok(out)
    })());
    t.observe("W8", "OUT CLOB bind (DB_TYPE_CLOB) of the 16 MiB row 4: taken as Lob, its size", (|| {
        let mut r = c.execute_named(
            "begin select text_value into :o from fx_lobs where id = 4; end;",
            &[("o", &DB_TYPE_CLOB)],
        )?;
        let mut l: Lob = r.out_bind_data().take("o")?;
        Ok(l.get_size()?)
    })());
    t.observe("W9", "OUT CLOB bind of a PL/SQL temporary LOB: taken as Lob, read_to_string", (|| {
        let mut r = c.execute_named(
            "declare l clob; begin dbms_lob.createtemporary(l, true); dbms_lob.writeappend(l, 5, 'hello'); :o := l; end;",
            &[("o", &DB_TYPE_CLOB)],
        )?;
        let mut l: Lob = r.out_bind_data().take("o")?;
        let mut s = String::new();
        l.read_to_string(&mut s)?;
        Ok(s)
    })());
    t.check("W10", "workaround for a large LOB IN to PL/SQL: stream into a row, the block reads it from there", &(HUGE * MIB).to_string(), (|| {
        let mut r = c.execute_named(
            "declare l clob; begin select text_value into l from fx_lobs where id = 6; :n := fx_lob.text_length(l); end;",
            &[("n", &DB_TYPE_NUMBER)],
        )?;
        Ok(r.out_bind_data().get::<i64>("n")?)
    })());

    for (id, mib) in [("W11", 1u64), ("W12", 16)] {
        t.measured(id, &format!("schema-free workaround: {mib} MiB CLOB to PL/SQL as VARCHAR2 chunk binds appended in one Anonymous block: (length, ok)"), &format!("({}, true)", mib * MIB), (|| {
            let mut text = Vec::new();
            TextGen::mib(mib).read_to_end(&mut text)?;
            let text = String::from_utf8(text)?;
            let meter = Meter::start();
            // Split into <= 32767-byte pieces on character boundaries.
            let mut chunks: Vec<&str> = Vec::new();
            let mut rest = text.as_str();
            while !rest.is_empty() {
                let mut cut = rest.len().min(32767);
                while !rest.is_char_boundary(cut) {
                    cut -= 1;
                }
                chunks.push(&rest[..cut]);
                rest = &rest[cut..];
            }
            let mut plsql = String::from("declare l clob; begin dbms_lob.createtemporary(l, true);");
            for i in 0..chunks.len() {
                plsql.push_str(&format!(" dbms_lob.writeappend(l, length(:c{i}), :c{i});"));
            }
            plsql.push_str(" :n := fx_lob.text_length(l); end;");
            let names: Vec<String> = (0..chunks.len()).map(|i| format!("c{i}")).collect();
            let mut binds: Vec<(&str, &dyn oracledb::ToDbValue)> = vec![("n", &DB_TYPE_NUMBER)];
            for (name, chunk) in names.iter().zip(chunks.iter()) {
                binds.push((name.as_str(), chunk));
            }
            let mut r = c.execute_named(&plsql, &binds)?;
            let n: i64 = r.out_bind_data().get("n")?;
            Ok(Measured { outcome: (n as u64, true), stats: format!("{} binds, {}", chunks.len(), meter.stats(text.len() as u64, 1)) })
        })());
    }

    println!("== Lifecycle ==");
    t.observe("D0", "temporary LOBs held by this session now", temp_lob_count(&c));
    t.observe("D1", "200 temporary CLOBs created and dropped, then the count", (|| {
        for _ in 0..200 {
            let mut l = c.create_lob(DB_TYPE_CLOB)?;
            l.write_all(b"x")?;
        }
        temp_lob_count(&c)
    })());
    t.check("D2", "1000 locators of row 4 fetched and dropped unread, then a read of row 1", &format!("{:?}", Some(SMALL)), (|| {
        for _ in 0..1000 {
            let _ = locators(&c, 4, false)?;
        }
        Ok(inline(&c, 1)?.0)
    })());

    println!("== Inline fetch of large values (whole value in memory) ==");
    t.measured("I4", "row 4 (16 MiB) inline: (CLOB bytes + BLOB bytes, both match)", &format!("({}, true)", BIG_CLOB_BYTES + 16 * MIB), (|| {
        let meter = Meter::start();
        let (s, b) = inline(&c, 4)?;
        let (s, b) = (s.ok_or("NULL")?, b.ok_or("NULL")?);
        let ok = equals_stream(s.as_bytes(), &mut TextGen::mib(16)) && equals_stream(&b, &mut BinGen::mib(16));
        let bytes = (s.len() + b.len()) as u64;
        Ok(Measured { outcome: (bytes, ok), stats: meter.stats(bytes, 1) })
    })());
    t.measured("I5", &format!("row 6 ({HUGE} MiB) inline"), &format!("({}, true)", HUGE * LINES_PER_MIB * LINE_BYTES + HUGE * MIB), (|| {
        let meter = Meter::start();
        let (s, b) = inline(&c, 6)?;
        let (s, b) = (s.ok_or("NULL")?, b.ok_or("NULL")?);
        let ok = equals_stream(s.as_bytes(), &mut TextGen::mib(HUGE)) && equals_stream(&b, &mut BinGen::mib(HUGE));
        let bytes = (s.len() + b.len()) as u64;
        Ok(Measured { outcome: (bytes, ok), stats: meter.stats(bytes, 1) })
    })());

    c.rollback()?;
    println!(
        "\n{} pass, {} fail, {} err, {} observed",
        t.pass, t.fail, t.err, t.obs
    );
    Ok(())
}
