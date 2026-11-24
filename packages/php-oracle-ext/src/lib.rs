//! `rajska_oracle`: the PHP extension that hosts the Rust thin Backend.
//!
//! The module version PHP reports (`phpversion('rajska_oracle')`) is this
//! crate's Cargo version, the contract version Core checks.
//!
//! Its native surface (spec #32 §14) lives in `Rajska\Oracle\Extension\`;
//! Core reaches it only through its Extension adapter.

// PHP on Windows uses the vectorcall ABI, still unstable in Rust (C18).
#![cfg_attr(windows, feature(abi_vectorcall))]

use ext_php_rs::prelude::*;

mod bind;
mod connection;
mod cursor;
mod error;
mod input;
mod lob;
mod object;
mod options;
mod pool;
mod session;
mod temp_lob;
mod temporal;
mod typed;
mod value;

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<error::NativeException>()
        .class::<connection::NativeConnection>()
        .class::<cursor::NativeCursor>()
        .class::<lob::NativeLob>()
        .shutdown_function(shutdown)
}

/// MSHUTDOWN: closes every Pool of this process, joining its manager thread,
/// before PHP unloads the library.
extern "C" fn shutdown(_type: i32, _module_number: i32) -> i32 {
    // A panic must not cross into PHP; the Pools are then leaked.
    let _ = std::panic::catch_unwind(pool::shutdown);
    0
}
