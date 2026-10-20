#!/usr/bin/env bash
# SPDX-License-Identifier: AGPL-3.0-or-later
# Whatever a person types and has not saved holds the page: leaving it asks, and reloading or closing the tab is held
# by the browser's own question (`UnsavedChanges`). A form that does not count its unsaved fields is left without that
# hold, and a reload loses what was typed with no word: the default values of the settings page went that way in front
# of a shop. A site is a component of the index, specs excluded, whose class or template edits something (a form group or
# control, a descriptor form, ngModel, an input or change handler). It counts through `unsavedChanges(`, `listRecord(` or
# `UnsavedChanges).declare(`; a dialog (it injects `MAT_DIALOG_DATA` or `MatDialogRef`) closes with its question and
# leaves nothing on the page behind it; any other is listed below with its reason. Usage: unsaved-guarded.sh [--root DIR]
set -uo pipefail
export LC_ALL=C
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
[[ "${1:-}" == "--root" && -n "${2:-}" ]] && root=$2
# Every editing component counts today but these; the floor is what the tree holds, so losing a page's count reds.
floor=38
declare -A exempt=(
  [web/src/app/account/notification-choices.ts]="each switch is saved the moment it is turned"
  [web/src/app/accounting-export/accounting-export-page.ts]="a period chosen for a file to download, nothing saved"
  [web/src/app/auth/login-page.ts]="signed out: nothing of the company to lose"
  [web/src/app/auth/two-factor-page.ts]="signed out: a code typed once, valid seconds"
  [web/src/app/company/company-security-page.ts]="its one switch is saved the moment it is turned"
  [web/src/app/company/modules-page.ts]="each switch is saved the moment it is turned"
  [web/src/app/customer-screen/customer-screen-page.ts]="the words typed to leave the customer screen, never saved"
  [web/src/app/customers/customer-statement.ts]="a period chosen to read a statement, nothing saved"
  [web/src/app/data-erasure/data-erasure-page.ts]="parts chosen for an erasure the page then asks to confirm, nothing saved"
  [web/src/app/delivery-notes/delivery-note-lines.ts]="the lines of a delivery note, counted by its page through its form"
  [web/src/app/expenses/expense-bill.ts]="the bill of an expense, counted by its page through its form"
  [web/src/app/fiscal/name-language-add.ts]="a language picked to show its field, which the names form counts"
  [web/src/app/import/import-page.ts]="a file and its headings read again from the file; nothing is written before the import runs"
  [web/src/app/inventory/place-contents.ts]="a search over what a place holds"
  [web/src/app/inventory/receipt-placement.ts]="the rows of a shared delivery, counted by the stock page that holds them"
  [web/src/app/inventory/stock-movements-page.ts]="a search over the movements"
  [web/src/app/invitation/accept-invitation-page.ts]="signed out: an invitation accepted in one step"
  [web/src/app/invoices/invoice-lines.ts]="the lines of an invoice, counted by its page through its form"
  [web/src/app/pairing/phone-scanner-page.ts]="a phone lent as a scanner, holding nothing of its own"
  [web/src/app/password-reset/forgot-password-page.ts]="signed out: one address typed and sent"
  [web/src/app/password-reset/reset-password-page.ts]="signed out: a password typed and sent"
  [web/src/app/products/price-calculator.ts]="works a price out into the product form, which counts it"
  [web/src/app/products/product-labels-page.ts]="what to print on labels, nothing saved"
  [web/src/app/products/product-labels-sheet-page.ts]="what to print on a sheet of labels, nothing saved"
  [web/src/app/purchases/purchase-order-lines.ts]="the lines of an order, counted by its page through its form"
  [web/src/app/recurring-expenses/recurring-expenses-page.ts]="its forms are dialogs, which close with their question"
  [web/src/app/reports/report-page.ts]="a period and filters chosen to read a report, kept in the address"
  [web/src/app/shared/form/colour-field.ts]="a field of a form, counted by the page that owns the form"
  [web/src/app/shared/form/descriptor-form.ts]="draws a form a page counts"
  [web/src/app/shared/form/file-drop.ts]="a field of a form, counted by the page that owns the form"
  [web/src/app/shared/form/pick-field.ts]="a field of a form, counted by the page that owns the form"
  [web/src/app/shared/form/select.ts]="a field of a form, counted by the page that owns the form"
  [web/src/app/shared/i18n/language-menu.ts]="the screen's language, applied the moment it is chosen"
  [web/src/app/shared/legal/legal-text.ts]="the language a legal text is read in"
  [web/src/app/shared/list/data-list.ts]="a list's search and choices, kept in the address"
  [web/src/app/shared/list/list-filter-panel.ts]="a list's filters, kept in the address"
  [web/src/app/shared/scan/camera-view.ts]="the camera a scan reads through"
  [web/src/app/shell/settings-area.ts]="a search over the settings"
  [web/src/app/signup/finish-signup-page.ts]="signed out: an account finished in one step"
  [web/src/app/signup/signup-page.ts]="signed out: an address typed and sent"
  [web/src/app/support-access/support-access-page.ts]="a length chosen for access granted in one step"
)
edits='buildFormGroup\(|new FormGroup|new FormControl|<app-descriptor-form|ngModel|\(input\)=|\(change\)=|\[formControl\]|formControlName'
counts='unsavedChanges\(|listRecord\(|UnsavedChanges\)\.declare\('
mapfile -t sources < <(cd "$root" && git ls-files -- 'web/src/app/*.ts' | grep -v '\.spec\.ts$')
status=0
counted=0
dialogs=0
declare -A editing=()
for source in "${sources[@]}"; do
  grep -q '@Component(' "$root/$source" 2>/dev/null || continue
  files=("$root/$source")
  [[ -f "$root/${source%.ts}.html" ]] && files+=("$root/${source%.ts}.html")
  grep -qE "$edits" "${files[@]}" || continue
  editing[$source]=1
  if grep -qE "$counts" "${files[@]}"; then
    counted=$((counted + 1))
    [[ -n "${exempt[$source]:-}" ]] && {
      printf 'unsaved-guarded: FAIL — %s counts its unsaved fields and is still listed as exempt: drop it from the list\n' "$source"
      status=1
    }
  elif grep -qE 'MAT_DIALOG_DATA|MatDialogRef' "$root/$source"; then
    dialogs=$((dialogs + 1))
  elif [[ -z "${exempt[$source]:-}" ]]; then
    printf 'unsaved-guarded: FAIL — %s edits something and never counts what is unsaved: count it through unsavedChanges, or list it here with its reason\n' "$source"
    status=1
  fi
done
for source in "${!exempt[@]}"; do
  if [[ -z "${editing[$source]:-}" ]]; then
    printf 'unsaved-guarded: FAIL — %s is listed as exempt but is no editing component of the index: drop it from the list\n' "$source"
    status=1
  fi
done
if ((counted < floor)); then
  printf 'unsaved-guarded: FAIL — %d components count their unsaved fields, fewer than the %d the tree holds: one stopped counting, or the patterns no longer find them\n' "$counted" "$floor"
  status=1
fi
((status)) && exit 1
printf 'unsaved-guarded: OK — %d components hold the page while something is unsaved (%d dialogs, %d exempt with a reason)\n' "$counted" "$dialogs" "${#exempt[@]}"
