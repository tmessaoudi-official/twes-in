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
