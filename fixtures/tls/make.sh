#!/usr/bin/env bash
# Regenerates the TLS test wallets in this folder (see README.md). They are
# checked in: the slim database image the suites use has no orapki, so the
# server wallet is made once here, with OpenSSL from the ddev web image and
# orapki from gvenzl/oracle-free's "full" image (about 2 GB). Run on the host:
#   fixtures/tls/make.sh
# Everything here is a test fixture signed by a throwaway CA: never use it
# for anything else.
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
openssl_image="${OPENSSL_IMAGE:-ddev/ddev-webserver:v1.25.4}"
orapki_image="${ORAPKI_IMAGE:-gvenzl/oracle-free:23.26.3-full}"
password="rajska_wallet1"

rm -rf "$here/server" "$here/client"
mkdir -p "$here/server" "$here/client"/{ca,mtls,mtls-encrypted,p12,sso}

docker run --rm -i -u "$(id -u):$(id -g)" -v "$here:/tls" -w /tls --entrypoint bash "$openssl_image" -s <<EOF
set -euo pipefail
work=\$(mktemp -d)
days=36500

# The CA.
openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days \$days \
  -subj "/CN=rajska-oracle test CA" -keyout \$work/ca.key -out \$work/ca.crt \
  -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign"

# The server: the host names the suites reach the listener by.
openssl req -newkey rsa:2048 -nodes -sha256 -subj "/CN=oracle" \
  -keyout \$work/server.key -out \$work/server.csr
openssl x509 -req -sha256 -days \$days -in \$work/server.csr \
  -CA \$work/ca.crt -CAkey \$work/ca.key -CAcreateserial -out \$work/server.crt \
  -extfile <(printf '%s\n' \
    'basicConstraints=CA:FALSE' \
    'keyUsage=critical,digitalSignature,keyEncipherment' \
    'extendedKeyUsage=serverAuth,clientAuth' \
    'subjectAltName=DNS:oracle,DNS:localhost,IP:127.0.0.1')
openssl pkcs12 -export -in \$work/server.crt -inkey \$work/server.key \
  -certfile \$work/ca.crt -name server -passout pass:$password \
  -certpbe PBE-SHA1-3DES -keypbe PBE-SHA1-3DES -macalg sha1 -out \$work/server.p12
cp \$work/server.p12 \$work/ca.crt server/

# The client, for mTLS.
openssl req -newkey rsa:2048 -nodes -sha256 -subj "/CN=rajska-oracle test client" \
  -keyout \$work/client.key -out \$work/client.csr
openssl x509 -req -sha256 -days \$days -in \$work/client.csr \
  -CA \$work/ca.crt -CAkey \$work/ca.key -CAcreateserial -out \$work/client.crt \
  -extfile <(printf '%s\n' 'basicConstraints=CA:FALSE' 'extendedKeyUsage=clientAuth')

# Client wallets: the CA only (one-way TLS to a private CA), the client's
# certificate and key with the CA (mTLS), the same with the key encrypted
# (walletPassword), and the two formats oracledb cannot read.
cp \$work/ca.crt client/ca/ewallet.pem
cat \$work/client.crt \$work/client.key \$work/ca.crt > client/mtls/ewallet.pem
openssl pkcs8 -topk8 -v2 aes-256-cbc -v2prf hmacWithSHA256 \
  -in \$work/client.key -passout pass:$password -out \$work/client.enc.key
cat \$work/client.crt \$work/client.enc.key \$work/ca.crt > client/mtls-encrypted/ewallet.pem
openssl pkcs12 -export -in \$work/client.crt -inkey \$work/client.key \
  -certfile \$work/ca.crt -name client -passout pass:$password \
  -certpbe PBE-SHA1-3DES -keypbe PBE-SHA1-3DES -macalg sha1 -out client/p12/ewallet.p12
cp client/p12/ewallet.p12 client/sso/ewallet.p12
rm -rf \$work
EOF

docker run --rm -i -u "$(id -u):$(id -g)" -v "$here:/tls" -w /tls --entrypoint bash "$orapki_image" -s <<EOF
set -euo pipefail
# The server wallet: auto-login (cwallet.sso), so the listener and the
# server processes open it without a password.
orapki wallet create -wallet server -pwd $password -auto_login -nologo
orapki wallet import_pkcs12 -wallet server -pwd $password -pkcs12file server/server.p12 \
  -pkcs12pwd $password -nologo
rm server/server.p12 server/ca.crt
# A client auto-login wallet: cwallet.sso only.
orapki wallet create -wallet client/sso -pwd $password -auto_login -nologo
orapki wallet display -wallet server -nologo
rm client/sso/ewallet.p12 server/*.lck client/sso/*.lck
EOF
ls -R "$here/server" "$here/client"
