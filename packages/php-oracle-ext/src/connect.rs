//! The connect parameters of `$pool` (spec #32 §11, "Connect parameters"),
//! checked before any network I/O and turned into the crate's `Config` /
//! `PoolConfig` settings.
//!
//! - The `dsn` is an Easy Connect string, a descriptor or a TNS alias.
//!   Whatever the crate would silently ignore in it is rejected, saying what
//!   to do instead (usually the `ConnectionConfig` field to use): Easy
//!   Connect Plus `?params`, and the descriptor keys of [`REJECTED_KEYS`], in
//!   a descriptor or in the entry a TNS alias names.
//! - The wallet is `ewallet.pem` only; `cwallet.sso` / `ewallet.p12` fail
//!   with conversion instructions.
//! - The user is `user[proxyUser]` with a proxy client.
//! - The DRCP connection class, unless given, is derived from the user, so
//!   every process of an application shares one.

use std::path::{Path, PathBuf};
use std::time::Duration;

use crate::error::{Error, Kind};

/// What to do instead of a setting the crate ignores, by lowercase name:
/// the descriptor keys of [`REJECTED_KEYS`] and the Easy Connect Plus
/// parameters that have a `ConnectionConfig` field.
const INSTEAD: &[(&str, &str)] = &[
    (
        "wallet_location",
        "use ConnectionConfig's walletLocation instead",
    ),
    (
        "ssl_server_dn_match",
        "remove it: the server certificate is always checked against the host name, and its DN never is",
    ),
    (
        "ssl_server_cert_dn",
        "remove it: the server certificate is always checked against the host name, and its DN never is",
    ),
    ("pool_purity", "remove it: there is no purity setting"),
    (
        "transport_connect_timeout",
        "use ConnectionConfig's connectTimeout instead",
    ),
    (
        "connect_timeout",
        "use ConnectionConfig's connectTimeout instead",
    ),
    (
        "pool_connection_class",
        "use ConnectionConfig's drcpConnectionClass instead",
    ),
];

/// The descriptor keys rejected in a descriptor or a TNS alias's entry.
const REJECTED_KEYS: &[&str] = &[
    "wallet_location",
    "ssl_server_dn_match",
    "ssl_server_cert_dn",
    "pool_purity",
    "transport_connect_timeout",
];

fn instead(name: &str) -> &'static str {
    INSTEAD.iter().find(|(ignored, _)| *ignored == name).map_or(
        "use the ConnectionConfig fields or a (DESCRIPTION=...) descriptor instead",
        |(_, instead)| *instead,
    )
}

/// The prefix of the DRCP connection class derived from the user.
const CONNECTION_CLASS_PREFIX: &str = "RAJSKA:";

fn configuration(message: impl Into<String>) -> Error {
    Error::new(Kind::Configuration, message)
}

/// Where tnsnames.ora is: `configDir`, else `$TNS_ADMIN` (read at every
/// connect), else the crate's default (`$ORACLE_HOME/network/admin`).
pub fn config_dir(config_dir: Option<&str>) -> Option<String> {
    config_dir.map(str::to_string).or_else(|| {
        std::env::var("TNS_ADMIN")
            .ok()
            .filter(|dir| !dir.is_empty())
    })
}

/// Fails if the `dsn` asks for something the crate would ignore.
pub fn check_dsn(dsn: &str, config_dir: Option<&str>) -> Result<(), Error> {
    let dsn = dsn.trim();
    if dsn.starts_with('(') {
        return check_descriptor(dsn, "The dsn");
    }
    if let Some((_, parameters)) = dsn.split_once('?') {
        return Err(easy_connect_plus(parameters));
    }
    if !dsn.contains('/') {
        // A TNS alias: its entry, when it can be found here. When it
        // cannot, the crate's lookup reports why.
        let dir = config_dir.map(PathBuf::from).or_else(|| {
            std::env::var("ORACLE_HOME")
                .ok()
                .map(|home| Path::new(&home).join("network/admin"))
        });
        if let Some(dir) = dir {
            let file = dir.join("tnsnames.ora");
            if let Some(entry) = tns_entry(&dir, &file, dsn) {
                let what = format!("The TNS alias '{dsn}' (in {})", file.display());
                let entry = entry.trim();
                if entry.starts_with('(') {
                    return check_descriptor(entry, &what);
                }
                if let Some((_, parameters)) = entry.split_once('?') {
                    return Err(easy_connect_plus(parameters));
                }
            }
        }
    }
    Ok(())
}

/// The error for Easy Connect Plus `?params`, saying what to do instead of
/// each.
fn easy_connect_plus(parameters: &str) -> Error {
    let names: Vec<String> = parameters
        .split('&')
        .filter_map(|pair| pair.split('=').next())
        .map(|name| name.trim().to_lowercase())
        .filter(|name| !name.is_empty())
        .collect();
    let steps: Vec<String> = names
        .iter()
        .map(|name| format!("for {name}, {}", instead(name)))
        .collect();
    configuration(format!(
        "The dsn has Easy Connect Plus parameters ({}), which rajska_oracle does not support: {}.",
        names.join(", "),
        steps.join("; ")
    ))
}

