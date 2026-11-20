//! Throwaway build probe for the PIE-targets ticket (#12) of the rust-oracledb spike.
//!
//! A minimal `rajska_oracle` extension: `ext-php-rs` 0.16 plus `oracledb`.
//! It exists to prove that the pair builds and loads on every PIE target, so
//! the functions only make sure `oracledb` (and its TLS stack, `rustls` on
//! `aws-lc-rs`) is linked in and runs:
//!
//!   rajska_probe_info()                    build facts as one line
//!   rajska_probe_query($dsn, $u, $p, $sql) first column of the first row, or "ERR: …"
//!
//! Errors and panics never cross into PHP as a crash: both come back as "ERR: …".

// PHP on Windows uses the vectorcall ABI, still unstable in Rust: nightly or RUSTC_BOOTSTRAP=1.
#![cfg_attr(windows, feature(abi_vectorcall))]

use ext_php_rs::prelude::*;

#[php_function]
pub fn rajska_probe_info() -> String {
    format!(
        "rajska_oracle probe: target={}-{} zts={} debug={}",
        std::env::consts::ARCH,
        std::env::consts::OS,
        ext_php_rs::PHP_ZTS,
        ext_php_rs::PHP_DEBUG,
    )
}

#[php_function]
pub fn rajska_probe_query(dsn: String, user: String, password: String, sql: String) -> String {
    let run = || -> Result<String, oracledb::Error> {
        let config = oracledb::Config::default()
            .set_credentials(&user, &password)
            .set_connect_string(&dsn)?;
        let conn = oracledb::connect(config)?;
        let row = conn.query_row(&sql, &[])?;
        let value: String = row.get(0)?;
        Ok(value)
    };
    match std::panic::catch_unwind(std::panic::AssertUnwindSafe(run)) {
        Ok(Ok(v)) => v,
        Ok(Err(e)) => format!("ERR: {}", chain(&e)),
        Err(_) => "ERR: panic".to_string(),
    }
}

/// The error and every `source()` under it, on one line.
fn chain(e: &dyn std::error::Error) -> String {
    let mut out = e.to_string();
    let mut cur = e.source();
    while let Some(s) = cur {
        out.push_str(" <- ");
        out.push_str(&s.to_string());
        cur = s.source();
    }
    out.replace('\n', " ")
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(rajska_probe_info))
        .function(wrap_function!(rajska_probe_query))
}
