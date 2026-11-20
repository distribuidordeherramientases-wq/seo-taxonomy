# Changelog

## Unreleased — WordPress.org preflight corrections
- Replace computed gettext domains with literal plugin text domains.
- Clarify ordered placeholders and translator comments for WordPress Plugin Check.
- Strengthen request sanitization, safely bound nonce-check annotations and add the language directory.
- Follow-up PR #2867, Issue #2862.

## 0.1.0 — 2026-11-20
- Initial independent Ticketing plugin and dedicated private database schema.
- Customer form shortcode, editable labels, request categories, branding and email routing.
- Ticket statuses, priorities, assignees, private and public messages, protected attachments.
- Private customer reply link and staff notifications.
- Optional integration hook for future Tasks adapter, no Core dependency.
- References #2862.
- #2870: first test-site delivery of the complete, independent plugin to STAGING and Focazul, with no automatic activation and no production rollout.
