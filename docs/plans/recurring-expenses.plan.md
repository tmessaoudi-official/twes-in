# recurring-expenses Plan

SPEC § 8 row 179 (size L). Rulings: SPEC § 7 2026-10-11 01:24, 2026-10-15 17:39 and 17:46. Wiring map:
`var/claude/recurring-expenses/map.md` (HEAD 0dfc63a1).

## Decisions Log
- [2026-10-15 18:08] ASSUMED (review): a schedule stores its anchor with its own parameters (a day of the month 1–31 kept and clamped to shorter months, the month's last day, the nth weekday 1–4, the last weekday), so revising from a clamped day keeps the 31st — because deriving it from the first day lost month-end after a revise from the 28th of February. Alternatives: derive the anchor from the first day (today's behaviour, loses the 31st).
- [2026-10-15 18:08] ASSUMED (review): a recurring expense's draft copies the model's description, payee, vendor, category, lines with their taxes, charges, printed taxes and notes, dated on the occurrence's day; it drops the supplier's reference, the attachments and the payment — because each bill carries its own reference and receipt. Alternatives: copy the reference too; copy nothing but the amounts.
- [2026-10-15 18:08] ASSUMED (review): the shared Schedule and Recurrence come first and recurring invoices move onto them only in slice 4 with their migration — because their raw SQL, index and CHECKs name bare columns, and a shipped feature should not move before expenses ship. Alternatives: move both at once.
- [2026-10-15 20:27] ASSUMED (review): A model that names a vendor while Vendors is switched off is not copied: the schedule pauses and whoever writes expenses is told once (expense.recurring_stopped), as for a deleted model — because a new expense may name no vendor while Vendors is off, and copying it without its vendor would draft a bill from nobody. Alternatives: copy it without the vendor, its name kept as the payee in words; or keep drafting and skip only that field.
- [2026-10-15 20:27] ASSUMED (review): Slice 2 (API) is committed locally and pushed together with slice 3 (web), whose screen texts name the module, the bell's two kinds and the « À surveiller » row — because pushed alone, the Modules page and the bell would show raw keys on master and staging. Alternatives: push slice 2 with stub texts now.
- [2026-10-15 20:34] ASSUMED (review): The « Récurrentes » form offers all four anchors for a month or a year (« le 5 », « le dernier jour », « le 2e mardi », « le dernier vendredi »), the second choice shown only once a month or a year is picked; weekday and ordinal names come from Intl, not from screen texts — because the 17:46 ruling asks for flexible dates, the API accepts all four, and advisor() agreed (confident pick, unattended window). Alternatives: date and last day only; date only.
- [2026-10-15 20:34] ASSUMED (review): Screen wording taken from the rulings: module « Dépenses récurrentes » (§ 7 2026-10-11 01:24), tab « Récurrentes » and flag « Montant à vérifier » (§ 7 2026-10-15 17:46), action « Rendre récurrente » as the recurring invoices say it (one canon) — an objective pick, the rulings dictate it. Alternatives: « À vérifier » alone.
- [2026-10-15 21:55] ASSUMED (review): Recurring invoices move onto the shared schedule by a migration that writes each frequency as the schedule giving the same days (weekly a week; monthly and quarterly one and three months, yearly a year, each on the first day's date) and whose down() refuses, before changing anything, while any schedule none of the four frequencies says exists — because § 7 2026-10-15 17:46 widened recurring invoices to the same schedule and an issued plan must keep its days. Alternatives: keep frequency beside the schedule (two truths), or drop unsaid schedules on the way back (silent loss).
- [2026-10-15 21:55] ASSUMED (review): The schedule's words (schedule.*) are a texts part of their own, web/public/i18n/schedule/, opened into the root service by TextsParts.open before the recurring lists and the « Rendre récurrente » dialogs — because the global file has 298 B left of its 61,440 B budget and both the invoice and the expense screens read them. Alternatives: the global file (over budget), a copy in each page's part (two texts to keep equal).
- [2026-10-15 21:55] ASSUMED (review): A model that cannot be copied (credit note, deposit or cancelled invoice) is refused with reason not_copiable, and an unknown one with unknown_choice, the invoice's refusals named camelCase as the form names them (modelInvoiceId, startsOn, endsOn) — because refusals carry a stable reason a screen translates (§ 7 2026-09-18, 2026-09-22) and the recurring expenses already name theirs so. Alternatives: one invalid_value for both.
- [2026-10-15 21:55] ASSUMED (review): RequestedSchedule moves to Shared/Infrastructure/ApiPlatform with a ScheduleRequest interface both resources implement — because modules never call each other (ModuleBoundariesTest) and the two write the same flat fields. Alternatives: a copy in each module.
- [2026-10-15 21:55] ASSUMED (review): The recurring invoices list's « Quand » column shows the schedule as a sentence (« Tous les 2 mois, le dernier vendredi »), sorted by unit then interval — because the recurring expenses list already says it so, one canon per screen. Alternatives: keep the four frequency words (cannot say the new schedules).
- [2026-10-15 22:14] ASSUMED (review): The recurring invoices' screen words follow the schedule: the intro gives examples the form says (« tous les deux mois, le dernier vendredi tous les trois mois, chaque année le 31 octobre »), the first day reads « Premier brouillon au plus tôt le » with « Aujourd’hui ou plus tard. » as on the expense side, and the model's refusal names cancelled invoices beside credit notes and deposits — because the API refuses all three as not_copiable and the first draft is the first scheduled day from that day on. Alternatives: keep « les suivants gardent le même jour du mois » (no longer true).

## Formal Plan

1. Slice 1 — `api/src/Shared/Domain/Schedule` (unit day|week|month|year, every 1–99, anchor stored with its own
   parameters: a day of the month 1–31 kept and clamped, the month's last day, the nth (1–4) weekday, the last weekday)
   and the `Recurrence` embeddable (first day, last day, next index and day, drafted, paused; due, drafted, revise, pause).
   Recurring invoices are NOT moved onto it here (their raw SQL, index and CHECKs name bare columns): slice 4 does it.
   The revise case « monthly from 31 Jan, revised on 28 Feb to every 3 months » keeps the 31st (red first).
2. Slice 2 — `api/src/Module/RecurringExpenses/` (key `recurring_expenses`, depends on `expenses`, reuses
   `expense.read|write`), entity `recurring_expense` (model expense, `Recurrence`, variable amount, last expense), use
   cases mirroring Recurring with `DomainRule` refusals, hourly task, notification kinds `expense.recurring_drafted|stopped`,
   ports answered in `Module/Expenses/Infrastructure/RecurringExpenses/`; Expenses gains `Expense::copyOf`,
   `ManageExpenses::draftFromModel` (through its own validation: a retired tax or category returns null and the schedule
   pauses), `expense.amount_to_check` cleared on record, and an `ExpenseWatch` kind. Guards: ModulesTest,
   BackgroundWorkScopeTest, ScaleGenerator LEFT_OUT, emails yaml, tests switching `expenses` off. Demo dataset seeds one.
3. Slice 3 — web: `recurring-expenses/` feature, `shared/schedule/` fields and sentence, « Récurrentes » tab while the
   module is on, the flag, notification types; every key placed and measured against the 61,440 B budget first.
   One AskUserQuestion batch for the user-visible choices (anchors, copy rules, wording) before it lands.
4. Slice 4 — recurring invoices onto `Recurrence`: migration from `frequency` (weekly→week/1, monthly→month/1,
   quarterly→month/3, yearly→year/1, each on its first day's date), `down()` aborting on an unrepresentable row; API,
   web and `recurring.spec.ts` in one commit.
5. Slice 5 — move or skip one occurrence, both modules.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 1 | Shared Schedule + Recurrence | M | done | 3b7dfd9a | api/src/Shared/Domain/** |
| 2 | Recurring expenses API | L | done | 15d3a9db | api/src/Module/RecurringExpenses/**, api/src/Module/Expenses/** |
| 3 | Recurring expenses web | L | done | 9b919fde | web/src/app/recurring-expenses/**, web/src/app/shared/schedule/** |
| 4 | Recurring invoices widened | M | done | 6fe36b37 | api/src/Module/Recurring/**, web/src/app/recurring/** |
| 5 | Move or skip one occurrence (parked as row 331) | M | deferred | - | - |
<!-- /progress-block -->
### Blocked
### Needs input
- (Ruled § 7 2026-10-16 17:40: slice 5 is parked as row 331; row 179 closes with slices 1–4.)
- Copy rules, anchor set and wording (slice 3 batch).
### Needs research
### Fragile
- fr.json screen texts: 340 B left of 61,440 B gzipped (61,100 B at 6fe36b37); first page 999.35 kB of 1 MB (6fe36b37):
  a route importing a feature's module, not a constant file, puts it there (`shared/schedule/schedule-texts.ts`).
- The schedule's words (`schedule.*`) are a shared texts part (`web/public/i18n/schedule/`), opened into the root by
  `TextsParts.open`: a screen or dialog that says a schedule outside the two recurring lists opens it first, or shows keys.
### Known issues
