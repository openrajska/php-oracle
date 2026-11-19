# Can `rajska_oracle` ship as PIE pre-packaged binaries across the platform matrix?

Research for issue #5 (child of map #1). Researched 2026-11-19 against PIE
1.5.5 (latest stable; 1.6.0 is in RC) and ext-php-rs 0.16.5.

## Answer

**Yes, with no hard blocker.** Every target in the matrix (Linux glibc and musl
on x86_64 and arm64, macOS arm64 and x86_64, Windows x86_64, NTS and ZTS, PHP
8.4) can be published as a PIE binary. Each one builds natively on a
GitHub-hosted runner, so no cross-compilation is needed. Two other Rust
extensions built on ext-php-rs 0.16 already ship this way: `flow-php/arrow-ext`
(Linux glibc and macOS arm64, NTS and ZTS) and `xberg-io/html-to-markdown`
(Linux, macOS and Windows x86_64, NTS).

A package can rule out compiling from source. If `download-url-method` lists
only `pre-packaged-binary`, PIE never falls back to a source build. When no
asset matches, it fails with an error. On Windows PIE never builds from
source at all.

The main risks are operational, not feasibility problems:

- Windows builds need nightly Rust, or `RUSTC_BOOTSTRAP=1`, for the
  `vectorcall` ABI.
- PIE finds assets through the GitHub API. When PIE runs unauthenticated, the
  rate limit becomes a hard failure for a binary-only package.
- Packagist needs a split repository for the extension package.
- The minimum glibc version has to be pinned on purpose.

## Feasibility table (PHP 8.4)

`{v}` is the Composer "pretty" version, which is the git tag exactly as
written. A tag `v0.1.0` gives `v0.1.0` in the asset name.

| Target | TS | Feasible | Build on | Asset name PIE looks for |
|---|---|---|---|---|
| Linux glibc x86_64 | NTS | Yes | `ubuntu-24.04` + `php:8.4-cli-bookworm` container | `php_rajska_oracle-{v}_php8.4-x86_64-linux-glibc.zip` (or `…-glibc-nts.zip`) |
| Linux glibc x86_64 | ZTS | Yes | `ubuntu-24.04` + `php:8.4-zts-bookworm` | `php_rajska_oracle-{v}_php8.4-x86_64-linux-glibc-zts.zip` |
| Linux glibc arm64 | NTS | Yes | `ubuntu-24.04-arm` + `php:8.4-cli-bookworm` | `php_rajska_oracle-{v}_php8.4-arm64-linux-glibc.zip` |
| Linux glibc arm64 | ZTS | Yes | `ubuntu-24.04-arm` + `php:8.4-zts-bookworm` | `php_rajska_oracle-{v}_php8.4-arm64-linux-glibc-zts.zip` |
| Linux musl x86_64 | NTS | Yes (1) | `ubuntu-24.04` + `php:8.4-cli-alpine` | `php_rajska_oracle-{v}_php8.4-x86_64-linux-musl.zip` |
| Linux musl x86_64 | ZTS | Yes (1) | `ubuntu-24.04` + `php:8.4-zts-alpine` | `php_rajska_oracle-{v}_php8.4-x86_64-linux-musl-zts.zip` |
| Linux musl arm64 | NTS | Yes (1)(2) | `ubuntu-24.04-arm` + `php:8.4-cli-alpine` | `php_rajska_oracle-{v}_php8.4-arm64-linux-musl.zip` |
| Linux musl arm64 | ZTS | Yes (1)(2) | `ubuntu-24.04-arm` + `php:8.4-zts-alpine` | `php_rajska_oracle-{v}_php8.4-arm64-linux-musl-zts.zip` |
| macOS arm64 | NTS | Yes | `macos-15` / `macos-26` + `shivammathur/setup-php` | `php_rajska_oracle-{v}_php8.4-arm64-darwin-bsdlibc.zip` |
| macOS arm64 | ZTS | Yes | same, `phpts: zts` | `php_rajska_oracle-{v}_php8.4-arm64-darwin-bsdlibc-zts.zip` |
| macOS x86_64 | NTS/ZTS | Yes, but low value (3) | `macos-15-intel` / `macos-26-intel` | `php_rajska_oracle-{v}_php8.4-x86_64-darwin-bsdlibc[-zts].zip` |
| Windows x86_64 | NTS | Yes, with caveats (4) | `windows-2025` | `php_rajska_oracle-{v}-8.4-nts-vs17-x86_64.zip` |
| Windows x86_64 | TS | Yes, with caveats (4) | `windows-2025` | `php_rajska_oracle-{v}-8.4-ts-vs17-x86_64.zip` |