/// Fails at the first key of [`REJECTED_KEYS`] the descriptor sets.
fn check_descriptor(descriptor: &str, what: &str) -> Result<(), Error> {
    let mut rest = descriptor;
    while let Some(start) = rest.find('(') {
        rest = &rest[start + 1..];
        let trimmed = rest.trim_start();
        let end = trimmed
            .find(|c: char| !(c.is_ascii_alphanumeric() || c == '_' || c == '.'))
            .unwrap_or(trimmed.len());
        let key = trimmed[..end].to_lowercase();
        if trimmed[end..].trim_start().starts_with('=') && REJECTED_KEYS.contains(&key.as_str()) {
            return Err(configuration(format!(
                "{what} sets {}, which rajska_oracle would ignore: {}.",
                key.to_uppercase(),
                instead(&key)
            )));
        }
    }
    Ok(())
}

/// The text of `alias` in a tnsnames.ora file (following IFILEs), or
/// `None` if the file or the alias cannot be found.
fn tns_entry(dir: &Path, file: &Path, alias: &str) -> Option<String> {
    entries(dir, file, 0)
        .into_iter()
        .find(|(names, _)| names.iter().any(|name| name.eq_ignore_ascii_case(alias)))
        .map(|(_, value)| value)
}

/// The entries of a tnsnames.ora file: `name[, name…] = value`, where a
/// value in parentheses runs to its closing one and any other value to the
/// end of its line. `#` starts a comment.
fn entries(dir: &Path, file: &Path, depth: usize) -> Vec<(Vec<String>, String)> {
    let Ok(contents) = std::fs::read_to_string(file) else {
        return Vec::new();
    };
    let text: String = contents
        .lines()
        .map(|line| line.split('#').next().unwrap_or(""))
        .collect::<Vec<_>>()
        .join("\n");
    let mut found = Vec::new();
    let mut rest = text.as_str();
    while let Some(equals) = rest.find('=') {
        let names: Vec<String> = rest[..equals]
            .split(',')
            .map(|name| name.trim().to_string())
            .filter(|name| !name.is_empty())
            .collect();
        let after = rest[equals + 1..].trim_start();
        let end = if after.starts_with('(') {
            let mut depth = 0usize;
            after
                .char_indices()
                .find(|(_, c)| {
                    match c {
                        '(' => depth += 1,
                        ')' => depth = depth.saturating_sub(1),
                        _ => {}
                    }
                    depth == 0
                })
                .map_or(after.len(), |(i, _)| i + 1)
        } else {
            after.find('\n').unwrap_or(after.len())
        };
        let value = after[..end].trim().to_string();
        rest = &after[end..];
        if names.len() == 1 && names[0].eq_ignore_ascii_case("ifile") {
            if depth < 8 {
                found.extend(entries(dir, &dir.join(&value), depth + 1));
            }
        } else {
            found.push((names, value));
        }
    }
    found
}

/// Fails unless the wallet directory holds `ewallet.pem`, with conversion
/// instructions for the formats the crate cannot read.
pub fn check_wallet(location: &str) -> Result<(), Error> {
    let path = Path::new(location);
    if path.is_file() {
        let name = path
            .file_name()
            .map(|n| n.to_string_lossy().to_lowercase())
            .unwrap_or_default();
        if name.ends_with(".sso") || name.ends_with(".p12") {
            return Err(conversion(path.parent().unwrap_or(path)));
        }
        return Err(configuration(format!(
            "walletLocation '{location}' is a file; it must be the directory that holds ewallet.pem."
        )));
    }
    if path.join("ewallet.pem").is_file() {
        return Ok(());
    }
    if path.join("cwallet.sso").is_file() || path.join("ewallet.p12").is_file() {
        return Err(conversion(path));
    }
    Err(configuration(format!(
        "No ewallet.pem in walletLocation '{location}'."
    )))
}

fn conversion(dir: &Path) -> Error {
    let dir = dir.display();
    configuration(format!(
        "The wallet in '{dir}' has no ewallet.pem, only cwallet.sso / ewallet.p12, which rajska_oracle cannot read. \
         Convert it to PEM once: openssl pkcs12 -in {dir}/ewallet.p12 -out {dir}/ewallet.pem \
         (add -legacy if OpenSSL 3 refuses the file; add -nodes to leave the private key unencrypted, \
         otherwise set walletPassword to the pass phrase you choose). A cwallet.sso has no PEM form of its own: \
         convert the ewallet.p12 that came with it. Autonomous Database wallet zips already contain ewallet.pem."
    ))
}

/// The user the crate logs on as: `user[proxyUser]` with a proxy client.
pub fn user(user: &str, proxy_user: Option<&str>) -> String {
    match proxy_user {
        Some(proxy) => format!("{user}[{proxy}]"),
        None => user.to_string(),
    }
}

/// The DRCP connection class: the one given, else one derived from the
/// user, the same in every process.
pub fn connection_class(class: Option<&str>, user: &str) -> String {
    class.map_or_else(
        || format!("{CONNECTION_CLASS_PREFIX}{user}"),
        str::to_string,
    )
}

/// The transport connect timeout, from milliseconds.
pub fn connect_timeout(milliseconds: i64) -> Result<Duration, Error> {
    u64::try_from(milliseconds)
        .ok()
        .filter(|ms| *ms >= 1)
        .map(Duration::from_millis)
        .ok_or_else(|| configuration("'connectTimeout' must be at least 1 ms."))
}
