#!/usr/bin/env bash
# (Re)generate a self-signed TLS cert for eduQR covering BOTH the public IP
# and the Tailscale hostname. Run as root:  sudo bash deploy/make-cert.sh
set -euo pipefail

IP="79.123.193.62"
HOST="haytekr640.tail20f79d.ts.net"
DIR="/etc/nginx/ssl"

mkdir -p "$DIR"
openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
    -keyout "$DIR/eduqr.key" \
    -out    "$DIR/eduqr.crt" \
    -subj   "/CN=$HOST" \
    -addext "subjectAltName=DNS:$HOST,IP:$IP"

chmod 600 "$DIR/eduqr.key"
chmod 644 "$DIR/eduqr.crt"
echo "Cert written to $DIR/eduqr.{crt,key} (SAN: DNS:$HOST, IP:$IP)"
