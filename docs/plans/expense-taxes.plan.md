# expense-taxes Plan

An expense records a supplier's bill as the paper shows it: several VAT rates, FODEC, the stamp (screens-maturity
L-603, P1; round 2's slice S8 waits for it).

## Decisions Log
- [2026-10-14 19:19] AGREED: L-603 plan approved as written (docs/plans/expense-taxes.plan.md): an expense has amount lines (a net and its percentage taxes, a VAT rate and FODEC) and the bill's fixed charges, worked out by the invoices' DocumentCalculator; TEJ declares one operation per VAT rate with the withholding taken on each rate's own TTC; the exports go per rate; L-614 follows on the same form.
- [2026-10-14 19:19] AGREED: An expense's tax amounts are computed and shown; the person may type the amount printed on the bill instead, accepted within 0.001 per tax line and recorded as typed, so the expense, the VAT summary and TEJ say what the bill says when its supplier rounded differently.
- [2026-10-14 19:19] AGREED: A new expense offers the company's fixed charges (the stamp) unticked: the person ticks the stamp when the bill shows it, so no receipt is charged a stamp it does not carry.
- [2026-10-14 19:42] ASSUMED (review): The expenses CSV gains a fixed_amount column after tax_amount, the stamp a bill carries, so amount_net + tax_amount + fixed_amount is amount_gross. Alternatives: fold the stamp into tax_amount (hides it from VAT reconciliation); no column (the gross no longer adds up).
- [2026-10-14 19:42] ASSUMED (review): The purchases journal writes one row per tax a bill carries, with a base column, laid out as the sales journal (tax_code, tax_rate, base, tax, then the bill's net and gross); a fixed charge's row has no rate and no base, and the VAT summary leaves it out. Alternatives: one row per bill with the first rate (loses the second rate's deduction); new columns per rate (unbounded).
- [2026-10-14 19:42] ASSUMED (review): A revision's audit names the bill's fields as the API does: lines, documentTaxComponentIds, printedTaxes, field names only. Alternative: one internal 'amounts' field (names nothing a person sent).
- [2026-10-14 19:42] ASSUMED (review): The demo data enters every fourth expense of a taxed vendor as a bill at two rates: in Tunisia 70 % at TVA 19 % with FODEC and 30 % at TVA 7 % plus the stamp, in France 60 % at TVA 20 % and 40 % at TVA 10 %. Alternative: one fixed sample bill (seen once, on one page).
- [2026-10-14 19:42] ASSUMED (review): An expense list page reads its rows' lines and taxes in two statements whatever its size, so the page costs 14 statements (12 before); one join of both collections would multiply their rows. Alternative: leave the bill off the list rows (the list would answer a thinner row than the page).

## Sources read

- docs/fiscal/TN.md § 3 (FODEC 1 %, enters the VAT base), § 4 (stamp 1.000 TND per invoice, outside every base), § 5
  and § 5a (withholding 1 % from 1000 TND VAT included; the stamp in neither the threshold nor the base, the preset's
  choice).
- docs/research/tax-data-tunisia.md § 2.2, « A bill with several taxes » (added for this plan, from the TEJ cahier des
  charges of September 2026 and its XSD): one certificate, several `Operation`s, one VAT rate each;
  `MontantTTC = MontantHT + MontantTVA`; no place for FODEC or the stamp; `TaxeAdditionnelle` is the VAT withholding.

## What is wrong today

`Expense` holds ONE percentage tax (`tax_component_id`, `tax_rate`, `tax_amount`): a bill at 7 % and 19 %, or with
FODEC, or with the stamp, cannot be entered as printed, so its gross, the withholding, the amount paid, the VAT
summary's deductible VAT and the TEJ figures all differ from the paper.

## Formal Plan (approved 2026-10-14 19:19)

The expense takes the invoice's shape for its money, through the same `DocumentCalculator`, so a tax kind, a rate,
FODEC entering the VAT base and the stamp mean the same on both sides (country support stays data).

1. **API, the expense's amounts** (`Module/Expenses`): an expense has one or more amount lines, each a net and the
   percentage taxes on it (a VAT rate, FODEC), and the fixed charges of the bill (the stamp). Figures from
   `DocumentCalculator` at the company's scale; a tax may be given as printed (see the open question on computed or
   printed amounts). Stored per tax in a new `expense_tax` table (component, code, kind, rate, base, amount, whether
   it was typed), `CompanyOwned`, and as totals on `expense` (`amount_net`, `tax_amount`, `fixed_amount`,
   `amount_gross`). An expense's taxes refuse a `withholding_total` component: withholding stays on the payment.
   Migration backfills one line from today's `amount_net` and `tax_component_id`. API: `lines[]` (`amountNet`,
   `taxComponentIds`) and `documentTaxComponentIds` replace `amountNet` / `taxComponentId`; the answer carries
   `taxes[]` and the totals. Tests first: a 7 % + 19 % bill, FODEC under VAT, the stamp, refusals.
