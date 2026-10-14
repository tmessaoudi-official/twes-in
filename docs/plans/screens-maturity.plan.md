# screens-maturity Plan

The goal after readiness, started on its own once the threat-model goal and P10 are pushed. A senior design-system, UI and
UX sweep of every screen, a per-screen inventory of what it lacks (data, actions, bulk actions, conversions, workflows,
recurrence, filters, states), every promise already made but never built, and then every screen built to 100 %. Once the
level is reached it is kept by construction: every future screen is held to it without redoing this work.

## Decisions Log
- [2026-10-13 00:17] AGREED: goal shape: audit rounds first (5 to 20, stop by saturation, ask at 20) producing one unified artifact and a ruled proposal list, then build screen by screen; once reached, every future work meets that level automatically (developer).
- [2026-10-13 00:17] AGREED: screen texts split per feature: one translation file per language per feature, loaded with its screens, each with its own gzip budget measured before and after; supersedes one hashed file per language (developer).
- [2026-10-13 00:17] AGREED: the screen maturity checker's form is decided after the audit (developer).
- [2026-10-13 00:17] AGREED: starts on its own once the threat-model goal and P10 are pushed, stopping only for the developer's rulings on proposals (developer).
- [2026-10-13 00:17] AGREED: Gérer les pages légales becomes its own tab in Gérer la plateforme; both duplicated cards (Vue d'ensemble, Demande) go, the overview keeps a one-line status linking to it (developer).
- [2026-10-13 00:17] AGREED: « Textes », editable screen texts: overrides layered platform then company, only the differences served beside the shipped file, placeholders, plural syntax and length checked on save, plain text only, fiscal and legal mentions locked, search, preview in place, reset, CSV import and export; a promised feature of this goal (developer).
- [2026-10-13 16:43] AGREED: The big review starts now, before the threat model's W4-W7 and P10 (re-orders the 00:17 ruling): land 3e, freeze that commit, run the read-only rounds on it, widened to code, tests, readiness and fiscal rules; W4-W7 resume while the developer rules on the proposals (developer).
- [2026-10-13 16:43] AGREED: Danger: red, a lock icon and a dialog naming what is lost for définitif actions only; delete my account, close a company and erase its data also ask to type the company name or a word; corrigeable (fiscal) actions keep the primary colour with a consequence dialog; one danger button component, and a gate refusing a delete/remove/erase handler not declared destructive (developer).
- [2026-10-13 16:52] AGREED: The review's checklist is the 16-lens question bank (can I, should I, clear, findable, mobile first, bad network, business correctness, technical correctness, lifecycle, what comes next, feelings, accessibility and language, proof, operations, promises, trade fit) plus large data loads on every screen; more lenses proposed for ruling (developer).
- [2026-10-13 16:52] AGREED: The feelings vocabulary is the 21 listed (danger, consequence, undoable, safe to try, locked, needs attention, urgent, sensitive, someone else is here, working, done, failed, not allowed, not yet, changed since you looked, first time, trusted, uncertain, money direction, loss, connection lost), each with one look and one wording ruled before any build; more proposed for ruling (developer).
- [2026-10-13 16:52] AGREED: Bad network standard: survive a short drop (drafts, scans and forms kept, unsent shown as unsent, retries safe); issuing waits for the network and says so; offline-first refused for fiscal documents (gapless numbering, hash chain) (developer).
- [2026-10-13 16:57] AGREED: Lenses 17-33 added (large data, time and calendar, the likely slip, wrong context, interruptions, why is this number this, insider misuse, strange input, first ten minutes, leaving, what leaves the app, once a month, the fast expert, the support call, legal proof, running cost, would we know): 33 lenses (developer).
- [2026-10-13 16:57] AGREED: Feelings: the 19 added (which company am I in, all clear, progress, closure, countdown, waiting on someone else, unusual, suggestion, forgiveness, what the customer will see, costs something, partly down, demo not real, training mode, it's mine, celebration, private, who did it, final soon): 40, grouped into families so they never mean 40 colours (developer).
- [2026-10-13 16:57] AGREED: Review mechanism: round 0 builds the instruments (inventory of screens, routes, actions, filters and promises; a gallery by role, device and language; large-data timings; axe; a route x role matrix); rounds judge that evidence cell by cell with coverage measured, carrying every finding forward; data loss, security and wrong money fixed at once; the developer rules the vocabulary and rubric after round 1; one blind round without the list confirms saturation; then fix by kind; the instruments become the maturity checker and gates. The design system's components are judged themselves too (developer).
- [2026-10-13 16:59] AGREED: Final lists adopted as the closed starting set: 52 lenses (34-52: components themselves, the 5-second test, numbers on screen, responsiveness felt, one product, sound and touch, the real place, reading level, colour-blind and cognitive load, company size, data decay, notification hygiene, find anything, setting side effects, when something else is down, honesty, one word per action, help that's true, seen from outside) and 58 feelings in families; a round adds one only from evidence and reports it (developer).
- [2026-10-13 16:59] AGREED: Go: 3e lands first with the first danger button (red, lock icon, a dialog naming what is lost, a typed word for account deletion and company closing), then /compact, then round 0; certification by advisor() only at the gates, the three-lens panel once at the end of the rounds on a frozen commit (developer).
- [2026-10-13 18:16] AGREED: While a pushed commit's CI runs, work continues on the next step and commits stay local; no push until that run reports (a push cancels it); green carries on, red stops the work for a fix on top (developer).
- [2026-10-13 18:20] AGREED: Round 0 order: the inventory and the route x role matrix first, from code by read-only agents; then the gallery and axe in one Playwright pass on a worktree stack; the scale-data timings last.
- [2026-10-13 18:34] ASSUMED (review): The gallery's language axis is fr, en and a right-to-left probe (French texts served under <html dir="rtl">) instead of fr / ar, because twes-in ships no Arabic yet (SUPPORTED_LANGUAGES fr, en; SPEC 7 2026-09-09 'Arabic later'): the probe measures how far the layout is from Arabic. Alternatives: picture fr only and record ar as a gap; build Arabic texts first.
- [2026-10-13 19:17] ASSUMED (review): L-066 (the ruled step-up list, 2026-09-21 19:05) is checked where a person acts, in the API processors after the company and permission checks: changing the company's IBAN or BIC, inviting or removing a member, creating, revising or deleting a role; the use cases stay callable by the fixtures and the platform. A revision asks only when the bank details differ from what is stored; a role revision asks whatever it changes. Alternatives: in the use cases (breaks the fixtures and the platform's owner invitation); a role rename without a proof.
- [2026-10-13 19:17] ASSUMED (review): A vendor's IBAN is outside the ruling's words (the company's own bank details) and is not given a step-up; asked of the developer with the round-0 security questions. Alternatives: include it now.
- [2026-10-13 20:25] AGREED: Order: round 0 to its end (gallery role passes, measured API matrix, large-data timings), then L-066 and the five step-up rulings of 20:21 land on master under the 16:57 fix-now ruling, then round 1 on that frozen commit (developer).
- [2026-10-13 23:57] AGREED: Order after the step-up work (landed, CI green at 1a5f5e8a): the paratest trial first (API suite timings before and after, kept only if faster and green), then round 1 on the frozen commit; changed-only runs and one-container sabotage after round 1 (developer).

## Formal Plan

**A. Rounds (5 to 20).** Each round takes the brief, SPEC § 1–8, CLAUDE.md, the expertise packs and the previous round's
output, widens it and writes to `var/claude/maturity/round-<n>.md`; at most five read-only agents at once, each returning one
line. Coverage per screen and per role (owner, clerk, accountant, operator; phone, till, desktop):
- what it shows and edits; single, bulk and conversion actions; workflows to and from other screens; recurrence;
- filters, sort, search, saved views, import, export, print/PDF, mails;
- empty, loading, error, offline states; undo and preview rules; permissions and module switches; live reload;
- phone first, RGAA, fr/en/ar with Arabic right to left, keyboard and the palette, guided tours, microcopy;
- the design system: components, tokens, spacing, density, consistency across screens;
- performance on a weak phone; what the customer sees (PDF, mails, customer display);
- competitors studied by their behaviour only (Pennylane, Sellsy, Zoho, Odoo, Tunisian tools), never their source;
- a bidirectional inventory of every AGREED item in SPEC § 7 against the code: promised and never built (recurring
  expenses among them) is a finding.
Stop rule: a round that adds no new category and under about 5 % new findings; at round 20 without it, ask the developer.

**A, widened (2026-10-13 16:43–16:59).** Also code, tests, the readiness work and the fiscal rules; on 3e's commit, frozen.
- **Round 0, the instruments**, kept for the checker: an inventory of every screen, route × method, action, filter and
  AGREED § 7 item (both ways); a gallery per screen × role × 360 px / desktop × fr / ar, one feature at a time; every list
  timed on `make scale-data`; axe on every screen; a route × role matrix. Coverage = cells answered with evidence.
- **Rounds** judge that evidence cell by cell (screen × role × device × lens), each given every finding so far with a
  stable id and asked for new ones only; data loss, security and wrong money are fixed on master at once and marked.
  After round 1 the developer rules the feelings' looks and wordings and the rubric; at apparent saturation one blind
  round without the list (over 5 % new: continue). Then the developer rules, and fixes go by kind: a shared component,
  a gate, a lint rule or a test, and the lesson in the expertise pack. A finding is closed when its kind is.
- **52 lenses**, each a question asked of every screen: 1 can I do this (role, module, plan, state; why not and who can;
  both walls) · 2 should I (law, preset or setting; consequence; kind; journal; who is told) · 3 clear · 4 easy to find ·
  5 mobile first · 6 a short drop · 7 business correctness · 8 technical correctness · 9 lifecycle · 10 what comes next ·
  11 feelings · 12 accessibility and language · 13 proof · 14 operations · 15 promises · 16 trade fit · 17 large data ·
  18 time and calendar · 19 the likely slip · 20 wrong context · 21 interruptions · 22 why is this number this ·
  23 insider misuse · 24 strange input · 25 first ten minutes · 26 leaving · 27 what leaves the app · 28 once a month ·
  29 the fast expert · 30 the support call · 31 legal proof · 32 running cost · 33 would we know · 34 the components
  themselves (states, sizes, schemes, RTL, contrast, tokens, one canon) · 35 the 5-second test · 36 numbers on screen ·
  37 responsiveness felt · 38 one product · 39 sound and touch · 40 the real place · 41 reading level · 42 colour-blind
  and cognitive load · 43 company size · 44 data decay · 45 notification hygiene · 46 find anything · 47 setting side
  effects · 48 when something else is down · 49 honesty · 50 one word per action · 51 help that's true · 52 seen from
  outside.
- **58 feelings**, in families so they never mean 58 colours: danger (définitif), consequence (corrigeable), undoable,
  safe to try, locked, needs attention, urgent, sensitive, someone else is here, working, done, failed, not allowed, not
  yet, changed since you looked, first time, trusted, uncertain, money direction, loss, connection lost, which company
  am I in, all clear, progress, closure, countdown, waiting on someone else, unusual, suggestion, forgiveness, what the
  customer will see, costs something, partly down, demo not real, training mode, it's mine, celebration, private, who
  did it, final soon, protected, prove it's you, calm in failure, not your fault, legal certainty, fair, familiar,
  relief, respect for time, under control, confidence before committing, healthy, money tight soon, done for today,
  new period, team, welcome, curiosity.
- Agents: at most five at once, read-only, each writing to `var/claude/maturity/` and returning one line.

**A out.** One unified artifact replacing the scattered pages (readiness, proposals, marking, mockups): the vision, the
maturity rubric (levels per dimension), each screen's level and gaps, design fixes, promised items, and every proposal
for the developer to rule on a marking page; the rules and goals that contradicted each other on the way, and how the
stop was reached and on what criteria.

**B. Build.** Screen by screen to the agreed level, from the ruled proposals only, test first; then the maturity checker
in the form decided after the audit, so every future screen meets the level automatically.

Known before round 1:
- « Gérer les pages légales » appears twice in « Gérer la plateforme » (Vue d'ensemble and Demande, one section copied):
  it becomes a tab of its own; the overview keeps a one-line status linking to it.
- « Mon compte → Notifications » lists every company in one run: to be restructured (grouped by company, one switch per
  kind across them).
- « Textes »: editable screen texts, as ruled below.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 0 | Round 0: the instruments (inventory, gallery, timings, axe, route × role matrix) | L | done | 5f0b6440 | scripts/** var/claude/maturity/** |
| 1 | Rounds to maturity + unified artifact | L | todo | - | var/claude/maturity/** |
| 2 | Developer rules the proposals | M | todo | - | - |
| 3 | Screen texts split per feature | L | todo | - | web/** scripts/gates/** |
| 4 | Build the ruled proposals screen by screen | L | todo | - | web/** api/** |
| 5 | Maturity checker | L | todo | - | scripts/** .claude/** |
<!-- /progress-block -->
### Blocked
### Needs input
### Needs research
### Fragile
### Known issues
