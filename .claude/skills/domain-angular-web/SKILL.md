---
name: domain-angular-web
description: Use when a task touches the twes-in web app (Angular signals/zoneless, Material + Tailwind, descriptor lists/forms, a11y with axe, Playwright e2e, fr/en i18n, shell/nav, scan and till UI). How an expert works here - rules, tools, acceptance, failure modes, evidence.
---
Review date: 2026-10-03 12:16   Validation mode: advisory   Core: .claude/rules/expertise-core.md

## Roles and mental models
- **Owner-manager UX (shop owner first)**: home answers money owed, overdue, what next; calm SaaS on restyled Material+CDK, comfortable density, status colour carries meaning (six fixed tones). Phone is first-class: cards under 600 px  [SPEC §7 2026-09-13/16, via r2-decisions]
- **Descriptor architect**: screens are data. Every list = `DataList` over a `ListDescriptor`, every form = `DescriptorForm` over a `FormDescriptor`, actions declared once (`RowAction`, `DocumentAction`); a new control type goes into the descriptor, not into one screen  [r2-decisions]
- **Accessibility auditor (RGAA beyond WCAG 2.1 AA)**: every control named, tooltip on every control whose full name is not visible, keyboard path for every pointer gesture (WCAG 2.5.7), contrast certified by a real scan  [r2-decisions 2026-09-26]
- **Till cashier ergonomics**: fast repeated scan, no pointer-only path, no modal surprise; browser-only hardware (no local agent)  [r2-research-ops 4]
- **Form-first, gesture-as-accelerator**: dragging/resizing/map drawing only ever writes into a form; nothing reachable only by pointing  [r2-decisions stock map]

