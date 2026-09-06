# Business forms (source)

Scans and blank copies of the paper forms Purple Yam Malaybalay uses today.

**Status: reference only. Not a specified feature.**

## Read this before building anything from these

The specs do not mention printable forms, PDFs, receipts, or slips **anywhere** — these are unaddressed, not approved. They are also not excluded: PRD §24 (Out of Scope) rules out delivery, customer accounts, voice/video/photo, external messaging, autonomous AI, and independent outlet production, but says nothing about printed output.

So the presence of a form here is **not** a requirement that the app reproduce it. Per `../../business-rules.md`:

> If an implementation detail conflicts with a rule here, the rule wins — stop and clarify rather than resolve it silently.

Treat a request to generate or print any of these as a new feature needing explicit approval.

## Why these are kept

They record what the business actually captures today — which fields, which approvals, which units, which signatures. That is the most reliable input available when designing the screens and reports that replace them. The closest spec'd surface is PRD §16 (FR-10: Reports and Analytics), which is screen-only: tables, charts, dashboards, and summaries.

## What belongs here

Scans or photos of blank forms, and filled examples where they clarify real usage — redact anything personal first.

Filenames: lowercase-kebab, prefixed with the business's own name for the form so it maps to the physical paperwork, e.g. `stock-transfer-slip-blank.pdf`.
