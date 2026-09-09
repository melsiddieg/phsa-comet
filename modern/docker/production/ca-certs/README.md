# Corporate CA certificates (optional)

Drop `.crt` files here (PEM format) when the network uses **TLS inspection**.

On this (Debian) image the build copies everything here into
`/usr/local/share/ca-certificates/` and runs `update-ca-certificates`, the
standard Debian flow — no `SSL_CERT_FILE` gymnastics required. Leaving the
directory empty is fine; the build is unchanged.

## Symptom this solves

```
WARNING: updating and opening https://dl-cdn.alpinelinux.org/... : TLS: unspecified error
```

`docker pull` works (the daemon uses the **host** trust store, which has the
corporate root) while `apk` inside a build fails (containers ship their own
minimal CA bundle, which does not).

## Getting the certificate

Easiest — reuse the host bundle, which already works:

```bash
cp /etc/ssl/certs/ca-certificates.crt docker/production/ca-certs/host-bundle.crt
```

Or extract just the inspecting proxy's root:

```bash
openssl s_client -showcerts -connect dl-cdn.alpinelinux.org:443 </dev/null 2>/dev/null \
  | awk '/BEGIN CERT/,/END CERT/' > docker/production/ca-certs/corporate-root.crt
```

Then rebuild: `comet build`.

## Note

These are public certificates, not secrets — but they identify internal
infrastructure, so this directory is git-ignored by default. Commit one
deliberately only if your team wants it baked into the repo.
