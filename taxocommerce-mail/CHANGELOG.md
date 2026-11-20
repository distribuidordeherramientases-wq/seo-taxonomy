# Changelog

## 0.1.0 — 2026-11-20
- Initial independent multi-account hosting mail client, using IMAPS over verified TLS and authenticated SMTP (465/587).
- Store account credentials with AES-256-GCM protected by WordPress salts; no message copies stored locally.
- Administrator-only inbox, message reading, reply and compose; bounded read-only IMAP, per-account setup.
- Two check views and bundled user/technical guidance.
- Issue #2880. No Gmail, POP3, automatic sync or production deployment.
