//! `oracledb`: the PHP extension that hosts the Rust thin Backend.
//!
//! The module version PHP reports (`phpversion('oracledb')`) is this
//! crate's Cargo version, the contract version Core checks.
//!
//! Its native surface (spec #32 §14) lives in `Rajska\Oracle\Extension\`;
//! Core reaches it only through its Extension adapter.

// PHP on Windows uses the vectorcall ABI, still unstable in Rust (C18).
#![cfg_attr(windows, feature(abi_vectorcall))]

use ext_php_rs::prelude::*;
use ext_php_rs::zend::ModuleEntry;
use ext_php_rs::{info_table_end, info_table_row, info_table_start};

mod array_dml;
mod bind;
mod connect;
mod connection;
mod cursor;
mod error;
mod input;
mod json;
mod lob;
mod object;
mod options;
mod pool;
mod session;
mod temp_lob;
mod temporal;
mod type_name;
mod typed;
mod value;
mod vector;

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        // The crate is php-oracledb (the driver crate is oracledb); PHP sees oracledb.
        .name("oracledb")
        .class::<error::NativeException>()
        .class::<connection::NativeConnection>()
        .class::<cursor::NativeCursor>()
        .class::<lob::NativeLob>()
        .shutdown_function(shutdown)
        .info_function(info)
}

/// MINFO, for `php --ri oracledb` and `phpinfo()`. A custom MINFO
/// replaces PHP's default table, so it prints the Version row itself: the
/// Cargo version, as `phpversion('oracledb')` reports it.
extern "C" fn info(_module: *mut ModuleEntry) {
    info_table_start!();
    info_table_row!("Version", env!("CARGO_PKG_VERSION"));
    info_table_end!();
}

/// MSHUTDOWN: closes every Pool of this process, joining its manager thread,
/// before PHP unloads the library.
extern "C" fn shutdown(_type: i32, _module_number: i32) -> i32 {
    // A panic must not cross into PHP; the Pools are then leaked.
    let _ = std::panic::catch_unwind(pool::shutdown);
    0
}
