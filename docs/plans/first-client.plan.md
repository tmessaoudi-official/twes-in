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
- [2026-10-17 16:41] AGREED: Wednesday adds supplier orders and reports to the built modules and the release work.
- [2026-10-17 16:41] AGREED: Arabic ships in full on Wednesday, printed documents included; the developer, a native Arabic speaker, reviews the wording.
- [2026-10-17 16:41] AGREED: The demo gives each sign-in its own private copy of the demo company: every PDF stamped « DÉMO — sans valeur fiscale », mail captured and never sent, nothing reaching the shop's data, deleted at sign-out or after 2 h idle.
- [2026-10-17 16:41] AGREED: The client's Excel file arrives tonight or tomorrow, as a copy with made-up values and as the real file, under var/claude/client-excel/ (gitignored, never committed or sent anywhere).
- [2026-10-17 16:41] AGREED: No compromise: every rule, maturity round and quality bar holds; anything found that breaks a rule or is a mess that works is corrected properly, proven by tests.
- [2026-10-17 16:43] AGREED: Whatever is not 100% on Wednesday 10:00 Tunisia time (09:00 UTC) is switched off, or hidden for a language, and arrives in the first update through the safe update path; nothing half-done is visible to the shop.
- [2026-10-17 16:43] AGREED: The run's order: 1 release core (install on Windows, Linux and macOS, safe updates, security, LAN HTTPS), 2 the demo sandbox, 3 the language foundations and RTL, 4 the proof pass of every built module in fr, en and ar with the client's Excel file, 5 reports, 6 supplier orders, 7 the Arabic texts and documents, 8 guides, help and README on the final screens.
- [2026-10-17 16:43] AGREED: The developer reviews the Arabic wording in the running app and in the source, no marking page; Arabic is shown to the shop only once reviewed.

## Formal Plan
Deadline: Wednesday 2026-10-21 10:00 Tunisia time (09:00 UTC, 11:00 Paris). Whatever is not 100% then is switched off
or hidden and ships in the first update. Each step is sliced test-first, gated (`make gate`), committed by pathspec
from the index; pushes at the batch size or a step's end, CI read job by job. Every step's completion note says what
was and was not certified by execution.

1. Release core. The shop's install: production images (`compose.prod.yaml`, `compose.backup.yaml`) on Windows 10/11
   (Docker Desktop and WSL 2), Linux and macOS, started with the machine; reached on localhost and over HTTPS on the LAN
   address with a local certificate authority the phones trust once. An update is one command: it takes an encrypted
   backup first, migrates, checks health and rolls back to the backup and the previous images when anything fails;
   migrations are additive (expand, then contract in a later release) so the previous version still runs on the new
   schema. A security pass over what an on-premises install exposes: secrets generated per install, no default
   password, the operator's first sign-in, ports, headers, the backup's encryption.
2. The demo sandbox: `demo@example.com` signs into a private copy of the demo company made for that sign-in; every
   document stamped « DÉMO — sans valeur fiscale », mail captured, nothing reaching the shop's company; deleted at
   sign-out or after 2 h idle, by a scheduled task that is idempotent.
3. Language foundations and RTL (row 338's foundations): a language is data (tag, name, direction, script, plurals,
   font); the person's language is one BCP 47 tag; the layout mirrors through logical properties; Arabic's font
   self-hosted; a gate refuses a language code written in code.
4. Proof pass of every built module in fr, en and ar: each workflow and its edge cases end to end, defects fixed at
   their root, the client's Excel file mapped into the importer as a saved mapping.
5. Reports.
6. Supplier orders and goods receipts (row 81).
7. The Arabic texts and printed documents, reviewed by the developer in the app and the source.
8. Guides, help and README on the final screens.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 0 | Sign-in restored: the development database migrated | S | done | 976d6c04 | CLAUDE.md |
| 1 | Release core: install on Windows, Linux, macOS; safe updates with backup and rollback; security; LAN HTTPS | L | todo | - | infra/** scripts/** compose*.yaml docs/** |
| 2 | Demo sandbox per sign-in, stamped, deleted at sign-out or after 2 h idle | L | todo | - | api/** web/** |
| 3 | Language foundations and RTL | L | todo | - | api/** web/** |
| 4 | Proof pass of every built module in fr, en, ar; the client's Excel mapped | L | todo | - | web/e2e/** api/** web/** |
| 5 | Reports | L | todo | - | api/** web/** |
| 6 | Supplier orders and goods receipts | L | todo | - | api/** web/** |
| 7 | Arabic texts and printed documents | L | todo | - | web/public/i18n/** api/translations/** api/templates/** |
| 8 | Guides, help and README on the final screens | M | todo | - | docs/** web/** |
<!-- /progress-block -->
### Blocked
### Needs input
- Their Excel file, to map its columns into the importer (expected tonight or tomorrow).
- After Wednesday, in an order to set: declarations (fiscal rules await an accountant), composite products, jobs, the till (the Tunisian homologated-register question), zakat (its research pass, row 120).
### Needs research
### Fragile
### Known issues