That is 12 assets for the core matrix, or 14 with macOS x86_64.

Notes on the table:

1. The extension must link musl dynamically, with
   `-C target-feature=-crt-static`. ext-php-rs documents this and uses it in
   its own musl CI ([README, "Alpine Linux (musl)"][ephprs-readme];
   [musl Dockerfile][ephprs-musl-docker]).
2. ext-php-rs's own musl CI builds only `linux/amd64`
   ([build.yml `build-musl`][ephprs-build-yml]). arm64 musl should work the
   same way on an arm64 runner, but no upstream CI covers it. Validate it in
   the spike.
3. GitHub-hosted Intel macOS runners still exist (`macos-15-intel`,
   `macos-26-intel`) ([runner-images README][runner-images]). Two signals say
   the platform is on its way out. Homebrew moved Intel macOS to Tier 3 ("CI
   coverage is unavailable; bottles will rarely be built or published") and
   will stop running on Intel in or after September 2027
   ([Homebrew Support Tiers][brew-tiers]). Apple says macOS Tahoe 26 is the
   last macOS for Intel hardware (same page). Real Rust PIE extensions skip
   this target: arrow-ext, lindera and html-to-markdown ship macOS arm64 only.
   Recommendation: leave it out of v1. Users then get a clear "no binary"
   error.
4. Windows: see [Windows](#windows-x86_64).

## How PIE resolves and installs a pre-packaged binary

All of this comes from PIE's source on `main` and from its
[extension maintainer docs][pie-maint].

- **Opt-in.** Set `php-ext.download-url-method` in `composer.json`. The value
  is a list of `composer-default`, `pre-packaged-source` and
  `pre-packaged-binary`. Support for `pre-packaged-binary` arrived in PIE
  1.4.0 ([1.4.0 release notes][pie-140]), so users need PIE ≥ 1.4.
- **Lookup.** PIE calls the GitHub API
  `GET /repos/{org}/{repo}/releases/tags/{version}` and compares the asset
  names to a candidate list without regard to case
  ([`GithubPackageReleaseAssets`][pie-gh-assets]). `{org}/{repo}` comes from
  the package's dist URL on Packagist ([`Package::githubOrgAndRepository`][pie-package]).
  Only GitHub is supported.
- **Candidate names (Linux, macOS, BSD)**
  ([`PrePackagedBinaryAssetName`][pie-asset-name]):
  `php_{ext}-{version}_php{maj.min}-{arch}-{os}-{libc}{-debug}{-zts}.{zip|tgz}`,
  plus the same name with an explicit `-nts`.
  - `{arch}`: `x86_64`, `arm64` or `x86`.
  - `{os}`: `linux`, `darwin`, `bsd`, `solaris`, `windows` or `unknown`.
  - `{libc}`: `glibc`, `musl` or `bsdlibc`. PIE detects it at runtime
    ([`LibcFlavour::detect`][pie-libc]). If `otool` exists it picks
    `bsdlibc`, so macOS is always `darwin-bsdlibc`. Otherwise it picks `musl`
    when `ldd ls` mentions musl, and `glibc` in every other case.
  - Debug PHP builds get `-debug`. Non-debug builds get **no** suffix. The
    docs mention a `nodebug` token, but the code never generates it. An asset
    named `…-nodebug…` would never match.
  - On Linux, PIE also accepts `…-linux-anylibc…` for binaries that run on
    both libcs. A Rust `cdylib` that PHP loads must link against the host
    libc, so this option does not help us.
- **Archive contents (Unix).** PIE expects `{ext}.so` at the root of the
  extracted archive. For us that is `rajska_oracle.so`
  ([`UnixBuild::prePackagedBinary`][pie-unixbuild]). Cargo produces
  `librajska_oracle.so` (Linux) or `librajska_oracle.dylib` (macOS), so CI
  must rename the file to `rajska_oracle.so` on both. arrow-ext does exactly
  this ([arrow-ext release.yml][arrow-release]).
- **Windows is a separate path.** On Windows PIE ignores `download-url-method`
  and always uses `windows-binary`
  ([`DownloadUrlMethod::possibleDownloadUrlMethodsForPackage`][pie-dlm]). It
  looks for `php_{ext}-{version}-{maj.min}-{ts|nts}-{compiler}-{arch}.zip`
  (the order of compiler and ts may be swapped). The zip must contain a DLL
  with the same base name ([`WindowsExtensionAssetName`][pie-win-name];
  [docs, "Windows Support"][pie-maint]). PIE reads `{compiler}` from the
  target PHP's "PHP Extension Build" string (`vs17` for PHP 8.4).
- **Integrity.** After switching to a release-asset URL, PIE clears
  Composer's dist checksum and prints a warning: "HTTPS to the release-asset
  origin is the only integrity guarantee"
  ([`OverrideDownloadUrlInstallListener`][pie-listener]). PIE checks
  attestations only for itself, not for extensions.

### What happens when no binary matches, and how to forbid source builds

PIE tries the listed methods in order
([`OverrideDownloadUrlInstallListener`][pie-listener]):

- With `["pre-packaged-binary", "composer-default"]`, a missing asset makes
  PIE silently download the source and run `phpize && ./configure && make`.
  The PIE docs recommend this setup ([docs][pie-maint]).
- With `["pre-packaged-binary"]` **only**, a missing asset makes PIE throw
  `CouldNotDetermineDownloadUrlMethod`, which lists why each method failed.
  **No compilation is attempted.** This is how a package forbids source
  builds. `xberg-io/html-to-markdown` ships this way
  ([composer.json][h2m-composer]).
- If the user passes any configure option, PIE skips `pre-packaged-binary`
  ([listener][pie-listener]; [usage docs][pie-usage]). We must therefore
  declare **no** `configure-options`.
- `pie build` leaves out `pre-packaged-binary` by default (since 1.6.0,
  [PR 774][pie-160]). For a package that lists only binaries, `pie build`
  fails ([docs][pie-maint]). That is acceptable: contributors build with
  `cargo`.
- Since PIE 1.5.0, end users can also run
  `--suppress-download-url-method=composer-default` themselves
  ([PR 705][pie-150]). That option exists for users; it is not a package
  setting.
- `support-nts` and `support-zts` filter during dependency resolution, and so
  does `os-families`. There is no metadata to restrict architecture or libc.
  Those gaps only show up at download time, as the "no binary" error.

The rate limit makes the binary-only choice sharper. PIE issue
[php/pie#701][pie-701] reports that a GitHub API rate-limit response (HTTP
403, 60 requests/hour unauthenticated) made PIE skip the binary and compile
from source. With a binary-only package, the same situation becomes a hard
error. PIE goes through Composer's HTTP layer, and PIE's own CI authenticates
with `composer config --global --auth github-oauth.github.com $GITHUB_TOKEN`
([PIE CI][pie-ci]). Our install docs should tell CI and Docker users to do the
same.

Another papercut: `pie install` runs a build-tools check (`cc`, `make`,
`autoconf`, `pkg-config`, `libtool`, `unzip`) **before** it resolves the
package, even for a binary-only package. In interactive mode it asks to
install any missing tools ([`CheckAllBuildTools`][pie-buildtools];
[`InstallCommand`][pie-install-cmd]). The check does not abort the install.
Answering "N", or passing `--no-build-tools-check`, carries on. `unzip`
genuinely matters, because without it Composer may fall back to a git clone
(comment in the same file). Our install docs should say
`pie install --no-build-tools-check openrajska/php-oracle-ext`, or tell users
to install `unzip`.

## Toolchain and CI per target

ext-php-rs's build script has two properties that decide the CI design:

- It **runs the target `php` and `php-config` binaries** to read the version,
  ZTS and debug flags and the include paths ([build.rs][ephprs-build-rs];
  [unix_build.rs][ephprs-unix-build]).
- It chooses the Windows or Unix provider with `cfg(windows)` **of the build
  host** (`#[cfg_attr(windows, path = "windows_build.rs")]`).

Cross-compiling would therefore need a runnable PHP that matches the target,
and Windows cannot be cross-built from Linux at all. Build every target
natively. GitHub hosts every runner needed: `ubuntu-24.04`,
`ubuntu-24.04-arm`, `macos-15`/`macos-26` (arm64), `macos-15-intel`/
`macos-26-intel` and `windows-2025` ([runner-images README][runner-images]).

General requirements:

- **libclang on every target.** bindgen needs it (ext-php-rs README,
  "Requirements").
- **A C compiler on every target, because of TLS.** The `oracledb` crate
  (26.0.0-beta.6) depends on `rustls 0.23` with default features
  ([crates.io deps][oracledb-deps]). The defaults include `aws_lc_rs`
  ([crates.io rustls][rustls-features]), so `aws-lc-sys` compiles C. That
  needs no CMake for non-FIPS builds. On Windows x86_64 it needs NASM, or
  falls back to its prebuilt NASM objects ([aws-lc-rs requirements][awslc-req];
  [Windows][awslc-win]). The result is still statically linked, so it adds no
  runtime dependency.
- **Linker flags** (as in ext-php-rs's own `.cargo/config.toml`
  ([config][ephprs-cargo-config]) and arrow-ext's ([config][arrow-cargo-config])):
  - `-Wl,-undefined,dynamic_lookup` on macOS;
  - `-crt-static` on musl;
  - `linker = "rust-lld"` on Windows MSVC.

### Linux (glibc and musl)

Build inside the official `php` Docker images. They come in `amd64` and
`arm64v8` and include `php-config` and the headers. PHP 8.4 tags exist for
`cli`/`zts` on `trixie`, `bookworm`, `alpine3.23` and `alpine3.24`
([docker-library/official-images `library/php`][php-docker-tags]). arrow-ext
follows exactly this pattern: `php:${PHP}-cli` or `php:${PHP}-zts`, natively
on `ubuntu-latest` and `ubuntu-24.04-arm`, followed by a smoke test
`php -n -d extension=… -m` ([arrow-ext release.yml][arrow-release]).

**glibc baseline: decision needed.** A `.so` built in a given container needs
that container's glibc symbol versions or older. Bookworm ships glibc 2.36
and trixie ships 2.41. Building in `php:8.4-*-bookworm` rather than the
default (trixie) tag widens compatibility. To reach older distributions
(RHEL 8 has glibc 2.28) we would need to pin the target, for example with
`cargo-zigbuild --target x86_64-unknown-linux-gnu.2.28`, while still running
the container's `php-config`. This is not verified here; the spike should
check the result with `objdump -T | grep GLIBC_`.

### macOS

Build on the macOS runners, with PHP installed by `shivammathur/setup-php`
(`phpts: nts|zts`) and LLVM from Homebrew for libclang. Rename the `.dylib`
to `.so` and zip it. This is arrow-ext's macOS job, NTS and ZTS
([arrow-ext release.yml][arrow-release]). ext-php-rs's CI builds on
`macos-latest` for NTS and TS, PHP 8.1–8.6 ([build.yml][ephprs-build-yml]).

### Windows x86_64

- **Rust nightly is required**, because PHP on Windows uses the `vectorcall`
  calling convention. In Rust that ABI is still unstable (`abi_vectorcall`,
  tracking issue [rust-lang/rust#124485][vectorcall], still open). ext-php-rs
  states the requirement ([README, "Windows Requirements"][ephprs-readme]),
  and its CI excludes `windows-latest` + stable ([build.yml][ephprs-build-yml]).
  html-to-markdown builds on a pinned stable toolchain with
  `RUSTC_BOOTSTRAP=1` instead, plus `CFLAGS=/MD` and LLVM 18 from Chocolatey
  for libclang ([xberg-io/actions build-php-extension][xberg-build-action]).
  Both approaches work; neither is "stable Rust".
- **Supported PHP builds.** The extension works only with PHP builds from
  windows.php.net. The ext-php-rs build script downloads the matching
  `php-devel-pack-…-Win32-vs17-x64.zip` (TS or NTS) by itself and links
  against `php8.lib` or `php8ts.lib` ([windows_build.rs][ephprs-win-build];
  README). Its comments say PHP builds moved to VS17 at **8.4.1**. A user on
  PHP 8.4.0 would report `vs16` and miss a `vs17` asset. That edge case is
  minor, and a duplicate `vs16`-named asset would cover it.
- **Packaging by hand.** PIE recommends `php/php-windows-builder`
  ([docs][pie-maint]), but that action drives `config.w32` and
  `phpize`-style builds, which a Cargo project does not have. We produce the
  zip ourselves and follow the "manual artifact naming scheme": the zip and
  the DLL inside it carry the same name. html-to-markdown does this for NTS
  8.3–8.5 (its release assets: `php_html_to_markdown-v3.22.2-8.4-nts-vs17-x86_64.zip`).
- **No real-world TS example.** ext-php-rs's CI covers Windows TS, but no
  Rust extension we found ships a Windows TS binary yet. Treat TS as
  "should work, verify".

### Not worth pursuing

- **`php/pie-ext-binary-builder`.** The official helper action for Unix
  binaries runs `phpize`/`./configure` from `config.m4`
  ([README][pie-builder], [action.yml][pie-builder-action]). It does not fit
  a Cargo build. We only need its naming convention, which the table above
  already covers.
- **Fully static PHP builds** (static-php-cli and the tools built on it).
  Those builds are designed to have extensions compiled in, not loaded at
  run time. Not verified here, and out of scope.

## Other packaging constraints found along the way

- **Split repository for the extension package.** Packagist reads a
  repository's root `composer.json`, and PIE looks for assets on the GitHub
  releases of the repository behind the package's dist URL. The extension
  package therefore needs its own (split) repository, for example
  `openrajska/php-oracle-ext`. Releases and binaries go on that repository,
  tagged with the version Packagist sees. Flow PHP does this for a Rust
  extension: the source lives in `flow-php/flow` under
  `src/extension/arrow-ext/`, and the workflow under that directory's
  `.github/` runs in the split `flow-php/arrow-ext` repository, which carries
  the binaries ([arrow-ext composer.json][arrow-composer];
  [flow-php/arrow-ext 0.48.0 release][arrow-ext-release]).
- **Immutable releases.** If the repository turns on immutable releases,
  assets must be uploaded to a **draft** release, which CI then publishes
  ([pie-ext-binary-builder README][pie-builder]). arrow-ext follows that
  draft → upload → publish flow.
- **PHP version pin.** Set `"require": {"php": "~8.4.0"}`, so PHP 8.3 and
  8.5 users get a resolution error rather than a "no binary" error.
  Extensions are ABI-compatible across 8.4.x patch releases, because the
  module API number is per minor version.

## Suggested `composer.json` for `openrajska/php-oracle-ext`

```json
{
    "name": "openrajska/php-oracle-ext",
    "type": "php-ext",
    "require": { "php": "~8.4.0" },
    "php-ext": {
        "extension-name": "rajska_oracle",
        "support-nts": true,
        "support-zts": true,
        "download-url-method": ["pre-packaged-binary"],
        "os-families": ["linux", "darwin", "windows"]
    }
}
```

No `configure-options`, and no `composer-default` fallback. The hard
requirement is "users never compile", and a source fallback would need a
`config.m4` that wraps cargo plus a Rust toolchain on the user's machine.

## Open items for the spike (not blockers)

1. Check that the `oracledb` crate builds and connects on Windows MSVC, macOS
   and musl. Its repository publishes no CI workflows we could inspect.
2. Pick the glibc baseline: bookworm (2.36), or a zig-pinned 2.28.
3. Prove arm64 musl and Windows TS end to end, because no upstream CI or
   real-world release covers them.
4. Smoke-test each asset in CI with `php -n -d extension=… -m` before upload,
   as arrow-ext does. Then run a real `pie install` against a draft release.

## Sources

[pie-maint]: https://github.com/php/pie/blob/main/docs/extension-maintainers.md
[pie-usage]: https://github.com/php/pie/blob/main/docs/usage.md
[pie-asset-name]: https://github.com/php/pie/blob/main/src/Platform/PrePackagedBinaryAssetName.php
[pie-win-name]: https://github.com/php/pie/blob/main/src/Platform/WindowsExtensionAssetName.php
[pie-libc]: https://github.com/php/pie/blob/main/src/Platform/LibcFlavour.php
[pie-dlm]: https://github.com/php/pie/blob/main/src/Downloading/DownloadUrlMethod.php
[pie-listener]: https://github.com/php/pie/blob/main/src/ComposerIntegration/Listeners/OverrideDownloadUrlInstallListener.php
[pie-gh-assets]: https://github.com/php/pie/blob/main/src/Downloading/GithubPackageReleaseAssets.php
[pie-package]: https://github.com/php/pie/blob/main/src/DependencyResolver/Package.php
[pie-unixbuild]: https://github.com/php/pie/blob/main/src/Building/UnixBuild.php
[pie-buildtools]: https://github.com/php/pie/blob/main/src/SelfManage/BuildTools/CheckAllBuildTools.php
[pie-install-cmd]: https://github.com/php/pie/blob/main/src/Command/InstallCommand.php
[pie-ci]: https://github.com/php/pie/blob/main/.github/workflows/continuous-integration.yml
[pie-140]: https://github.com/php/pie/releases/tag/1.4.0
[pie-150]: https://github.com/php/pie/releases/tag/1.5.0
[pie-160]: https://github.com/php/pie/releases/tag/1.6.0-rc.1
[pie-701]: https://github.com/php/pie/issues/701
[pie-builder]: https://github.com/php/pie-ext-binary-builder
[pie-builder-action]: https://github.com/php/pie-ext-binary-builder/blob/main/action.yml
[ephprs-readme]: https://github.com/extphprs/ext-php-rs/blob/main/README.md
[ephprs-build-rs]: https://github.com/extphprs/ext-php-rs/blob/main/build.rs
[ephprs-unix-build]: https://github.com/extphprs/ext-php-rs/blob/main/unix_build.rs
[ephprs-win-build]: https://github.com/extphprs/ext-php-rs/blob/main/windows_build.rs
[ephprs-build-yml]: https://github.com/extphprs/ext-php-rs/blob/main/.github/workflows/build.yml
[ephprs-musl-docker]: https://github.com/extphprs/ext-php-rs/blob/main/.github/action/musl/Dockerfile
[ephprs-cargo-config]: https://github.com/extphprs/ext-php-rs/blob/main/.cargo/config.toml
[vectorcall]: https://github.com/rust-lang/rust/issues/124485
[arrow-composer]: https://github.com/flow-php/flow/blob/1.x/src/extension/arrow-ext/composer.json
[arrow-release]: https://github.com/flow-php/flow/blob/1.x/src/extension/arrow-ext/.github/workflows/release.yml
[arrow-cargo-config]: https://github.com/flow-php/flow/blob/1.x/src/extension/arrow-ext/.cargo/config.toml
[arrow-ext-release]: https://github.com/flow-php/arrow-ext/releases/tag/0.48.0
[h2m-composer]: https://github.com/xberg-io/html-to-markdown/blob/main/composer.json
[xberg-build-action]: https://github.com/xberg-io/actions/blob/main/build-php-extension/action.yml
[runner-images]: https://github.com/actions/runner-images/blob/main/README.md
[brew-tiers]: https://docs.brew.sh/Support-Tiers
[php-docker-tags]: https://github.com/docker-library/official-images/blob/master/library/php
[oracledb-deps]: https://crates.io/crates/oracledb/26.0.0-beta.6/dependencies
[rustls-features]: https://crates.io/crates/rustls
[awslc-req]: https://aws.github.io/aws-lc-rs/requirements/index.html
[awslc-win]: https://aws.github.io/aws-lc-rs/requirements/windows.html

- PIE extension maintainer docs: <https://github.com/php/pie/blob/main/docs/extension-maintainers.md>
- PIE source (`main`): asset naming, libc detection, download-method selection, Unix install, build-tools check (links above)
- ext-php-rs 0.16.5 README, build scripts and CI: <https://github.com/extphprs/ext-php-rs>
- Real Rust/ext-php-rs PIE extensions with binaries: `flow-php/arrow-ext` (<https://github.com/flow-php/arrow-ext/releases>),
  `xberg-io/html-to-markdown` (<https://github.com/xberg-io/html-to-markdown/releases>),
  `lindera/lindera` (<https://github.com/lindera/lindera/releases>, Linux and macOS arm64, NTS and ZTS)
- Full-matrix naming in the wild (C extension, includes musl, ZTS and Windows): `phpstan/turbo-ext` 2.3.7 release assets (<https://github.com/phpstan/turbo-ext/releases>)
- GitHub runner images: <https://github.com/actions/runner-images>
- Homebrew support tiers: <https://docs.brew.sh/Support-Tiers>
- Official PHP Docker tags: <https://github.com/docker-library/official-images/blob/master/library/php>
