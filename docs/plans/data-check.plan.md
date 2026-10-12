# data-check Plan

## Decisions Log
- [2026-10-12 04:03] AGREED: stock left out of the data check, holding by construction (SPEC § 7).
- [2026-10-12 04:03] AGREED: operators see the check, the company and a count only; details in the console and logs.
- [2026-10-12 04:03] AGREED: told every night while found, in the app and by mail.
- [2026-10-12 04:03] AGREED: the six-step plan below (core `DataHealth`, `DeclaresDataChecks`).
- [2026-10-12 04:20] ASSUMED (review): nightly run as a daily AsPeriodicTask from app.data_check.at, no cron library added (SPEC § 7)
- [2026-10-12 04:20] ASSUMED (review): gap check by last digits, per series and reset period, only formats ending with {SEQ}; invoices and credit notes first (SPEC § 7)
- [2026-10-12 04:20] ASSUMED (review): check codes named by owning context (invoices.numbering, not numbering.gaps), shown untranslated (SPEC § 7)
- [2026-10-12 04:20] ASSUMED (review): six arithmetic identities, line tax left out (SPEC § 7)

## Formal Plan
1. DataHealth context: `DeclaresDataChecks`, `DataFinding`, `RunDataChecks`, `app:data:check` (non-zero exit on any finding), a nightly `#[AsPeriodicTask]` from the hour `app.data_check.at` (no cron library: SPEC § 7 04:19).
2. Invoices: `invoice_chain_head` row written under the series lock (backfilled from the chains), the chain verifier against it (two-step reads, lines fetched in the same query), the issued documents' arithmetic identities.
3. Tenancy: numbers without gaps per series and reset period (first period may start above 1; unparsable numbers counted, not raised).
4. References across companies: `ScaleInvariants` moved out of `DataFixtures` and declared as a check.
5. Operator notification `data.check.failed` (check, company, count), mailed, fr/en.
6. The scale command runs the check; `DemoFixturesTest` asserts no finding; failing tests first; sabotages; time and peak memory measured on the 100k scale database.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | DataHealth context and command | M | done | da0ad5ce | api/src/DataHealth/** |
| 2 | Invoices checks and chain head | M | done | da0ad5ce | api/src/Module/Invoices/** api/migrations/** |
| 3 | Numbers without gaps | M | done | da0ad5ce | api/src/Tenancy/** |
| 4 | References across companies | S | done | da0ad5ce | api/src/Shared/** api/src/DataFixtures/** |
| 5 | Operator notification | S | done | da0ad5ce | api/src/DataHealth/** web/public/i18n/** |
| 6 | Wiring, demo, measurement | M | done | da0ad5ce | api/tests/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
- Not certified by execution: the operator mail's rendering (the inbox row is asserted, the mail text is not looked at), and the chain walk at 1M (measured at 100k: 24.8 s, 48 MB peak).
