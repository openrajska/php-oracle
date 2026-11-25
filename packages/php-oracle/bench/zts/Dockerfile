# The ZTS side of the benchmark: PHP 8.4 ZTS (FrankenPHP's official image),
# oci8 over Instant Client as the baseline, wrk, and the Rust toolchain to
# build oracledb against ZTS headers. The same image as the spike's
# (spikes/rust-oracledb/bench/zts), so an existing build is reused.
FROM dunglas/frankenphp:1-php8.4-bookworm

ARG RUST_VERSION=1.100.0
ARG OCI8_VERSION=3.4.1
ARG TARGETARCH

RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      build-essential pkg-config cmake clang libclang-dev libaio1 unzip curl ca-certificates wrk \
 && rm -rf /var/lib/apt/lists/*

# Instant Client from Oracle's "latest" permalink (local dev image only, no redistribution).
RUN set -eux; \
    case "${TARGETARCH}" in arm64) a=linux-arm64 ;; amd64) a=linuxx64 ;; *) exit 1 ;; esac; \
    base=https://download.oracle.com/otn_software/linux/instantclient; tmp="$(mktemp -d)"; \
    curl -fsSL -o "$tmp/basic.zip" "$base/instantclient-basiclite-$a.zip"; \
    curl -fsSL -o "$tmp/sdk.zip" "$base/instantclient-sdk-$a.zip"; \
    mkdir -p /opt/oracle; unzip -qo "$tmp/basic.zip" -d /opt/oracle; unzip -qo "$tmp/sdk.zip" -d /opt/oracle; \
    mv /opt/oracle/instantclient_* /opt/oracle/instantclient; \
    echo /opt/oracle/instantclient > /etc/ld.so.conf.d/oracle-instantclient.conf; ldconfig; rm -rf "$tmp"

# oci8 built for ZTS; not enabled globally, each run loads it with -d extension=oci8.so.
RUN set -eux; tmp="$(mktemp -d)"; \
    curl -fsSL "https://pecl.php.net/get/oci8-${OCI8_VERSION}.tgz" | tar -xz -C "$tmp"; \
    cd "$tmp/oci8-${OCI8_VERSION}"; phpize; \
    ./configure --with-oci8=instantclient,/opt/oracle/instantclient; \
    make -j"$(nproc)"; make install; cd /; rm -rf "$tmp"

ENV RUSTUP_HOME=/usr/local/rustup CARGO_HOME=/usr/local/cargo \
    CARGO_TARGET_DIR=/var/cache/cargo-target PATH=/usr/local/cargo/bin:$PATH
RUN curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
      | sh -s -- -y --no-modify-path --profile minimal --default-toolchain "${RUST_VERSION}"

WORKDIR /app
