# support-access Plan

## Decisions Log
- [2026-10-12 08:53] AGREED: entry from the company switcher, « <company> · Support », owner's own screens read-only under a banner (SPEC § 7).
- [2026-10-12 08:53] AGREED: 1 h, 24 h or 72 h, default 24 h, ended early at any moment, decided from dates (SPEC § 7).
- [2026-10-12 08:53] AGREED: every read but bulk exports, selection PDFs and `product.cost.read`; one document may be printed (SPEC § 7).
- [2026-10-12 08:53] AGREED: audit the entry, then each page once per 15 minutes per operator (SPEC § 7).
- [2026-10-12 09:20] ASSUMED (review): five entries in SPEC § 7 at that stamp: any operator with a second factor; GET only and no `/exports/` under a grant; « — Support » in the switcher; granting again replaces the window, other owners told; pages recorded by route name.

## Formal Plan
Approach A: a live grant reads as a read-only membership in the two places every company path is decided
(`CompanyGuard::roleGrants`, `PermissionVoter`), plus a method listener as a second wall. Rejected: a platform copy of
the screens (one canon per screen), a temporary real membership (needs a job to expire, reuses people's role code).

1. Domain and storage: `SupportGrant` in Tenancy (company, granted by, starts at, ends at, ended at, ended by), live
   while now is inside its window and nobody ended it; `NOT_SCOPED` beside `Membership` (read across companies by the
   operator); migration.
2. Owner side: `ManageSupportAccess` (grant 1/24/72 h, end early; owner membership only, `OwnerOnly` like erasure),
   `GET/POST/DELETE /api/companies/{id}/support-access`, audited (`support_access.granted`, `.ended`); the other owners
   told on grant, every owner when it is ended early; the `support-access` settings page replaces « en construction ».
3. Authorisation: an operator with a second factor of their own, no membership and a live grant passes
   `roleGrants`/the voter for `.read` permissions only, never `product.cost.read`; exports and selection PDFs refused;
   `SupportReadOnlyListener` refuses every non-GET under `/api/companies/{granted}` with 403 `support_read_only`;
   owner-only gates (erasure, roles, invitations, members) stay closed because they need a membership.
4. Entering: `/me/companies` lists live grants marked support, `SwitchWorkingCompany` and `DescribeWorkingContext`
   accept one (permissions = the read set), `Me.supportUntil`; the shell banner for the operator and for the company's
   members while a grant is live; the web interceptor answers `support_read_only` with a toast.
5. Audit of use: `support.entered` on switching in, then `support.read` per operator and path template, deduplicated
   against `audit_log` over 15 minutes (no in-memory state: worker mode), shown as « Support » in the activity page.
6. Evidence: failing tests first (no grant 404, expired 404, ended 404, operator without second factor 404, member
   unaffected, write 403, export 403, cost hidden, erasure/roles refused, another company 404, audit rows and dedup,
   owners told); sabotages; the e2e of granting and of the operator's banner, on a company the operator is NOT a
   member of (the seed makes the operator Demo's owner); screenshots of the page and the banner before and after.

Restatement — My understanding: an owner lets the platform's operators read their company for 1/24/72 h from a
settings page; operators open it from the switcher, read-only, minus exports and cost, every visit in the activity log.
Key assumption: the operator's own second factor is required, and the dev seed's operator-owner of Demo stays (dev
data only, documented). What would redirect me: wanting a named operator rather than all of them, or write access.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | SupportGrant and migration | S | done | - | api/src/Tenancy/** api/migrations/** |
| 2 | Owner endpoints, notices, settings page | M | done | - | api/src/Tenancy/** web/src/app/** |
| 3 | Guard, voter, read-only listener | M | done | - | api/src/Tenancy/** api/src/Shared/** |
| 4 | Switcher, Me, banner | M | done | - | api/src/Tenancy/** api/src/Identity/** web/src/app/** |
| 5 | Audit of use | S | done | - | api/src/Tenancy/** api/src/Audit/** |
| 6 | Tests, sabotages, e2e, screenshots | M | done | - | api/tests/** web/e2e/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
- The seed makes the operator Demo's owner: every test of the grant path must use a company without that membership.
### Known issues
- Row 67's first half (subscription reminder mails) is not in this plan: the row stays `doing` after it lands.
