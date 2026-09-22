# TLS certificate (optional)

Put a certificate and private key here when the network blocks ACME, so Caddy
cannot obtain one from Let's Encrypt automatically.

```
certs/
  tls.crt    full chain: server cert first, then intermediates
  tls.key    private key, PEM, not passphrase-protected
```

Then set in `.env.production`:

```bash
COMET_TLS=tls /etc/caddy/certs/tls.crt /etc/caddy/certs/tls.key
```

Restart Caddy: `comet up -d`.

## Symptom this solves

```
tls.obtain  could not get certificate from issuer
  ... acme.zerossl.com ...: read: connection reset by peer
  ... acme-v02.api.letsencrypt.org ...: read: connection reset by peer
```

Caddy retries for 30 days, so the site stays on HTTP until a certificate is
available.

## Getting the files ready

Have a PEM bundle and a key (e.g. `all.pem` + `comet.key`)? Put them here and
run:

```bash
./prepare.sh all.pem comet.key                 # hostname read from .env.production
./prepare.sh all.pem comet.key comet.phsa.ca   # or pass it explicitly
```

It writes `tls.crt` and `tls.key` after checking that the key matches, the
certificate covers the hostname, and it has not expired. It also puts the
certificates in the order Caddy needs (server → intermediate → root) and
removes the key passphrase, since Caddy cannot read an encrypted key. If any
check fails, it writes nothing. For non-interactive use, pass the passphrase
as `KEY_PASS=...`.

Other formats (Windows `.pfx`, separate files): see the deployment README,
section **"When ACME is blocked: use your own certificate"**.

## Temporary alternative

For internal testing only, Caddy can issue its own certificate:

```bash
COMET_TLS=tls internal
```

Browsers will warn on every visit because the issuing CA is not trusted. Do
not use this for real users or real data.

## Note

These files are secret and are git-ignored. Never commit `tls.key`.
