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

## Getting a certificate

Request one from your organisation's PKI for the exact hostname in
`COMET_DOMAIN`. The full procedure — converting a Windows `.pfx`, checking
the hostname, dates, chain and key match — is in the deployment README under
**"When ACME is blocked: use your own certificate"**.

Quick version for separate files:

```bash
cat server.crt intermediate.crt > tls.crt     # server cert FIRST
cp server.key tls.key && chmod 600 tls.key
[ "$(openssl x509 -in tls.crt -noout -pubkey)" = "$(openssl pkey -in tls.key -pubout)" ] \
  && echo "key matches certificate" || echo "KEY DOES NOT MATCH"
```

## Temporary alternative

For internal testing only, Caddy can issue its own certificate:

```bash
COMET_TLS=tls internal
```

Browsers will warn on every visit because the issuing CA is not trusted. Do
not use this for real users or real data.

## Note

These files are secret and are git-ignored. Never commit `tls.key`.