2. **Withholding and TEJ**: the TEJ cahier works each operation's withholding on its own TTC
   (`MontantRS = MontantTTC * TauxRS`), so the payment withholds per VAT rate, on that rate's base plus its VAT, and
   the expense's withholding is the sum; the threshold stays on the whole gross less the fixed charges (TN.md § 5a:
   the stamp is in neither). The certificate carries one `Operation` per VAT rate (`MontantHT` its base with FODEC
   inside it, `MontantNetServi` its TTC less its withholding); `TotalPayement` sums them. The stamp is declared
   nowhere, so what the supplier is handed (gross less withholding) exceeds `TotalMontantNetServi` by the stamp:
   said in the screen's TEJ figures. XML checked by the existing `TejDeclarationSchemaTest` (the schema read from
   `TEJ_XSD_DIR`, never in the tree).
3. **Exports and reads**: `PurchaseEntry` carries its taxes, so the VAT summary's purchases columns are per rate;
   the purchases journal and the expenses CSV write one row per tax with the net and gross on the expense's first
   row. `ExpenseSearch` / `DoctrineExpenseRepository` (gross filters and sort) and `SummarizeExpenses` read the new
   totals; `ManageExpenses`'s threshold check reads the gross less fixed charges.
4. **Web**: the expense form edits the lines (net + taxes each) and offers the company's fixed charges; the list and
   the detail show every tax. Specs, `web/e2e/expenses.spec.ts` (reads cells by `data-column`), an e2e entering a
   7 % + 19 % bill with the stamp, a screenshot before and after.
5. **Around it**: the demo expenses (`DataFixtures/DemoCatalogue`, `DemoCompanies`) get a two-rate bill with the
   stamp; `ScaleGenerator`'s left-out list names `expense_tax` with `expense`; company erasure removes it
   (`DataErasureTest`); TN.md § 5a, FR.md (several VAT rates on a receipt), SPEC § 8 row.

Out of scope, following it: L-614 (typing the bill's total and deriving the net), on the same form once lines exist.

Verify: functional tests per figure above, the schema test with `TEJ_XSD_DIR`, a sabotage per guarantee, the gates,
CI. Rollback: the migration's `down()` keeps the first line's tax.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Expense amount lines and taxes through the calculator | L | done | d1eddad0 | api/src/Module/Expenses/** api/migrations/** |
| 2 | Withholding base without fixed charges; TEJ operation per VAT rate | M | done | d1eddad0 | api/src/Module/Expenses/** |
| 3 | Purchase entries, VAT summary and CSV per tax | M | done | d1eddad0 | api/src/Module/AccountingExport/** api/src/Module/Expenses/** |
| 4 | Web form, list and detail; e2e | L | done | e57149ab | web/src/app/expenses/** web/e2e/** |
| 5 | Docs and SPEC row | S | done | - | docs/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
- Accountant: FODEC inside `MontantHT` on TEJ; the stamp left out of TEJ and of the withholding base.
### Fragile
### Known issues
