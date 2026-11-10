---
paths:
  - "web/**"
---
# Web — twes-in

What a session needs while working in `web/`, beside CLAUDE.md, which keeps the process, the licensing
invariants, git, the commands and the lessons that apply before any file is read. A new lesson of this area goes at
the end of § Lessons here.

## Where things live

- `web/src/app/api/` — TypeScript types generated from the API's OpenAPI document (`make api-types`, gitignored;
  CI passes the document from the api job to the web job as an artifact; the web IMAGE generates them itself from
  the document the api image exports at build, through a compose `additional_contexts` service reference, so a
  clean clone builds without them and a stale local copy is kept out by `.dockerignore`). One directory per feature — over twenty, so
  check `ls web/src/app` rather than this sentence: `account` — « Mon compte », the person's own preferences, `auth`, `company`, `customers`, `delivery-notes`, `expenses`,
  `fiscal`, `hello`, `inventory`, `invitation`, `invoices`, `legal` — the public `/legal/<slug>` pages, `texts` — « Textes », one page at `/company/texts` and `/platform/texts`, its words merged over the shipped files by `shared/i18n/texts-overlay.ts`, `platform`, `products`, `reports` — « Rapports », the catalogue and each report's page, `settings`, `signup`,
  `vendors`, `purchases` — the orders to vendors, their lines editor, the receive dialog (a serial number a line of quantity 1) and « Ajouter à… »'s order target (found by vendor; only active goods are taken in), `pairing` — the phone's public `/pair` page, a scanner with no sign-in, `support-access` — « Accès du support », the owner's page and the banner, `watch` — « À surveiller » and its home count, `first-steps` — « Premiers pas » on the home, `notifications` — the bell, the centre and the Centrifugo connection behind
  the `REALTIME_CONNECTOR` token, `shell` — the signed-in layout by window class (bottom bar below 600 px, rail to 1199, labelled from 1200), its nav manifest (`planned-nav.ts` places the planned modules the signed-in state's `plannedModules` lists; `COMING_NAV` keeps only the settings pages not built yet), the home manifest (`home-manifest.ts`: a module declares its `*_HOME` panel, loaded lazily, beside its `*_NAV`), the Ctrl K palette (`commands.ts`: a module declares its `*_COMMANDS` beside its `*_NAV`), account menu and the settings area behind the gear, whose list stays put beside the page and folds to a rail with `]`; every
  signed-in route is a child of it but `/customer-display`, a window facing the customer), `shared/` for what several features use and which imports no feature (ESLint enforces it; `session/`: the `Session`
  port the auth facade answers; `theme/`: runtime accent colour tokens (`accentTokens`: the accent as picked and its readable roles), the lifecycle map every module's status tones derive from (`lifecycle-tones.ts`),
  `ThemeFacade` (Automatique follows the device) and the scheme menu; `i18n/`: `LanguageFacade` and the language menu, and `PluralCompiler`, which says a count in its language's form (`{count, plural, one {…} other {…}}` in a screen text; never « (s) », the parity spec refuses it); `a11y/`: the
  `appLabel` directive, one string for a control's accessible name and its tooltip; `testing/`: providers specs share (`provideQuietFeedback` records toasts, `effectToasts` reads the kinds said; `announceSaved` plays a live change); `feedback/`: the `Feedback` port (toasts; `effect(key, params, kind)` after a consequential action), `RequestActivity` and its interceptor, the activity bar; `actions/`: `ScreenAction`, whose confirmation must name its `ActionKind` (annulable, corrigeable, définitif, définitif à terme; the last two confirmed by a `twes-danger` button, `isDanger`) and may ask a word or name typed first (`typed`), and `kindAmong`, which the toast after an action reads, and `PlannedAction`, what a screen will offer once a planned module ships, listed « Bientôt » last in either bar's « ⋮ »; `records/`: `RecordDeletes`, a record's delete and its restore the same on every screen (no question, a toast offering « Annuler », a refusal naming what uses each record), over `RecordRemovalsApi`, which opens the `records` texts into the root itself before it acts, and `record-tables.ts`, the one wording of a table a refusal or a merge counts (one the texts do not word reads as other records; `RecordWordsTest` holds every table a declaration counts to this list and its words in each language, the parity spec this list to them); `legal/`: `LEGAL_PAGES`, `LegalFooter` (the centred copyright and legal-links line closing every page), `CookieNotice` (the last row of the page, sticky at its foot, standing on the phone bar through `--twes-bottom-bar-height`) and `STORED_ITEMS` (what is stored, which the Cookies page lists), `LegalLink` (a link that opens `LegalDialog` over the screen, a modified click left to the browser) and `LegalText` (a page's text, shared by the dialog and `/legal/<slug>`: the API's Markdown through `marked` and the sanitizing `[innerHTML]`, with its fr / en / ar switch), `LegalApi`; `tour/`: `TOUR_ANCHORS`, the `data-tour` places a guided tour may point at, `Tour` (a module declares its `*_TOURS` beside its `*_NAV`, collected in `shell/tours.ts` and offered in the palette as « Guide : … ») and `TourGuide`, which runs one on a CDK overlay beside the anchor on screen, never blocking the page (`web/e2e/tours.spec.ts` plays every guide the palette offers, so a tour that no longer runs is a red CI); `health/`: the API health client; `scan/`: `ScanWedge`, which tells a scanner's burst from typing (the shell opens `ProductScanCard` on one outside a field, only while the company has the `scanning` module on, `SCANNING_MODULE`), the `ScanBus` every scan goes through, `CameraView`, `PhonePairing` (the tab's side of a lent phone) and `ScanOffers` (what the card offers that phone); `icons/`: `ICONS`, every Material Symbol the app draws, which the icon font is cut to at build (`web/scripts/subset-icons.mjs`, the font gitignored under `src/generated/`), and `IconName`, the type an `icon` field takes; `qr/`: `QrCode`, a QR code drawn as SVG; `build/`: `BuildInfo` (the web build this page runs, read once, and the API's, read again and again), `BuildLine` (the footer's build line, the whole line copied on a click) and `NewVersionBanner` (« Recharger » once a newer bundle is served); `documents/`: `liveFigures`, what a document with lines comes to as it is typed, asked of the API's `…/preview` once typing rests, `LineFiguresView`, a line's figures folded to its net and total, and `stockOfLines`, what a document's lines leave of what is on hand; `ui/`: the bars, `WINDOW_CLASS`, `COARSE_POINTER` (a finger rather than a mouse, what a target is sized for), and a menu's foldable sections (`sectionFolds`, kept in `presentation.folded-sections`) and its edge fade and current entry in view (`NavScroller`); `customer-view/`: `CustomerView`, whether the API holds the sign-in on the customer screen (`Me.customerScreenCompanyId`), the `CustomerScreenHold` port the auth facade answers, and the interceptor that sends a tab the API refused as `customer_screen_locked` to the screen; `install/`: `AppInstall`, the offer to install the application (the browser's own dialog where Chrome or Edge hands it
  over, Safari's two steps on an iPhone, nothing where it cannot or is installed), which the account menu, Ctrl K's
  « Application » group and the phone's home card (`web/src/app/install/`, put aside in `twes.install.later`) read; the
  manifest itself is the API's, `Settings/Infrastructure/Http/WebManifestController` at `/api/web-manifest`, from
  `app.product_name` and the accent's default, `WebManifestTest` holding the web's copies to them; `customer-display/`: `CustomerDisplay`, what a sale's scans show the customer display window (`/customer-display`, the `customer-display` feature directory) over a BroadcastChannel; `realtime/`: the one Centrifugo connection's connector, the `X-Tab` interceptor naming this tab, the `X-Live-Changes` interceptor handing this tab what its own request changed, and `LiveChanges` (a page calls `reloadOn(kinds, reload, destroyRef)` to read its data again, quietly, when this tab, another tab or member changes those kinds); `submissions/`: the interceptor naming every POST to the API with its `Idempotency-Key`, the same key only for the same write sent again after no answer, a server error or one still running, and the toast for the two answers about a key; `settings/`: the `SettingsFacade` port, its API adapter
  `ApiSettings` (the presentation chain), the browser-storage adapter it keeps for signed-out pages, and the registry
  every presentation key must be declared in; `errors/`: `refusedFor` and `saidAlready`, and an answer a reader has no words for, `UnknownRefusal` (`unknown-refusal.ts`: `refusalKey`, said as `feedback.unknown_refusal` with its code; `unknown-refusal-api.ts`: `unknownRefusal` and `isUnreachable`, the one rule for what is the network: no answer, 502, 503, 504) and `RefusalPipe` (`{{ code | refusal: '<prefix>' }}`); `list/`: `ListExport` (the export menu behind a step-up, a page naming the saved file through `fileName`), `ListDescriptor`, the pure view
  functions and `DataList` (given a `total`, it shows the page the API answered and emits `queryChange`; the rows-per-page chooser
  shows on every list with rows, at the size a person chose for every list, `presentation.page-size`, unless the address names one; a column holding a typed name says `wraps`, and its long values take further lines in the room the card leaves rather than send a column away; a filter the API reads under another name says it in `asked` (`regime` sent as `taxRegime`), so a read refused for a filter names the one a person set, by its label, with « Effacer ce filtre » (`refusedFilterOf`), held for every paged list by `web/src/app/filters-sent-as.spec.ts`); `form/`: `FormDescriptor`, `buildFormGroup` and `DescriptorForm`, and `DecimalInput` (`kind: 'decimal'`, or `appDecimal` on a hand-written input: the screen's decimal separator shown, a comma or a point taken, the API's point kept in the control); `liveRecord`, which merges another person's save into an open editor field by field over `mergeSavedVersion` and `RecordSync`, with the `RecordChanged` banner and `PartConflict` for what merges as one field, such as a document's lines; the save canon: `unsavedChanges` counts a form's unsaved fields into `UnsavedChanges`, which holds a navigation away and a reload while any is there, `RecordBar` beside the title shows that count and the save, active only once something changed (`testId` prefixes its ids where a tab draws a second bar), `listRecord` counts the editor a list opens and `editorActions` gives its card the same bar, and a bar saves through `DescriptorForm.submit()`, so what is refused shows where it is), files named by role: `*-page.ts`, `*-facade.ts` (signals, what components inject), `*-api.ts`
  (the only importer of the generated types), `*-types.ts`, `auth-guard.ts`, `csrf-interceptor.ts`; translations in
  `public/i18n/{fr,en,ar}.json` with a parity test; the texts only one page reads in `public/i18n/<page>/<lang>.json`,
  fetched when it first opens (`textsPart('<page>')` on its route, `shared/i18n/texts-part.ts`; `ls web/public/i18n` lists
  them: « Mon compte », the expense pages, the platform's, the stock plan's, the erasure page and more), added to the parity spec's `parts` and measured on their own by the budget gate; texts several screens
  and dialogs read (the schedule's, `public/i18n/schedule/`) are opened into the root instead, by `textsOpened(name)` on
  a route or `await TextsParts.open(name)` before a dialog, and a screen that says them without opening them shows keys.

## Gates (`scripts/gates/`)

  - `icon-buttons-named.sh` (every Material icon button named through `appLabel`)
  - `password-eyes.sh` (every password field has its eye, `shared/form/PasswordToggle` as the field's `matSuffix`, at least nine found)
  - `pastes-kept-whole.sh` (no `maxlength` on an input, which cuts a paste at its limit unsaid: the value is kept whole and `shared/form/length-said.ts` says the limit and the count under the field, the save waiting until it fits; a fixed format keeps it where its line, or a comment just before its element, says `paste-cut: <why>`)
  - `numbers-as-typed.sh` (no `type="number"`, which hands the form nothing for a French « 1,5 »: a whole number is typed as text with the numeric keyboard through `appCount` or `typedCount`, `shared/form/count-input.ts`, which says one not whole, a decimal through `appDecimal`; or `number-input: <why>`)
  - `dialog-content-layout.sh` (no `mat-dialog-content` or `mat-card-content` carries a `flex`, `grid` or `gap-*` class itself, which Material's styles override: the layout goes on a div inside it)
  - `scrollers-contain.sh` (every scroller is positioned, a template's `overflow-*-auto` beside `relative` and a style rule's `overflow: auto` beside `position`, or the line says `scroller-positioned: <where>`: a hidden text inside an unpositioned scroller escapes it and stretches the panel beyond, which slid the Paramètres list off screen)
  - `outcomes-as-toasts.sh` (what was just done is a toast through `Feedback`; a `role="status"` line only for a page state it names)
  - `outcome-sentences.sh` (every text the code hands `Feedback` as an outcome, or a scan answers as done, is closed by its period in fr, en and ar, main file and page parts alike; a floor reds when the calls are no longer found)
  - `planned-module-labels.sh` (every planned module in `PlannedModules` named, described and placed in the menu in fr and en, and every `{ module, label }` declaration of a planned action or settings card naming a planned module)
  - `stored-items.sh` (every cookie the API sets and every `twes.*` storage key is declared in `web/src/app/shared/legal/stored-items.ts`, which the Cookies page renders, both ways; browser storage only inside a `*_STORAGE` token's factory; no script from another origin)
  - `coming-gated.sh` (every template drawing the « Bientôt » chip sits beside a component that reads `showComing`, so « Montrer ce qui arrive » hides it)
  - `coming-texts.sh` (every planned module and unbuilt settings page has its `coming.<key>` texts and every such text an entry, every menu place a planned module, and the roadmap row an entry names is open in docs/SPEC.md § 8)
  - `presentation-settings-parity.sh` (every presentation preference the web writes is one the API declares, and the reverse: an undeclared key works until a reload, then is gone)
  - `tour-anchors.sh` (every name in `web/src/app/shared/tour/tour-anchors.ts` sits on an element as `data-tour`, and no `data-tour` is undeclared)
  - `route-permissions.sh` (every route a `*_NAV`, `*_COMMANDS` or `*_TABS` declaration needing a permission or a role names carries `permissionGuard(<that manifest>, '<route>')`, `shell/permission-guard.ts`, and every route below it a guard, or says `// route-open: <why>`: a page the menu hides from a role sends it to `/not-allowed`, which names the page and why; `RouteGates` reads the open page again when the session changes, since a company switch runs no guard)
  - `effect-kinds.sh` (every effect kind the API says through `new Effect(self::X` is named by a sayer registered as `EFFECT_SAYERS` in `app.config.ts`, and every kind a sayer names is said: neither half of `X-Effects` is silent)
  - `live-reload.sh` (every component reading records through a member, a stock read included, calls `reloadOn` or keeps a `LiveRecord`, or is listed with its reason; every list kept by `listRecord` also names its kind in a `reloadOn`; a list the API filters by the reader's role and modules (« À surveiller », the reports, the first steps, « Mon compte › Notifications ») hears the session too, through `withSessionKinds` or `SESSION_KINDS`; a component reading stock (on-hand, totals, substitutes, levels, valuation) names `stock`, the kind every movement is said as)
  - `role-names.sh` (a role's name reaches a template through the `roleKey` pipe, `shared/i18n/role-key.ts`, never `'roles.' + name`, which reads a company's own role as « roles.<name> »; a floor of 8 uses)
  - `interceptor-failures.sh` (an interceptor saying a refusal, which `saidAlready` then keeps the screen from saying, says it through `failure()`, kept until closed, never `notice()`; the submission interceptor is listed with its ruled reason; a floor of 5, today's count)
  - `list-retry.sh` (every `<app-data-list>` binding `[failed]` binds `(retry)` or `(queryChange)` on its tag, so DataList's « Réessayer » reads the list again: a paged list asks its page again, another hands `(retry)` to a method reading as on opening, the activity bar showing it; and takes `[failed]` from a facade's `readFailed()`, never its whole `error()`, which a refused save sets too; a floor of 31, today's count; the lists told a refused filter match `web/src/app/filters-sent-as.spec.ts`'s rows folder by folder, so a list naming its refused filter is held to what its read sends)
  - `read-retry.sh` (a page that could not read something says so through the one shared line, `<app-read-failed>`, `shared/feedback/read-failed.ts`, which binds `(retry)` on its tag to a method reading as on opening and offers « Réessayer » only after a read failed, `[retryable]="readFailed()"` where a refused save sets the same error; no page draws its own `'feedback.retry'` button but the shared line, DataList, the activity bar and a home panel (`feedback.retry_panel`); a line offering no retry says `<!-- read-retry: <why> -->`; a floor of 44, today's count)
  - `flow-outcomes-forgotten.sh` (a root facade keeping a flow's outcome, « link sent », « signup finished », offers `forget()`, and every page injecting it calls it on entry, so a page opened again starts on its form)
  - `session-forgotten.sh` (every value a root facade keeps is a `sessionSignal`, `shared/session/session-data.ts`, which `SessionData` puts back when the session ends, or leaves its company for the `company` reach, as `AuthFacade` tells it; a plain `signal(` there says `session-kept: <why>`)
  - `signed-out-exits.sh` (every page drawing `<app-signed-out-layout` offers its way out under the card, `twesAuthAfter`, or names the one its card draws with `way-out: <where>`: outside the shell there is no menu to leave by)
  - `signed-out-follows.sh` (every such page reading the session, `me()`, `company()`, `companyClosed()`, asks again on focus through `leaveOnceOpen`, or says why with `session-followed: <why>`: outside the shell no live change arrives)
  - `refusals-said.sh` (a matInput carrying `required`, `min`, `max` or `pattern`, or a button disabled on a wrong value, says the refusal beside it: a `<mat-error>` in the field, shown once left or on a save that `shared/form/reveal-invalid.ts` turns into every error shown and the first wrong field focused, a line of its own just after it, or `refusal-said: <where>`; never a greyed-out button alone, never a value put back within its limit unsaid; a field the screen refuses without a form control is marked as a form's is, `[appRefused]` on its input and a `<mat-error>`, `shared/form/refused-field.ts`, never `aria-invalid` set by hand or a hint in red)
  - `unsaved-guarded.sh` (every component that edits something counts what is unsaved through `unsavedChanges`, `listRecord` or `UnsavedChanges.declare`, so leaving asks and a reload is held, or is a dialog, or is listed with its reason; a floor at the number counting today)
  - `list-stances.sh` (every `<app-data-list>` offers selection, bulk actions, row actions, import and export, and draws an `[empty]` state, `EmptyState` in `shared/list/list-types.ts`: an icon, a title and the list's own create action, or is listed with its reason for each it does not, a state drawn without an action of its own (`empty-action`) included; red on a descriptor it cannot read, on the retired `emptyKey`, and below a floor of 31 lists drawing an empty state)
  - `folder-cap.sh` (no folder of `web/src` holds more than 25 files, specs aside, as `SourceLayoutTest::CAP` holds the API's: a template or a style counts, a subfolder on its own, read from the index; a folder already past it is in `folder-cap.allowed` with its count and reason, may not grow, lowers its line when it shrinks and leaves the list under the cap; a floor of 800 files read)
  - `e2e-environment.sh` (every variable a browser suite reads, `process.env['X']`, is passed into the `web-tools` service, which runs them; a floor of 20)
  - `design-tokens.sh` (no literal colour or static inline style in `web/src/app` outside `shared/theme`, and no class naming a colour, type step, weight, family, radius, shadow, line height, tracking or easing `web/src/tailwind.css` does not declare: its `@theme` clears each of those namespaces and declares the steps the screens use, so Tailwind emits nothing for any other and a new identity is a change of values there; no arbitrary `-[…]` value in those namespaces and no `[style.x]` of a font, radius, shadow or motion property; read in class contexts only, with a floor of 1,910 classes read)
  - `unscoped-host-selectors.sh` (no `:host`, `:host-context` or `::ng-deep` in the styles of a component declared `ViewEncapsulation.None`, which Angular leaves as written there and no browser matches: an unscoped stylesheet reads `:root[…] &`; a floor of 2 such components, today's count)
  - `icons-declared.sh` (every icon a template writes out is declared in `web/src/app/shared/icons/icons.ts`, which the icon font is cut to, and every declared one is still named)
  - `typed-emails.sh` (its web half for addresses: outside `shared/form`, every `Validators.email` in `web/src/app`, kept only where an account signs in with the address it has, is wrapped in `onTypedText(…)`, `shared/form/typed-text.ts`, so a direction mark or zero-width space pasted at an end is not refused unseen, or its line or the one above says `typed-email: <why>`; the page sends `typedText(…)` of it; a new or changed address takes `emailRule`, `shared/form/email-rule.ts`, the API's one rule `ContactRules::email`, and `EmailRuleTest` holds the two to `email-vectors.json`, refuses any other e-mail check in `api/src` but the account's own `Email`, and every address a resource or a platform setting writes without the rule)
  - `refusal-answers.sh` (every `new …Refused(` in `web/src/app` is handed to `refusedFor(answer, …)`, `shared/errors/said-already.ts`, so `saidAlready` reads under it a failure an interceptor has already said (the demo's refusal, a write still being made or whose answer was lost) and the screen says nothing more, or its line or the one above says `refusal-answer: none, <why>`; a floor of 90)
  - `restore-refusals.sh` (a file put back where it was is refused one way on every record that keeps files: each `…/restore` request in `web/src/app` reads its refusal through `restoreRefusalsIn`, `shared/files/restore-refusals-api.ts`, which sets a 422's upload words aside for the restore's own code (`files_full`) and reads a 409's move as every save does, or the request's line or the comment lines just above it say `restore-refusals: <why>` (the photo's own codes, the bin); `web/src/app/restore-readers.spec.ts` holds the three readers; a floor of 3)
  - `refusal-keys-joined.sh` (a screen says a refusal through the `refusal` pipe, `{{ code | refusal: '<prefix>' }}`, which words its own code, an answer it has no words for and a part another feature could not read; a key joined by hand, `'x.errors.' + code`, says only the first and the others as « x.errors.[object Object] »: the templates still joining one are held under a ceiling that only comes down, one more reds with its file and line, one moved to the pipe asks the ceiling lowered in the same change; TypeScript under a ceiling of its own, a quoted key or a template literal ending in `errors.${`, said through `refusalKey` once moved, specs not read; a floor of 100 templates and 600 sources read)
  - `reader-network.sh` (a reader says a lost connection by the one rule, `isUnreachable(error)` in `shared/errors/unknown-refusal-api.ts`: no answer, or a gateway's 502, 503 or 504; a test of the status against 0 anywhere in `web/src/app`'s sources (`===`, `==`, `!==`, `!=`, `>`, `<=`) reds with its file and line, so a restarting API is never said as « invalide » or « refusé »; a 5xx the API names itself, auth's `demo_busy`, is read from the body before the rule; the submissions interceptor alone is allowed, as it asks whether a write may still be unanswered; a floor of 36 files on the rule)
  - `product-pickers-explain.sh` (a product picker that finds nothing says why: every `<app-pick-field>` searching products binds `[explainNone]`, a descriptor form's `search: searchProducts` entry and a list filter's `search: (words) => this.pickProducts(` name `explainNone:` within the entry, read to its closing brace as prettier lays it, handing the field an `ExplainNone` from `UnofferedProducts`, `shared/unoffered/unoffered-products.ts` (the products switched off, the services where goods alone are offered, the goods whose stock is not kept, each counted by the selector's own `…/products/unoffered`), or the element or line says `explain-none: <why>`; a floor of 10, today's count)
  - `language-literals.sh` (no quoted language tag of the catalogue, `'fr'`, `"en-GB"` or intl's `'fr_FR'`, in the API's PHP and templates or the web's sources: a language is read from `Languages` or `shared/i18n/languages.ts`, and a line that must name one says why with `language-literal: <why>`)
  - `logical-direction.sh` (the screens turn over in Arabic from one set of templates: no physical Tailwind class (`pl-4`, `left-0`, `text-right`, `border-l`) or physical CSS (`margin-left`, `right:`, `translateX`, a shorthand whose left and right differ) in `web/src`, and every pointing icon (back, forward, chevrons, send) carries `mat-icon-rtl-mirror`, but where the line says `physical-direction: <why>`; the document's `dir` follows the language, `shared/i18n/document-direction.ts` tells the CDK, and code measuring sides reads `shared/i18n/inline-direction.ts`)
  - `value-direction.sh` (a typed value cut short keeps its start in either direction: it sits in a `twes-value` box with `dir="auto"`, never alone in a `truncate` box, which cuts at the end of the SCREEN's direction and showed « …791728493868 » for a Latin code in Arabic; every field reads its own way through `unicode-bidi: plaintext` in `styles.scss`; a value standing alone between two tags sits in a `<bdi>` (or a `dir="auto"` element), so its neutral signs stay at its own ends, but a text in the screen's language computed in code, which says `value-direction: <why>` on the line or the one above, since `dir="auto"` would turn an Arabic sentence opening on « IBAN » left to right; a printed document's typed text sits in a `<bdi>` or a `dir="auto"` block, `PdfTypedTextDirectionTest`, and a mail's in a `dir="auto"` element inside the block that says its direction again, `MailDirectionTest`; a signed change, « +6,2 % » or « −3,1 % », goes through the `signed` pipe (`shared/i18n/format-pipes.ts`, a true minus and an explicit plus) drawn in a `<bdi dir="ltr">`, and Angular's `percent` pipe, a `signDisplay` or a « + » built by hand around a value are refused; a `twes-value` box holding values joined by « · » is refused too, each part going in its own `<bdi>`; a floor of 2,900 interpolations read)
  - `screen-texts-budget.sh` (every language the catalogue's interface speaks has its texts, and each language's screen texts within 61,440 B at gzip level 6, a new change within the reserve of 59,392 B below it, raised only with its reason in docs/SPEC.md § 7, warned of at 98 % of the reserve, and `infra/web/nginx.conf` serving at that level; the first page's reserve is `web/angular.json`'s 985 kB error (978 kB warning) under the 1 MB wall, the developer's answer of 2 November)
  - `e2e-settled-reads.sh` (a browser test reads with `evaluateAll`, `textContent()`, `innerText()` or `all…Texts()` only a locator it asserted first in the same test with a waiting `expect(…).toHave…`, `.toContainText`, `.not.toBeEmpty` or an `expect.poll`, `toBeVisible` not counting, or the read says `settled-read: <why>`; a floor of 35)

## Lessons

- A template binding `[value]="fn(line)"` that builds a new object hands the child a new input on EVERY check, and a
  child effect over that input re-fires each time: `PickField` reset the text being typed on every keystroke. Compare
  a structured input by its content (`computed` with `equal`) before acting on it (2026-09-23).
- A `linkedSignal`'s `source` function is re-run, and its `computation` with it, whenever a signal it READS changes,
  even when the value it returns is the same: `() => \`${tab}|${product() != null}\`` reset an opened tab on every save.
  Give it a `computed` as its source, which only notifies when the value changes (2026-09-25, `ProductPage.tabKey`).
- A field added to a shared type, or a column added to a list, reaches specs and e2e you did not open: an API spec's
  `toEqual` on the whole mapped row, and an e2e reading a cell by position (`nth(3)`). Run the whole web unit suite
  before committing such a change, and read list cells by `[data-column="<id>"]`, never by index (2026-09-23: two reds).
  A required field added to a type is a compile error in every spec building one, which `make check` does not open:
  `make web-tools CMD='npx tsc -p tsconfig.spec.json --noEmit'` type-checks them all in a minute (`TextQuery.forms`, 2026-11-05).
  Vitest strips types, so a green spec says nothing of the code it tests either: a guard typed `string` handed to `.find()`
  over `string | null` passed its spec and stopped the image build. Add `tsc -p tsconfig.app.json --noEmit` (2026-11-08).
- A `FormGroup` built in a `computed` over facade signals is rebuilt by any reload that returns equal data as new
  objects, discarding what was typed (2026-09-14: revising a product right after creating it saved the old price and
  said "saved"). Key the form on the record's id and the descriptor's content, and read its inputs `untracked`. A list
  read after the form opened does the same; a `linkedSignal` over what is edited rebuilds over the typed values.
- A PUT body that echoes a read row with its `id` answers 400 ("Cannot find object to populate"). An e2e cleanup that
  never checks its `fetch` status hides that, and leaks rows into the shared database: throw on `!response.ok`.
- Playwright's `page.request` does not send the session cookie (`Secure`, `SameSite=Strict`) to the local http stack, so
  it answers 401: fetch an API file from inside the page (`page.evaluate`) instead (2026-09-14, the delivery note PDF).
- Angular Material's `mat-card-content` overrides Tailwind layout utilities placed on it: put the flex or grid on a `div` inside
  it (2026-09-15: the platform page's switches ran together and its buttons wrapped under the company name).
- A dead operator session makes `/api/auth/me` answer 401 with no `company` key AT ALL, so
  `forgetPresentationChoices`'s `me.company === null` guard passes `undefined` straight into `me.company.id`:
  a spec dying with `Cannot read properties of undefined (reading 'id')` on its first line needs the session
  re-minted with `--project=setup`, not a teardown fix (2026-09-16).
- Axe reads colours mid-transition: after a runtime scheme change every element with a colour transition still shows a
  blend of the old scheme, and the contrast check fails on a moment, not a design. `ThemeFacade` holds transitions for
  one frame (`.theme-changing`); a new transition on colour must stay under that class (2026-09-16, row 28).
- Never animate `mat-sidenav`'s width inside an `autosize` container: autosize measures the drawer once per change,
  reads it mid-transition and leaves the page on a margin between the two widths (2026-09-16: x=200 between 248 and 80).
- An editor that keeps its form after saving must show what the API kept, not what was typed: a later comparison with
  the saved version otherwise reads `1300` against `1300.000` as a change and highlights a field nobody touched, or
  toasts a save that changed nothing. `LiveRecord.savedHere` writes the answer into the controls (2026-09-17).
- The shared Demo company outgrows a list page as e2e runs leave rows behind (52 customers on 2026-09-16, 25 a page): a
  scenario asserting its new row in a list filters the list first, or the row sorts onto page 2 and reads as missing.
- Every company screen failing in a local `make e2e` after `make fixtures` is the operator's several memberships, not a
  regression: a sign-in opens the pinned, else the last used, else the first company by name (2026-09-25), which may
  not be Demo. A scenario fixes it for itself by calling `inACompany(page, CSRF)` after `signIn` (it switches to
  Demo; a no-op in CI, which seeds Demo alone). Switching moves the operator's "last used" company too. Add it to any
  spec you need to run locally: "let CI arbitrate" hid a real failure for three commits (2026-09-20). And a test id
  renamed in a shared component is a blast-radius sweep over `web/e2e` too, not only over `web/src`. The same holds for a
  shown format (a day through the `day` pipe) and a suffix selector (`[data-testid$="-confirm"]`): `git grep` the old
  literal over `web/e2e`; both reddened e2e on master the same day (2026-10-06). A toast's sentence reworded is the same
  sweep: the order's « envoyée » gained its vendor and total and `purchases.spec.ts` went red on CI only (2026-11-06).
- Several unrelated e2e signing in and landing on `/two-factor` is Demo's `mfa_required` left `true` on this machine,
  not a regression: the seed never sets it, so CI is green on the same commit. Read it with
  `docker compose exec -T postgres psql -U twes -d twes -c "SELECT mfa_required FROM company WHERE name='Demo'"`.
- Playwright's `error-context.md` snapshots the default `page` fixture, so a failure inside a second
  `browser.newContext()` page shows another page entirely; read that page's last `screencast/page@<id>-*.jpeg` in the
  trace zip instead. Its frame is what showed a form whose inputs had collapsed to nothing (2026-09-17).
- A guard that slows an animation to make a moment reproducible must stay slow through the scan it guards: putting the
  speed back first, or letting an earlier scan eat the window, lets the fade finish by itself and the mutant that
  removes the fix passes. Twice green before the sabotage caught it (2026-09-17, `data-list.spec.ts` mid-fade).
- `web/public/i18n/{fr,en}.json` keys are NOT alphabetical — that order is deliberate. Append a new section and assert
  the rest is unchanged by comparing the PARSED structures; sorting them produces a 2000-line diff of pure reordering
  that hides the 80 lines actually added (2026-09-20, same class as /stack's `jq … | unique` lesson).
- The connection never brings a live change back to the TAB that caused it (the `X-Tab` header); the API names it in
  that request's answer instead (`X-Live-Changes`, `LiveChangesAnswer`), which `liveChangesInterceptor` hands to
  `LiveChanges` as `own`, so every `reloadOn` of the acting tab hears it; `liveRecord` takes it without a notice. A
  write the API stages no change for (no audit entry) is still heard by nobody. Use `refresh()`, never `load()`,
  after a write — `load()` signs the person out when the API is briefly unreachable. What a request did BEYOND its
  subject (a receipt that moved a price) is said the same way: a use case calls the `Effects` port, `StagedEffects`
  keeps it until its unit commits (`StagedUntilCommit`, which `DoctrineTransactions` drives), `EffectsAnswer` writes
  `X-Effects`, and `effectsInterceptor` (`shared/effects/`) hands each kind to the `EFFECT_SAYERS` that say it, once
  `RequestActivity.settled()`, each in its turn (`EffectsInTurn`): a sayer speaks once the toast on screen has closed
  (`Feedback.idle()`), so the page's own outcome is read first and two consequences never replace each other. An e2e
  reading an effect's toast closes the page's own first (`toast-close`), or waits its four seconds.
- A stock location that has seen a movement is KEPT (409), so an e2e that moves goods into a location it created
  cannot delete it and leaks one per run into the shared company. Say the dimension is uncertified instead.
- Playwright's `evaluateAll` does NOT auto-wait: assert the count with `expect(locator).toHaveCount(n)` first, or a
  read straight after a reload returns `[]` and reads as "nothing was created". `e2e-settled-reads.sh` holds every read to it.
- Global CSS beats Tailwind: `styles.scss` rules are unlayered, Tailwind's utilities sit in a layer, so any global or
  Material `display` wins over a template's `hidden`, `max-lg:hidden` or `flex`. `mat-sidenav-content` is `display:
  block` whatever its `flex flex-col` says, and `.twes-rail`'s `display: flex` showed a list `max-lg:hidden` should
  have hidden. Read the computed style before trusting a utility on a Material or `twes-` element (2026-09-26, row 151).
  And Material's component CSS loads AFTER `styles.scss`: a `.twes-*` class on a Material host loses to it at equal
  specificity. `.twes-activity-progress { position: fixed }` read `relative`, and the bar pushed the app 4 px down on
  every request for weeks; qualify with Material's own class (`.mat-mdc-progress-bar.twes-…`).
- An `aria-disabled="true"` control is disabled to Playwright AND to axe: `click()` waits forever for it to be enabled
  (activate it with `focus()` then `keyboard.press('Enter')`, which also proves the keyboard reaches it), and axe's
  `color-contrast` skips it and everything inside it, so no scan certifies its colours (2026-09-26, row 150).
- A template reference named like a component member (`#day` beside a `day` signal) shadows it inside the template and breaks the type check; a `matSuffix` inside an `@case`/`@switch` is not projected (NG8011), so give the field its own `@else if` branch. A swatch or icon-only button needs `appLabel` for the a11y e2e's tooltip rule AND text content for the template lint (2026-10-04).
- A Material dialog with `autoFocus: 'first-tabbable'` moves focus only once its open animation ends: an e2e that fills the
  first field straight away types into the page behind (the invoice payment flake). Wait for `toBeFocused()` on that field
  first. A matSuffix inside an `@if` beside its input is not projected either (NG8011): one mat-form-field per kind (2026-10-06).
- When a search shape changes, sweep the API CALL as well as the type (`git grep '\.customers(\|\.products('`): `price-lists-facade` builds the search literal inline without naming its type, and twice only the build found it (2026-10-07).
- Over the first page's 1 MB budget, measure before trimming: build with the budget lifted and `--stats-json`, then walk
  `main.ts`'s static imports in `dist/web/browser-stats.json` for the inputs. A shell-level service importing a feature's
  whole API class puts it on the first page (`ProductScanDetails` carried all of `InventoryApi`, 13.5 kB): load it with
  `await import()` where the shell needs one call of it (2026-10-08).
- Never remove `.cdk-overlay-container` in the middle of a spec: the CDK keeps the detached element and opens every later
  menu off the document, so a following `toBeNull()` passes on nothing (a mutant survived it, 2026-10-08). To open a second
  page in one case, `fixture.destroy()` the first, which closes its menu; leave the removal to `afterEach`.
- A saved value whose field a form shows only sometimes (a preset's option) must be left out of the saved values when the
  field is absent: `dirtyCount` reads it as one unsaved change, the page holds every navigation away, and thirteen e2e that
  pass through Paramètres stayed on `/company/profile` (2026-10-09, `vatOnDebits`). The form's own spec builds the group
  and asks for zero changes on open.
- An e2e never proves who is at the screen in advance: the scenarios share the operator's session and the step-up limiter counts
  every attempt, five in five minutes, so eleven advance proofs ended in 429. Send API writes through `sendProving` and follow an
  on-screen write with `proveIfHeldBack` (`web/e2e/step-up.ts`); a fresh session's write may fill the dialog directly (2026-10-13).
- An expected value written `.toBe(await x(page))` beside `expect.poll` is read ONCE, before the poll starts. After `emulateMedia`
  the page takes the scheme up a moment later (`ThemeFacade` hears `matchMedia`), so the dark pass compared light with light and
  went red only when the switch fell between the two reads. Read both sides inside the poll and require the state to have moved;
  sabotage a style under `make up` only after `docker compose restart web` and a `curl` of the served CSS (2026-10-14).
- An effect keyed on `auth.me()?.company?.id ?? null` fires again when the person signs out, and its call answers 401:
  « Se déconnecter » landed on « Votre session a pris fin ». Treat signed-out as its own state and ask nothing then
  (`NotificationBell`); `RequestActivity` takes no 401 for an ended session once nobody is signed in (2026-10-14).
- `app.routes.ts` is on the first page, so a constant it imports from a feature's module brings that whole module with it:
  `SCHEDULE_TEXTS` from `schedule.ts` put the first page 2.77 kB over its 1 MB. Keep such a constant in a file of its own
  (`shared/schedule/schedule-texts.ts`), and measure the first page before a web slice, not after (2026-10-15).
- Moving screen texts into a page's part (`textsPart`) moves them out of `web/public/i18n/<lang>.json`, which API tests
  and gates read too: `git grep -l "public/i18n" api scripts` before the move. `ImportColumnsTest` went red only after the
  push, and its gate never reached the web half (2026-10-16, 7159fcb1). The same holds for any web constant moved between
  files: `git grep <NAME> -- api/tests scripts` first; `RecordWordsTest` read `NAMED_TABLES` by path (2026-10-20).
- When a PUT starts requiring a version, `git grep` its address over `web/e2e` before the push: a scenario's cleanup
  calls it straight from the page, without one, and turns red after passing (`numbering.spec.ts`, 2026-10-17). A
  cleanup sends the version read just before, never the one read when the scenario began.
- A list gives a column way whole when the values do not fit, so an e2e reading a low-priority column must use values
  whose width does not vary per run: `Date.now().toString(36)` letters moved « Vis LOT-<run> » by 20 px, and at 1600 px
  the stock list has 8 px to spare. Use digits (`lots.spec.ts`); reproduce a width flake with the widest letters (2026-10-19).
- Read a flake's cause from the trace's request bodies before writing a story for it: the wide-list scenario's 16 px was
  explained as a re-measure and the test "fixed", while the saved widths read 305, 321, 321: a press lost because the
  handler widened the `column` object the header last drew. A handler handed a template's object reads it as last
  rendered; read the current state by its id (2026-10-19, `DataList.onResizeKey`).
- A setting declared in a section of the settings page takes one cell of its two-column grid and moves every field after
  it by one; only `web/e2e/settings.spec.ts`'s row test sees it, and it reddened master (2026-10-19). Run that spec after
  declaring a setting, and say in its comment what it pairs with. A label that wraps where its neighbour does not lowers
  its input: a sentence goes in `SETTING_HINTS`, under a short label.
- axe decides whether a font draws icons from the first three it measures and keeps that answer for the whole scan: begun
  before Material Symbols arrived, it failed label-content-name-mismatch on every icon button at once under load. Start
  every scan through `axeAtRest(page)` (`web/e2e/axe.ts`), never `new AxeBuilder` in a spec (2026-10-19).
- A service whose toast outlives its page opens its own texts (`TextsParts.open`) rather than trusting every route to: the
  document pages' new delete said `records.deleted_one`, which their specs, doubling `RecordDeletes`, could not see; only
  the e2e did (2026-10-20).
- A toast check after a second save that says the same words passes on the first save's toast, still on screen: the
  read that follows sees the record before the second save (`fiscal.spec.ts`'s restore, red only beside other workers).
  Wait for the write's own response (`waitForResponse`, then `ok()`), then the toast (2026-10-21).
- A unit case that opens a page several times outruns Vitest's five seconds once a gate loads the machine (the delivery
  note's « says nothing without stock » opened four): one page per case (`it.each`), never a longer timeout (2026-10-21).
- Vitest's `toEqual` compares an `Error`'s `cause`: giving every feature refusal the HTTP answer as its cause reddened 69
  specs comparing refusals, and the cause would travel into logs and the client error report. Keep a link only one reader
  needs beside the object, in a module's `WeakMap` (`refusedFor`, `shared/errors/said-already.ts`) (2026-10-21).
- esbuild keeps one copy of a package, made of every export the whole app uses, in the chunk its first importer
  needs: a root provider importing `MatPaginatorIntl` put Material's paginator, its select and all of `@angular/forms`
  on the first page, so any lazy screen using one more forms export grew it. Provide such a class where it is drawn
  (`DataList`'s `providers`), and diff the first page's inputs against master's stats before trimming (2026-10-23).
- Render an SVG to a PNG with Chromium in `web-tools` (the SVG in an `<img>`, a screenshot with `omitBackground`), never
  ImageMagick, which dropped the maskable icon's strokes and drew a bare tile. Chrome's `Page.getInstallabilityErrors`
  answers `[]` in Playwright's Chromium even for a manifest with no icon, so it proves nothing: assert what
  `Page.getAppManifest` parsed instead. `assertSame(1, preg_match(…))` does not narrow `$m[1]` for PHPStan (2026-10-22).
- WebKit keeps an icon font a navigation cut short as failed for the next page: a reload landing while the home still
  loaded Material Symbols made `document.fonts.load` reject at once, with no request sent, and every icon read as its
  name (`install.spec`, 8 runs in 30, green or red by shard order). `signIn` returns once the home has its icon font
  (`e2e/icon-font.ts`); a scenario that leaves a page it has just opened waits for it the same way (2026-10-23).
- A row action is width on every row: a standing one takes a slot (two at most), the first that folds takes « ⋮ »'s. W6's
  three folded movements gave `/stock` its first « ⋮ » and pushed its columns off a laptop; only `list-fit.spec.ts` saw
  it. Run it after giving a list an action or a column (« Reçu par » on four lists reddened CI, 2026-10-27), and fold what is final or occasional (2026-10-25).
- A timer armed from a moment the server sent, read against `Date.now()`, fires early or late by the browser's clock
  skew, and loops when the moment reads as past: the API says seconds from itself (`readAgainIn`) and the browser counts
  its own moment from the answer (`WatchFacade.arm`). Two members left: `company-facade.ts` `readAgainWhenAGrantEnds`,
  `shared/time/deadline-clock.ts`. A facade's late answer for a company the session left is dropped by a count its
  forget moves on (`WatchFacade.load`); the other `sessionSignal` facades are not yet (2026-11-06).
- A step that swaps one form for another, or a refusal that empties a field, leaves the cursor on the button pressed,
  which the wait disables, so what is typed next is lost (the sign-in's code step, 2026-11-06). Focus the field typed
  next after the render (`afterNextRender`), a signed-out page's first field with `appTypedFirst` (`shared/a11y/typed-first.ts`;
  `autofocus` holds only for a document load), and a refused answer kept in a dialog selected (`typeAgain`). A spec focuses
  the button BEFORE the submit: focusing it after, once disabled, leaves the cursor where it was and the case passes on nothing.
- The first case of a heavy page spec pays the page's warm-up (its imports compiled, its texts opened): the delivery
  note's first case took 6.2 s and 6.4 s under two gates the same day, 565 ms and 702 ms alone, and reddened the gate
  twice. Measure it alone and read `uptime` before touching code; re-gate only once the load is measured quiet, never
  with a longer timeout (2026-11-06).
- Prettier is not idempotent on a long member chain (`http.expectOne(…).flush({ violations: [ … ] }, …)`): its first
  pass breaks the chain per call, a second joins it again, so `make check FIX=1` wrote the first form, said « ok » and
  the gate's `prettier --check` reddened (2026-11-07). After `FIX=1`, run `make check` once more without it before staging.
- A provider named in `app.config.ts` puts its class on the first page even when only lazy screens inject it: the camera's
  two ports rode it for months (1.39 kB). Give a port its default where it is declared, `@Injectable({ providedIn: 'root',
  useFactory: () => new Adapter() })` (a factory: the adapter is declared below the port or imports it), and keep
  `app.config.ts` for what the start itself uses; `scan-ports.spec.ts` is the pattern (2026-11-08).
- A screenshot taken as evidence passes `animations: 'disabled'`, as the gallery's do, or waits for the motion to end: a
  tab change slides for 500 ms, and a shot waiting only on the new tab's text showed the previous tab cut at the left
  edge and the new one past the right, read as a layout defect (S5.39, 2026-11-08). Measure `scrollLeft` and the
  `mat-tab-body-animating` class before calling a shifted panel a defect.
- A reader's fallback for an answer it has no words for is `unknownRefusal(error)`, never `'network'`: the erasure, support, the bin, the settings and a mail's stop link said a code the API added later as « Le serveur est injoignable » (S5.41). Only `isUnreachable` decides the network; a screen says the refusal through `refusal` or `refusalKey`, since `'x.errors.' + code` compiles over an object. A facade relaying another reader's refusal names that part through `unreadPart` (`unknown-refusal.ts`, said from the `unread` texts its routes open) with the code, never its own `'network'`, and its template says it through the pipe; `refusal-keys-joined.sh` holds the keys still joined by hand under a ceiling that only comes down (S5.44, 2026-11-10).
- `filters-sent-as.spec.ts` holds a list's filters only as its row builds the list: products' row read `PRODUCTS_LIST` and `productSearch(query)`, while the page builds `productsList(fields, offered)` and passes the reasons, so « À vérifier »'s two filters were held by nothing. A page that builds its descriptor through a function or hands its `…Search` more than the query is read the same way in its row (S5.48, 2026-11-10).
- A reader's last word (`invalid`, `refused`) is for a value it sent refused, 400 or 422; any other status it names no case for goes through `unnamedRefusal(error, word)` (`shared/errors/unknown-refusal-api.ts`), so a 403, 409 or 500 says its code. Read a widened reader's consumers by TYPE, not by spelling: `tsc` passes `'x.' + object`, the preview joined `'documents.preview_states.' +`, which no `errors.` search finds, and `recurringFailure(field)` said every failure without a field, the network included, as `invalid`: a helper is handed the whole refusal. `refusal-keys-joined.sh` holds templates and TypeScript under ceilings of their own; `unknown-answers.spec.ts` holds each reader, `NOT_YET` naming the ones not yet taken (S5.47, 2026-11-10).