## Standards, rules (rule | applies when | source | last checked)
| Rule | Applies when | Source | Checked |
|---|---|---|---|
| Layering component -> facade (`*-facade.ts`, `xSignal` private, `x` readonly, always `busy` + `error` code signals) -> adapter (`*-api.ts`, only importer of `HttpClient` and `api/types.gen`, `guard()` maps HTTP to the feature's error union) -> `*-types.ts`; `shared/` imports no feature | any new screen/feature | ESLint `no-restricted-imports` (r2-tech 7, 12) | 2026-10-02 |
| Error copy is a code -> `<feature>.errors.<code>` translation key, never raw API text; `role="alert"` for errors | any error state | r2-tech 2b, 8-14 | 2026-10-02 |
| OnPush on every component (lint error), selector `app-` kebab, directives `app` camel; `protected readonly` template members; data-testid `<entity>-<action>` / `field-<name>` | new component | eslint config (r2-tech 7, 3) | 2026-10-02 |
| TS is NOT `strict` but code is written null-safe; `noPropertyAccessFromIndexSignature` is on (`x['key']`) | new TS | tsconfig (r2-tech 1, 8-13) | 2026-10-02 |
| Colours only from tokens: none literal outside `shared/theme`, no inline style; accent roles `accent`, `on-accent`, `accent-soft`, `accent-text`; filled-button hover/focus layers are the OPPOSITE of the ink (white 4.64:1 at rest fails AA at ~4.07 hovered) | any styling | design-tokens gate; SPEC §7 2026-09-23/26 | 2026-10-02 |
| Icons: only names declared in `shared/icons/icons.ts` (font cut to 117 icons at build by `subset-icons.mjs`); icon-only button needs `appLabel` | any icon | icons-declared + icon-buttons-named gates | 2026-10-02 |
| Outcomes via `Feedback` toasts (bar after 300 ms, "taking longer" 8 s, unreachable notice with backoff); one contextual suggestion may be the toast's single button | save/create/delete result | outcomes-as-toasts gate; SPEC 2026-09-21 | 2026-10-02 |
| "Undo first, preview the rest": undoable actions ask nothing (toast/history); irreversible/fiscal/other-people actions get a dialog naming consequence and an `ActionKind` (corrigeable, definitif); `RowAction.confirm` is a function of the row; a confirm is never a disabled switch | any action | SPEC §7 2026-09-21/26 | 2026-10-02 |
| `RecordBar` Save inert until the form differs from the API's last answer (not Angular `dirty`); create-then-navigate calls `UnsavedChanges.savedAndLeaving()`; `beforeunload` only while unsaved > 0 | any editor | SPEC §7 2026-09-21 | 2026-10-02 |
| Invalid submit shows all errors and focuses the first invalid field; format error repeats the field's own hint (Material hides it while error shows) | any form | SPEC §7 2026-09-14/22 | 2026-10-02 |
| Lists: real column width (default 160 clamped 48-960), table scrolls inside its container never the page, whole row opens via a real link, 1-2 frequent row actions visible, rare/destructive behind a menu, cards < 600 px, rows-per-page always reachable (one key for all lists), prefs under `presentation.list.<id>` | any list | SPEC §7 2026-09-13/21 | 2026-10-02 |
| Shell by window class: <600 bottom bar (first four + Plus), 600-1199 icon rail, >=1200 labelled rail; `[` folds (>=1200 only), `]` the settings list; the shell is `h-full` and the PAGE scrolls inside its panel; the folded rail itself never scrolls | layout work | SPEC §7 2026-09-16/26 | 2026-10-02 |
| Shortcuts: C create menu, N new doc, E next step, `/` or Ctrl K search; ignored while focus is in an input (`isTypingTarget`); every behaviour has a default and is configurable | keyboard work | SPEC §7 2026-09-21 | 2026-10-02 |
| i18n: ngx-translate, `web/public/i18n/{fr,en}.json`, parity spec `i18n-parity.spec.ts`; budget 60 KB gzipped per language; language menu names each language in itself with code ("FR · Francais"), never a flag; Inter lacks Arabic so Arabic/RTL (planned v1 bet, not built) needs a fallback face and is Unverified in code | any copy | r2-tech 1/8-14; SPEC 2026-09-13/16 | 2026-10-02 |
| Formats: fr-TN style ("2 975,000", dd/mm/yyyy, 24 h) only through `amount`/`day`/`moment` pipes; "what day is it in a zone" has ONE implementation (`shared/i18n/format.ts` `todayIn`); inputs accept comma or point, API stays point; web never decides by company country | numbers/dates | SPEC §7 2026-09-14/16 | 2026-10-02 |
| Settings: one `SettingsFacade` with a typed registry; unregistered keys neither read nor written; person's choice beats role beats company; presentation state is `presentation.*` | any preference | SPEC §7 2026-09-13 | 2026-10-02 |
| Scan: keyboard wedge first (`ScanWedge`, >=4 chars each <=30 ms then quick Enter; per-browser `twes.scan.gap` 10-100); in a field or dialog the scan stays where typed; document line uses scan LOOKUP (pack code = piece count, repeat scan increments draft line); camera via zxing-wasm | scan/till | SPEC §7 2026-09-21/22 | 2026-10-02 |
| Stored items: every cookie and `twes.*` key declared (Cookies page), storage only via a `*_STORAGE` token factory, no foreign-origin script, fonts self-hosted | any storage/asset | stored-items gate | 2026-10-02 |
| Unbuilt screens: "Bientot" chip + shared `/coming/<key>` page, entries in `COMING_NAV` leave in the change that builds them; a mockup element with no data is left out, never faked | planned features | SPEC §7 2026-09-16/21 | 2026-10-02 |
| A module declares `*_NAV`, `*_HOME`, `*_COMMANDS` together; a write calls `refresh()` not `load()`; read list cells by `[data-column="<id>"]` | module / list code | CLAUDE.md "Where things live" | 2026-10-02 |

## Till / POS browser constraints (all Chrome/Edge; no vendor SDK, no local agent)
- Printer/drawer/customer display work from the browser: WebUSB (Chrome/Edge 61+), Web Serial (89+), WebHID; all need secure context and a user device choice; Firefox/Safari will not catch up. A Windows vendor driver claims the USB printer exclusively and breaks WebUSB  [r2-research-ops 4, S: till-hardware §1]
- Network print = Epson ePOS-Print XML POST to the printer; Chrome >= 142 Local Network Access prompt applies  [Inferred, not tested on hardware]
- Drawer kick is an ESC/POS pulse via the printer (`ESC p m t1 t2`); receipts via MIT `receipt-printer-encoder`; Arabic is not shaped by printers, render to canvas as image  [Inferred]
- Customer display = `/customer-display` window over BroadcastChannel, screen 2 via `getScreenDetails()`; card terminals are server/cloud only (FR), none in Tunisia  [r2-research-ops 4]
- QZ Tray (LGPL) and Star/Epson proprietary SDKs are licence-REFUSED: implement protocols from docs  [r2-research-ops 4]
- Direction (recommendation, not a ruling): a `ReceiptPrinter` port with network (default) and USB implementations  [r2-research-ops 4]

## Tools of the trade (as configured)
- Angular ^22 (`@angular/build:application`, unit builder), standalone + `withComponentInputBinding`, no zone.js dep (Inferred zoneless), Material ^22 + material-color-utilities pinned exactly 0.4.0 (inlined in Vitest by `web/vitest.config.ts`), Tailwind ^4 `@theme inline` over `--mat-sys-*`/`--twes-accent*` (`src/tailwind.css`, `styles.scss`), Vitest + jsdom via `ng test`, Playwright ^1.63 + `@axe-core/playwright` (3 configs: e2e 127.0.0.1:8090, design and gallery on 4200; workers 1, retries 0), ESLint 10 flat config with `templateAccessibility`, Prettier (width 100, single quotes)  [r2-tech 1/7]
- Node 26 (`.nvmrc`=Dockerfile=engines, tied by version-pins gate); `make api-types` generates `web/src/app/api/` (gitignored)  [r2-tech 1]
- `/design` dev route (`canMatch: isDevMode()`) holds fixture screens, amounts are preformatted strings; `make gallery` shoots every screen  [SPEC 2026-09-13]
- Specs: facade specs fake the API with `vi.fn()` objects via `{provide: XApi, useValue}`; e2e helpers `signIn`, `inACompany`, `toast`, `rowAction`, `wcagViolations`, unique data `Date.now().toString(36)`, `try/finally` cleanup  [r2-tech 4]

## What "good" looks like (checkable)
- Loading, empty, error are three distinct states; error text comes from a key (grep: no raw `error.message` in templates).
- `wcagViolations(page)` is `[]` on key pages, in light AND dark, and the focus order reaches every action with the keyboard alone.
- 375, 800 and 1400 px: no page-level horizontal scroll (`e2e/overflow`), rail labels present, no clipped table.
- fr and en both render; no hardcoded colour/string; parity spec green; no literal colour (`design-tokens` gate green).
- Every new destructive control asks with a row-named message or offers undo; every new icon declared.
- Scan on a till screen: a code+Enter lands in the focused field or opens the product card, never lost.

## Classic failure modes (detection)
- Found only in a real browser at 1024/1400 px (folded rail, scrollbars, white-on-white door on a map): static tests cannot see it; look at it  [SPEC 2026-09-21/26]
- Gate checks leaves not branches / interpolated keys invisible to a grep gate (see CLAUDE.md Lessons): ask what sits one level up from what a gate enumerates.
- Scan debounce eats the code+Enter (old pick-field): drive a burst < 30 ms/char in a spec.
- Mid-tone accent fails AA on hover: compute the state layer contrast, not just the rest colour.
- Duplicated "today in zone" logic gives a 422 for browsers whose day runs ahead: grep `todayIn` is the only source.
- Status/row shown as "no X" under an outage banner (SPEC §8 row 45); `presentation.language` vs `User.locale` disagree (row 43): known issues, do not "fix" silently.
- Contradictions to not re-apply: quiet-ledger tones/direction, gear in top bar, `accent`/`green` tones: all superseded/withdrawn; the current settings fold default and `presentation.settings-list` moved several times, read SPEC before touching  [r2-decisions contradictions]

## Evidence surfaces (what counts as proof)
- `make gate-web` (runs `npm run gate` in the `web-tools` container, never on a host Node; `api:types`, `lint`, `format:check`, `test`, `build`) read from its own exit/`[OK]`, never a pipe; repo gates `scripts/gates/*.sh` (each with a test).
- `make e2e` through the real stack with axe; local timing is not evidence under load (see CLAUDE.md).
- Visible change: BEFORE and AFTER screenshots of the real rendered result (live `make up`, or `make gallery` desktop+phone, light+dark into `var/claude/gallery`); jsdom/green tests alone are incomplete. Non-visual change: state "no visual surface".
- Contrast and focus claims are certified only by an axe scan or a measured value, never by reading CSS.
- Uncertified by design: aria-disabled controls (axe skips contrast), Arabic/RTL, real printers/drawers (no hardware test).

## Reviewer lenses
1. Accessibility and keyboard (axe, tab order, names, tooltips, RGAA). 2. State correctness and descriptors (signals, forms, RecordBar, live updates, layering/lint). 3. Phone/till reality (breakpoints, scan flow, browser-API limits, real-browser look).

## Vocabulary
| Term | Meaning |
|---|---|
| facade / adapter | signal state holder / only HTTP + generated-type importer |
| descriptor | TS description of a list/form/action rendered by a generic component |
| tone | one of six fixed status colours |
| wedge | scanner emulating a keyboard |
| Bientot | "unbuilt" marker chip |
| corrigeable / definitif | action kinds: undoable / irreversible |

## Canonical sources
- angular.dev, material.angular.dev, tailwindcss.com/docs, playwright.dev, github.com/dequelabs/axe-core, ngx-translate.org [Unverified: not retrieved]; twes-in docs/SPEC.md §7 (dated UX rulings), CLAUDE.md, docs/research/till-hardware.md.

## Changes vs v2
- Dropped the 22-row gotcha table: it restated CLAUDE.md Lessons (loaded every session); kept a pointer.
- Added ~25 dated UX/design rulings (undo-first, RecordBar, shell breakpoints, shortcuts, scan wedge, lists, settings).
- Added till browser-hardware section from till-hardware research.
- Fixed v2 "i18n fr/en (Arabic planned)": Arabic/RTL is a v1 bet, Inter lacks Arabic, not built.
- Added superseded-direction warnings and the visual-evidence rule with uncertified dimensions.
