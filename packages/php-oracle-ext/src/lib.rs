//! `rajska_oracle`: the PHP extension that hosts the Rust thin Backend.
//!
//! The module version PHP reports (`phpversion('rajska_oracle')`) is this
//! crate's Cargo version, the contract version Core checks.

// PHP on Windows uses the vectorcall ABI, still unstable in Rust (C18).
#![cfg_attr(windows, feature(abi_vectorcall))]

use ext_php_rs::prelude::*;
// Linked from day one so CI builds the fork on every target; the Backend
// code that calls it comes with the Driver contract.
use oracledb as _;

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
}
