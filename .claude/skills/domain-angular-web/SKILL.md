---
name: domain-angular-web
description: Use when a task touches the twes-in web app (Angular signals/zoneless, Material + Tailwind, descriptor lists/forms, a11y with axe, Playwright e2e, fr/en i18n, shell/nav, scan and till UI). How an expert works here - rules, tools, acceptance, failure modes, evidence.
---
Review date: 2026-11-02 22:30   Validation mode: advisory   Core: .claude/rules/expertise-core.md. the Lessons (CLAUDE.md and `.claude/rules/`) and gates load every session: pointed at, never restated.

## Roles and mental models
- **Owner-manager UX (shop owner first)**: home answers money owed, overdue, what next; status colour carries meaning (six fixed tones); phone first-class. Colour style « calme » / « vif » is the person's (new accounts start on vif), text size the device's  [Ruled: user 2026-10-31 23:04, SPEC:5758]
- **Descriptor architect**: screens are data (`DataList`/`ListDescriptor`, `DescriptorForm`/`FormDescriptor`); each record type declares its actions ONCE (`RecordAction`), read by page bar, row, phone card, sheet, selection bar, Ctrl K and keys  [Ruled: user 2026-10-11 08:26, SPEC:4998]
- **Accessibility auditor (RGAA beyond WCAG 2.1 AA)**: every control named, keyboard path for every gesture, focus flow designed, contrast certified by a scan, reflow at 320 px and 200 %  [Ruled: user 2026-10-14 12:05, SPEC:5184 (focus); 2026-11-02 00:32, SPEC:5764 (S5.9 200 % reflow); Observed: 3937e942b]
- **Till cashier ergonomics**: fast repeated scan, no pointer-only path, browser-only hardware (no local agent)  [r2-research-ops 4]
- **Form-first, gesture-as-accelerator**: dragging/drawing only writes into a form or a draft; nothing reachable only by pointing  [r2-decisions stock map; Ruled: user 2026-10-19 18:14, SPEC:5419]
- **Swappable identity**: a new look is a change of token VALUES, never of templates  [Ruled: user 2026-11-02 00:32, SPEC:5765]

