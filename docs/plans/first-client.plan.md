# first-client Plan

The first installation: a hardware shop (quincaillerie), on its own computer, free of charge, by Wednesday
2026-10-21. It runs in production from then on, so nothing done after it may break what exists or lose data.

## Decisions Log
- [2026-10-17 16:31] AGREED: The release goal comes first: the tool-audit queue (steps 3b, 3c, 4, 5) and the i18n goal's order are paused, not dropped, and resume after the release.
- [2026-10-17 16:31] AGREED: Must-have beside invoices (recurring), products (stock and price lists), expenses (recurring), delivery notes, « À surveiller », reports, subscriptions and the demo: the phone as scanner, quotes, the import of their data (their Excel file: the file is adapted or the importer is, whichever keeps them from retyping), and a counter sale.
- [2026-10-17 16:31] AGREED: The installation supports Windows 10/11, Linux and macOS; the shop's machine is most likely Windows 10/11. Reached on localhost and over HTTPS on the LAN address, for the phones.
- [2026-10-17 16:31] AGREED: The app is in production from the first install: an update never breaks what exists or loses data, and installs as easily on premises as hosted.
- [2026-10-17 16:31] AGREED: A demo sign-in (demo@example.com) can try everything; everything it makes is stamped as demo, and all of it is deleted when it signs out: a demo only.
- [2026-10-17 16:31] AGREED: Internationalisation, Arabic and localisation are wanted in the Wednesday version.

## Formal Plan
<!-- written once the scope is confirmed -->

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Sign-in restored: the development database migrated | S | done | 976d6c04 | CLAUDE.md |
<!-- /progress-block -->
### Blocked
### Needs input
- The Wednesday scope, as asked on 2026-10-17 16:20: clients, quotes, composite products, jobs, vendors, accounting export, all of stock and the 2D/3D map, « À surveiller », declarations, reports, zakat, supplier orders, real-time notifications that lead to where they came from, settings, every kind of document, i18n and Arabic. Confirmed or cut, with the challenge.
- Their Excel file, to map its columns into the importer.
### Needs research
### Fragile
### Known issues
