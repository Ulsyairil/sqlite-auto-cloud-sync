# Security Policy

## Supported Versions

Only the latest minor release receives security patches.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

Do not file a public issue. Instead, email **security@yourdomain.com** (replace with your contact).

Include:
- Affected package(s) and version(s)
- Steps to reproduce
- Impact assessment (data loss, unauthorized access, etc.)

We aim to acknowledge within 48 hours and publish a fix within 7 days for critical issues.

## Threat Model Assumptions

- Object storage credentials are scoped to the sync prefix per device/tenant.
- Payloads are not encrypted at rest; enable bucket encryption if required.
- `device_id` is the only trust anchor; spoofing it can cause data loss via LWW.
- Compaction deletes history; run only after all devices have synced.
- Schema mismatch throws rather than silently merging incompatible changes.

## Hardening Checklist for Production

- [ ] Use IAM policies restricting each device to its own `prefix/device-<id>/*` prefix.
- [ ] Enable server-side encryption on the bucket.
- [ ] Set a lifecycle rule to expire multipart uploads > 1 day.
- [ ] Schedule `sqlite-sync compact` via cron after verifying all devices synced.
- [ ] Take bucket snapshots/backups before migrations.
- [ ] Monitor `verify` output in CI to catch drift early.
- [ ] Do not expose `sync` endpoint publicly; run in a trusted worker/queue.