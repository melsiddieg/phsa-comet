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

Request one from your organisation's PKI / certificate service for the exact
hostname in `COMET_DOMAIN`. Combine the files if they arrive separately:

```bash
cat server.crt intermediate.crt > docker/production/certs/tls.crt
cp server.key docker/production/certs/tls.key
chmod 600 docker/production/certs/tls.key
```

Check the file matches the hostname and is not expired:

```bash
openssl x509 -in docker/production/certs/tls.crt -noout -subject -dates
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
