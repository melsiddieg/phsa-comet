#!/bin/sh
# Turn a certificate bundle + private key into the tls.crt / tls.key Caddy uses.
#
#   ./prepare.sh all.pem comet.key                 # hostname from .env.production
#   ./prepare.sh all.pem comet.key comet.phsa.ca   # or give it explicitly
#
# What it does:
#   - decrypts the key if it has a passphrase (asks for it, or reads KEY_PASS)
#   - finds the certificate that matches the key and puts it FIRST in tls.crt
#     (Caddy treats the first certificate as the server certificate)
#   - orders the rest by issuer: server -> intermediate -> root
#   - checks the hostname, expiry date and key match
#   - writes tls.crt and tls.key next to this script only if every check passes
#
# Needs only sh, awk and openssl (1.1.1 or 3.x).
set -eu

if [ $# -lt 2 ]; then
    echo "usage: $0 <bundle.pem> <key-file> [hostname]" >&2
    exit 2
fi
bundle=$1
key=$2
here=$(cd "$(dirname "$0")" && pwd)

host=${3:-}
if [ -z "$host" ] && [ -f "$here/../../../.env.production" ]; then
    host=$(grep -E '^[[:space:]]*COMET_DOMAIN=' "$here/../../../.env.production" | tail -1 | cut -d= -f2- | tr -d '"'"'"' \r')
fi

fail() { echo "ERROR: $*" >&2; exit 1; }
[ -r "$bundle" ] || fail "cannot read $bundle"
[ -r "$key" ] || fail "cannot read $key"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT

# 1. Private key -> unencrypted PEM. Prompts if it has a passphrase.
if [ -n "${KEY_PASS:-}" ]; then
    openssl pkey -in "$key" -passin env:KEY_PASS -out "$tmp/tls.key" \
        || fail "could not read the key (wrong KEY_PASS?)"
else
    openssl pkey -in "$key" -out "$tmp/tls.key" \
        || fail "could not read the key (wrong passphrase, or not a private key)"
fi
key_pub=$(openssl pkey -in "$tmp/tls.key" -pubout)

# 2. Split the bundle into one file per certificate. Anything else in the
#    file (a private key block, text headers) is ignored.
awk -v dir="$tmp" '
    /-----BEGIN CERTIFICATE-----/ { n++; f = sprintf("%s/cert%02d.pem", dir, n) }
    f != ""                       { print > f }
    /-----END CERTIFICATE-----/   { close(f); f = "" }
' "$bundle"
count=$(ls "$tmp"/cert*.pem 2>/dev/null | wc -l | tr -d ' ')
[ "$count" -gt 0 ] || fail "no certificates found in $bundle"

# 3. Find the certificate that belongs to the key.
leaf=""
for c in "$tmp"/cert*.pem; do
    if [ "$(openssl x509 -in "$c" -noout -pubkey)" = "$key_pub" ]; then
        leaf=$c
        break
    fi
done
[ -n "$leaf" ] || fail "no certificate in $bundle matches this key"

# 4. Build the chain: server certificate first, then each certificate's
#    issuer in turn (server -> intermediate -> root). Some strict clients
#    reject any other order. Certificates that are not part of that path are
#    appended at the end.
subj() { openssl x509 -in "$1" -noout -subject | sed 's/^subject= *//'; }
issr() { openssl x509 -in "$1" -noout -issuer  | sed 's/^issuer= *//'; }
used=" $leaf "
cat "$leaf" > "$tmp/tls.crt"
cur=$leaf
while :; do
    [ "$(issr "$cur")" = "$(subj "$cur")" ] && break    # self-signed root: done
    next=""
    for c in "$tmp"/cert*.pem; do
        case "$used" in *" $c "*) continue ;; esac
        if [ "$(subj "$c")" = "$(issr "$cur")" ]; then next=$c; break; fi
    done
    [ -n "$next" ] || break
    cat "$next" >> "$tmp/tls.crt"
    used="$used$next "
    cur=$next
done
for c in "$tmp"/cert*.pem; do
    case "$used" in *" $c "*) ;; *) cat "$c" >> "$tmp/tls.crt" ;; esac
done

# 5. Checks.
echo "Certificates in bundle : $count"
echo "Server certificate     : $(openssl x509 -in "$leaf" -noout -subject | sed 's/^subject= *//')"
echo "Issued by              : $(openssl x509 -in "$leaf" -noout -issuer  | sed 's/^issuer= *//')"
san=$(openssl x509 -in "$leaf" -noout -ext subjectAltName 2>/dev/null | tail -n +2 | tr -d ' ')
echo "Names (SAN)            : ${san:-<none>}"
echo "Expires                : $(openssl x509 -in "$leaf" -noout -enddate | cut -d= -f2)"

openssl x509 -in "$leaf" -noout -checkend 0 >/dev/null || fail "certificate has expired"
if ! openssl x509 -in "$leaf" -noout -checkend 2592000 >/dev/null; then
    echo "WARNING: certificate expires within 30 days"
fi

if [ -n "$host" ]; then
    parent=${host#*.}
    case ",$san," in
        *",DNS:$host,"*|*",DNS:*.$parent,"*) echo "Hostname $host        : covered" ;;
        *) fail "certificate does not cover $host (names: ${san:-none})" ;;
    esac
else
    echo "WARNING: no hostname given and COMET_DOMAIN not found; hostname not checked"
fi

if [ "$count" -lt 2 ]; then
    echo "WARNING: bundle has no intermediate certificate. Some browsers and curl"
    echo "         will reject the chain. Ask the PKI team for the full chain."
fi

# 6. Everything passed: install the files.
cp "$tmp/tls.crt" "$here/tls.crt"
cp "$tmp/tls.key" "$here/tls.key"
chmod 644 "$here/tls.crt"
chmod 600 "$here/tls.key"
echo
echo "Wrote $here/tls.crt (server certificate first, $count total)"
echo "Wrote $here/tls.key (unencrypted, mode 600)"
echo
echo "Next: set COMET_TLS=tls /etc/caddy/certs/tls.crt /etc/caddy/certs/tls.key"
echo "      in .env.production, then run: comet up -d"
