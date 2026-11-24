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
mod options;

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<error::NativeException>()
        .class::<connection::NativeConnection>()
        .class::<cursor::NativeCursor>()
}
