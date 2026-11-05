# S5.22 triage: a page's own alert saying something failed

Row S5.22 of `stable-release.plan.md`. The search found 84 lines:

```
git grep -nE 'data-testid="[a-z-]*-(failed|unavailable|error)"' -- 'web/src/app/*.html' 'web/src/app/*.ts'
```

The search leaves out specs, `<mat-error>`, `twes-auth-error` and S5.21's members. Each line is classified by what sets its condition:

- a read;
- a write's refusal or a form's own check;
- both (a facade's `error` from `RequestState`, which `read()` and `write()` share);
- not a failure.

The table also says whether the page already offers a way to read again. Paths are under `web/src/app/`. How it was read:

- The condition's signal was followed to the methods that set it. `var/claude/s522-triage.py` did a first pass, and the rest was read by hand.
- For each list page, the alert's `error()` was compared with the `[failed]` binding of its `app-data-list`. A server-paged DataList reads again on « Réessayer » even with no `(retry)` bound (`DataList.tryAgain`).

## Counts

| Group | Set by | Read retry today | Members | Needs |
|---|---|---|---|---|
| A | read and write | yes: the page's DataList says « Réessayer » in place of rows | 24 | nothing for the read |
| B | read and write | no | 22 | a retry offered only when a read failed |
| C | read only | no | 13 | a retry |
| D | a write's refusal or a form's check | n/a | 22 | none: not a read, out of the class |
| E | not a failure | n/a | 3 | none |

So 35 members (B + C) need a read retry. On the 22 in B, the line is the same one a refused save writes, so a retry there must appear only when the error came from a read. Today `RequestState` keeps that fact private (`writeRefused`).

## A: read and write, the read retried by the page's list (24)

The alert shows the facade's `error`, and the same `error` sets the DataList's `[failed]`:

activity/activity-page.html:23, company/custom-fields-page.html:50, company/establishments-page.html:21, company/members-page.html:13, company/numbering-page.html:12, customers/customer-groups-page.html:26, customers/customers-page.html:24, delivery-notes/delivery-notes-page.html:26, expenses/expense-categories-page.html:24, expenses/expenses-page.html:37, fiscal/fiscal-taxes-page.html:18, fiscal/fiscal-units-page.html:18, inventory/stock-movements-page.html:42, invoices/invoices-page.html:49, invoices/portfolio-page.html:13, platform/platform-page.html:7 (its lists sit in tabs; covered for the tab that holds them, inferred), price-lists/price-lists-page.html:18, products/product-categories-page.html:35, products/products-page.html:56, purchases/purchases-page.html:25, quotes/quotes-page.html:23, recurring/recurring-page.html:12, recurring-expenses/recurring-expenses-page.html:14, vendors/vendors-page.html:23.

Activity and portfolio are read-only facades; the rest share one `error` between reads and writes.

A neighbouring finding, not yet reproduced: on these pages a write refused while the list holds no rows sets `[failed]`. The list's blank state would then read as a failed read, with « Réessayer », although nothing failed to read (`DataList.blank`: `failed` wins when there are no rows). It belongs to the class « a write's refusal said as a read's failure ».

## B: read and write, no way to read again (22)

company/closing-page.html:12, company/company-profile-page.html:22, company/company-security-page.html:10, company/document-design-page.html:24, company/modules-page.html:12, company/roles-page.html:12, customers/customer-page.html:49 (its contacts list sits inside `@if (standing())`, so it is absent when the customer's read fails), customers/party-defaults.html:21, expenses/expense-page.html:62, import/import-page.html:17, inventory/loss-files-dialog.ts:44, inventory/stock-map-page.html:456, inventory/stock-valuation-page.html:16, invoices/invoice-instruments.html:100, licensing/subscription-page.html:7, products/article-defaults.html:21, products/product-homes.html:5, products/product-page.html:61, products/product-photos.html:7, products/product-reorder-points.html:6, settings/settings-page.html:25, vendors/vendor-page.html:49.

## C: read only, no way to read again (13)

activity/record-history.ts:39, customers/customer-account.html:11, customers/customer-statement.html:55, invoices/invoice-reminders.ts:31, products/product-cost-history.html:5, products/product-labels-sheet-page.html:30, products/product-scan-card.html:11, products/product-substitutes.html:5, recently-deleted/recently-deleted-page.html:11, reports/reports-page.html:6, vendors/vendor-purchases.html:8, invitation/accept-invitation-page.html:118 (the invitation's read: only `network` is worth retrying, an expired link is not), signup/finish-signup-page.html:159 (the same for the sign-up link).

## D: a write's refusal or a form's own check (22)

accounting-export/accounting-export-page.html:58 (an invalid period, checked in the browser), auth/two-factor-page.html:436, delivery-notes/delivery-note-page.html:138, delivery-notes/delivery-note-page.html:237, inventory/place-movement.html:14, inventory/stock-count-page.html:10, inventory/stock-locations-page.html:37, inventory/stock-page.html:45, invoices/invoice-page.html:125, invoices/invoice-page.html:334, invoices/invoice-page.html:472, price-lists/price-lists-page.html:75, price-lists/price-lists-page.html:94, products/product-barcodes.html:63, purchases/purchase-order-page.html:60, purchases/receive-dialog.html:25, quotes/quote-answer-dialog.ts:140, quotes/quote-deposit-dialog.ts:99, quotes/quote-page.html:85, quotes/quote-page.html:203, shared/company-proof/company-proof-dialog.ts:83, shared/step-up/step-up-dialog.ts:61.

## E: not a failure (3)

- platform/platform-page.html:172: the count of messages the worker gave up on, which is data, not a read that failed.
- products/price-calculator.ts:140: a state, as S5.21 found.
- shared/feedback/activity-bar.ts:36: it counts down and retries by itself.

## Open

The canon for the 35 is the developer's call, on the decision desk (`q-read-retry-canon`). Once it is decided, the 35 get it and a gate over `read-retry:` holds the floor.