## Standards, rules (rule | applies when | source | last checked)
| Rule | Applies when | Source | Checked |
|---|---|---|---|
| Layering component -> facade (`*-facade.ts`, signals, `busy` + `error` codes) -> adapter (`*-api.ts`, only importer of `HttpClient` and the generated types; `guard()` maps HTTP to the feature's errors) -> `*-types.ts`; `shared/` imports no feature; pages reach the API only through their facade; no import cycle; `SourceLayoutTest::CAP` per folder | any new screen/feature | ESLint (r2-tech 7, 12); [Ruled: user 2026-10-16 17:17, SPEC:5306]; [Observed: company-api.ts] | 2026-11-02 |
| OnPush everywhere, `app-` selectors, `protected readonly` template members, data-testid `<entity>-<action>` / `field-<name>`; TS not `strict` but null-safe, `noPropertyAccessFromIndexSignature` on | new component | eslint, tsconfig (r2-tech 1, 7) | 2026-10-02 |
| Design values are tokens in three places only: `tailwind.css` (type steps, weights, families, radii, shadows, easing; each namespace cleared, so an undeclared class draws nothing), `shared/theme` (colours), the `styles.scss` root block (`--twes-shadow-*`, `--twes-motion-*`, `--twes-ease-*`); Material reads the same steps; no literal, arbitrary value or style binding elsewhere; headings take their type role (h1 `text-title`, h2 `text-heading`/`text-section`) | any styling | [Ruled: user 2026-11-02 00:39, SPEC:5766]; design-tokens.sh; [Observed: 7ff3798a4, 18c4cec4f] | 2026-11-02 |
| Grounds are a neutral ramp tinted by calme/vif; anything meaning the accent takes its role (never Material `primary` for text or tints): accent text reads 4.6:1 on every ground it is written on (never the selected row or the highest container), an accent alone marking a state 3:1; accent presets stay off the status hues | colour work | [Observed: 459e513e6; plan ASSUMED 2026-11-02 07:28; presets 7fa4a4935]; S7.13 scheduled by [Ruled: user 2026-11-02 00:32, SPEC:5764] | 2026-11-02 |
| Text size 100 / 112 / 125 % per DEVICE (`twes.text-size`, never sent to the API), applied before first paint; control heights and the rail in rem; window class and saved column widths in effective px (`TextFactor`); `/customer-display` keeps its own size | any size, breakpoint or width | [Ruled: user 2026-10-31 23:04, SPEC:5758]; [Observed: 3937e942b, shared/theme/text-size.ts] | 2026-11-02 |
| Phone cards: a list declares slots (`cardTitle`, `cardSubtitle`, `cardAmount`, `cardStatus`): what a person recognises first, number and day under it, amount at the end; the head wraps, an unbroken value breaks inside the card; facets fold behind « Filtres (n) »; selection from a « Sélectionner » toggle in the list's own toolbar and a long press | any list on a phone | [Ruled: user 2026-10-11 08:26, 17:18, SPEC:5003, 5025]; [Observed: a19c97898, f9dec22e3, dcc605d26] | 2026-11-02 |
| Lists: every field a row holds is offered, hidden until picked, in the column chooser (label, format, who may see it); 1-2 frequent row actions visible, rare in « ⋮ », définitif never a row button; whole row a real link; table scrolls inside its box, never the page | any list | [Ruled: user 2026-10-21 18:26, SPEC:5590; 2026-10-11 08:26, SPEC:4998] | 2026-11-02 |
| Actions: frequency places them (next step the one filled button, last in the bar); effect decides asking: plain and annulable never ask (toast « Annuler »), corrigeable and définitif ask with an exact preview, définitif red and last in « ⋮ »; `RowAction.confirm` is a function of the row; « Bientôt » dashed on a wide bar, in « ⋮ » on rows and phones | any action | [Ruled: user 2026-10-11 08:26, SPEC:4998, 4999]; [Observed: list-types.ts] | 2026-11-02 |
| Words: « Annuler » is ONLY the toast's undo; void a draft « Abandonner le brouillon », a quote « Classer sans suite »; close « Fermer », discard edits « Rétablir » | any button label | [Ruled: user 2026-10-11 08:26, SPEC:5000] | 2026-11-02 |
| Save canon: every saving form counts unsaved fields into `UnsavedChanges` (route change and reload held; sign-out asks too); record/settings pages save from the bar beside the title (count, Enregistrer active once changed, Annuler les modifications); a tab saving its own form has its own bar; a settings page saves all changed keys in one batch, all or none | any editor | [Ruled: user 2026-10-20 23:14, SPEC:5546; 2026-10-14 16:18, SPEC:5197; 2026-10-17 03:44, SPEC:5341] | 2026-11-02 |
| Refusals: a wrong value shows once its field is left, then live until right; a save shows every error and focuses the first wrong field (Material's default ErrorStateMatcher); the browser checks with the API's own rule; a refused field says the import's own text under itself, via one web mapper of one problem+json shape; form reasons in the `form-refusals` texts part | any form | [Ruled: user 2026-10-27 20:57, SPEC:5694; 2026-10-15 06:51, SPEC:5232; 2026-10-16 17:17, SPEC:5308; 2026-10-26 10:43, SPEC:5670] | 2026-11-02 |
| Focus flow: on arrival (route, step, dialog, tab, sheet); after a refusal on the first field in error; back to the opener on close; next row after a removal; kept across saves and live reloads, never stolen by a toast; tab order follows the visual order, RTL too. Fine pointer: first field; coarse pointer: the title, but single-purpose typing pages focus their field; lists and scan pages focus the heading (search is « / ») | any screen | [Ruled: user 2026-10-14 12:05, SPEC:5184; 2026-10-15 20:00, SPEC:5276-5278] | 2026-11-02 |
| Error copy is a code -> `<feature>.errors.<code>` key, never raw API text, shown `role="alert"`; icons only those declared in `shared/icons/icons.ts`, an icon-only button named by `appLabel` | any error state, any icon | r2-tech 2b; icons-declared, icon-buttons-named gates | 2026-10-02 |
| Outcomes via `Feedback` toasts; a consequence beyond the subject said through `X-Effects` sayers (the Lessons (CLAUDE.md and `.claude/rules/`)) | save/create/delete result | outcomes-as-toasts gate; SPEC 2026-09-21 | 2026-10-02 |
| Shell by window class (effective px): <600 bottom bar with Material 3 pill indicators (56x32 px, icon over word, links too; first four + Plus), 600-1199 icon rail, >=1200 labelled rail; `[` folds (>=1200), `]` the settings list; the page scrolls inside its panel, the folded rail never | layout work | SPEC §7 2026-09-16/26; [Ruled: user 2026-09-21 15:20, SPEC:1439; 2026-10-11 09:01, SPEC:5006] | 2026-11-02 |
| Shortcuts: C create, N new doc, E next step, `/` search, Ctrl K; ignored in inputs; « Raccourcis à une touche » in Mon compte (on by default) turns every bare-letter key off (RGAA 12.10) | keyboard work | SPEC §7 2026-09-21; [Ruled: user 2026-10-11 08:26, SPEC:5004] | 2026-11-02 |
| i18n: ngx-translate; fr, en, ar; texts split per feature (`textsPart`), each language's screen texts within 61,440 B gzip 6; a language is DATA (`languages.json`, BCP 47 tag, direction, plurals), never `'fr' \| 'en'` in code; the whole interface turns right to left (logical CSS only); language menu: code and own name, never a flag | any copy or layout | [Ruled: user 2026-10-13 00:17, SPEC:5137; 2026-10-12 00:29, SPEC:5060; 2026-10-17 00:27/00:30, SPEC:5336-5337; 2026-09-16, SPEC:843] | 2026-11-02 |
| Arabic: Modern Standard Arabic keeping French fiscal terms; ships unreviewed, meaning defects fixed test-first, style waits for a reader; screen language is each person's, documents follow « Langue des documents »; ar keeps ar-TN number layout unless the company sets one | Arabic work | [Ruled: user 2026-10-17 00:30, SPEC:5337 (3); 2026-10-11 09:11, SPEC:5009 (videos, corroborates); 2026-10-20 19:16, SPEC:5531; 2026-10-18 21:20, SPEC:5377] | 2026-11-02 |
| Formats only through `amount`/`day`/`moment` pipes from the decimal STRING; one `todayIn`; comma or point typed, API keeps point; web never decides by company country | numbers/dates | SPEC §7 2026-09-14/16 | 2026-10-02 |
| Phone field: country box (flag, ISO, +code; full name in the list and accessible name) beside the local number, libphonenumber on both sides, full metadata never on the first page | any phone input | [Ruled: user 2026-10-29 16:53/18:30/20:20, SPEC:5723, 5727, 5730] | 2026-11-02 |
| Scan: keyboard wedge first (`ScanWedge`); a burst ending in Enter into a quantity or price field is a scan, never typing, said in a toast; an unusually large quantity or price asks; a repeat scan increments its draft line (a pack by its count); elsewhere a scan into a field or dialog stays where typed | scan/till | SPEC §7 2026-09-21/22; [Ruled: user 2026-10-14 12:57, SPEC:5188; 2026-09-23 09:30, SPEC:2460]; [Inferred: SPEC:2373 TAKEN OVERNIGHT, amended by 5188] | 2026-11-02 |
| Every dropdown is the shared Select (search past seven, a sheet under 600 px; grids and toolbar menus exempt); no native date (day format), colour (contrast-safe swatch) or file control (drop zone naming type, size); document figures come from the API's preview, never summed in the browser; margin, below-cost and price origin only in a folded « Rentabilité » tab, for `product.cost.read`, never while the customer screen is held (§ 8 row 221 doing) | form fields, line editors | [Ruled: user 2026-10-03 21:45, SPEC:4288, 4290; 2026-10-07 14:43, SPEC:4559] | 2026-11-02 |
| Settings via one `SettingsFacade` registry (`presentation.*`); stored items declared; unbuilt screens « Bientôt », no faked mockup data | prefs, storage, plans | SPEC §7 2026-09-13/16/21; stored-items gate | 2026-10-02 |
| One front end: no Flutter; installable offer (« Installer l'application ») with an API-written manifest, no service worker | packaging | [Ruled: user 2026-10-18 00:11, SPEC:5371; 2026-10-22 21:28, SPEC:5631] | 2026-11-02 |

## Till / POS browser constraints (all Chrome/Edge; no vendor SDK, no local agent)
- WebUSB, Web Serial, WebHID need secure context and a user device choice; a Windows vendor driver claims the USB printer and breaks WebUSB  [r2-research-ops 4, S: till-hardware §1]
- Network print via Epson ePOS-Print XML (Chrome >= 142 Local Network Access prompt); drawer kick `ESC p`; Arabic receipts rendered to canvas  [Inferred, not tested on hardware]
- Customer display = `/customer-display` over BroadcastChannel; QZ Tray and Star/Epson SDKs licence-REFUSED  [r2-research-ops 4]

## Tools of the trade (as configured)
- Angular ^22.2 zoneless (no zone.js; Inferred), `withComponentInputBinding`, Material ^22.2, ngx-translate ^18, Tailwind ^4.3, TypeScript ~6.0 (7 held back), Vitest ^5 + jsdom via `ng test`, Playwright ^1.64 + `@axe-core/playwright` on Chromium and WebKit (`@phone`), ESLint ^10  [Observed: web/package.json, app.config.ts 2026-11-02; Ruled: user 2026-10-21 14:07, SPEC:5577]
- All of it in the `web-tools` container (`make web-tools CMD=…`, already in `web/`), never host Node  [Source: CLAUDE.md "Run everything in Docker"]
- Specs fake the API with `vi.fn()` via `{provide: XApi, useValue}`; e2e helpers `signIn`, `inACompany`, `axeAtRest`, `toast`, `sendProving`  [r2-tech 4; Source: the Lessons (CLAUDE.md and `.claude/rules/`) 2026-10-13, 2026-10-19]

## What "good" looks like (checkable)
- Loading, empty (`EmptyState`: icon, title, create action), error are three states; error text from a key.
- `axeAtRest(page)` clean in light AND dark; keyboard reaches every action; focus lands where the focus-flow rule says.
- 320 px at 125 % and 200 % zoom: no page-level sideways scroll (`text-size.spec`, `list-fit.spec`, `phone-lists.spec`).
- fr, en and ar render; ar turns right to left with no physical style; budget gate green.
- A token swap changes every screen family (`design-tokens-swap` e2e)  [Observed: 7ff3798a4]

## Classic failure modes (detection)
- Found only in a real browser (folded rail, scrollbars, a list running 13 px sideways at 320 px and 125 %): look at it, at 125 % too  [Observed: f9dec22e3]
- A flex part is never narrower than its longest word: an unbroken value widens a phone card  [Observed: dcc605d26]
- An effect keyed on a session object re-runs on an unchanged re-read or on sign-out; keep unchanged parts as the same object  [Observed: 18c4cec4f; Source: the Lessons (CLAUDE.md and `.claude/rules/`) 2026-10-14]
- Mid-tone accent fails AA on hover/ground (white 4.64:1 at rest, 4.07 hovered): compute every state's contrast  [Observed: accent-theme.spec; SPEC:3176 DECIDED (revisit)]
- Gates check leaves not branches; interpolated keys are invisible to a grep gate  [Source: the Lessons (CLAUDE.md and `.claude/rules/`) 2026-09-20/21]
- Superseded, do not re-apply: one file per language (now per feature, SPEC:5137), Arabic after the pilot (SPEC:5336), Arabic only once reviewed (SPEC:5531), « Bientôt » last in ⋮ everywhere (SPEC:4999).

## Evidence surfaces (what counts as proof)
- `make check FILES=…` then `make gate-web` (or `make gate`), read from its own exit; `make check` skips specs reading a whole contract (i18n parity, settings rows, list-fit): run them too  [Source: the Lessons (CLAUDE.md and `.claude/rules/`) 2026-10-19, 10-22, 10-25/27, 10-28]
- `make gate` never boots the app: one e2e on an image of the result before landing  [Source: the Lessons (CLAUDE.md and `.claude/rules/`) 2026-11-01]
- Visible change: BEFORE and AFTER screenshots of the real result; jsdom alone is incomplete.
- Contrast and focus claims only from an axe scan or a measured value.
- Uncertified: aria-disabled controls (axe skips them), Arabic style (no reader yet), D3's mirrored chart axis (to confirm with a reader, SPEC:5758), real printers.

## Reviewer lenses
1. Accessibility, focus and keyboard (axe, RGAA, reflow, text size). 2. State and canon (signals, descriptors, RecordAction, save canon, refusals, live reloads). 3. Phone/till/RTL reality (cards, breakpoints in effective px, scan flow, real-browser look).

## Vocabulary
| Term | Meaning |
|---|---|
| descriptor | TS description of a list/form/action rendered by a generic component |
| RecordAction | a record type's one action list, read by every surface |
| annulable / corrigeable / définitif (à terme) | action kinds: toast undo / asks with preview / irreversible |
| calme / vif | the person's colour style |
| wedge | scanner emulating a keyboard |

## Canonical sources
- angular.dev, material.angular.dev, tailwindcss.com/docs, playwright.dev, axe-core, ngx-translate.org [Unverified]; docs/SPEC.md §7, CLAUDE.md, docs/research/till-hardware.md.

## Changes vs 2026-10-09
- Added tokens, calme/vif, text size, phone cards, save canon, refusals, focus flow, RecordAction, Arabic/RTL; removed the outage known issue (fixed: `list-failed`), the language one (ruled, SPEC:5337 (4)) and "Arabic not built".
