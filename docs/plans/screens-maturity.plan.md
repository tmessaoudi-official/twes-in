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
- [2026-10-14 11:55] AGREED: Lens 53, first focus: reaching a screen or a step with something to type (a route, a dialog, the sign-in's authenticator code step) puts the focus in the field typed first, so typing never goes nowhere; checked on every screen, measured by the gallery where a route lands and read in the code for in-screen steps and dialogs (developer).
- [2026-10-14 12:05] AGREED: Lens 53 widened from first focus to the whole family, focus and typing flow, the sign-in's code step being one worked example to generalise from: focus on arrival (route, in-place step, dialog, tab, sheet); after a refusal, on the first field in error; returned to the opener when a dialog, menu or sheet closes; on the next row after one is removed, on the new line after one is added; kept across saves and live reloads, never stolen by a toast; Enter submits and Escape cancels where expected; tab order follows the visual order, right to left too; focus always visible; one-time-code and numeric fields take paste and the right phone keyboard; on a phone, whether arrival focus opens the keyboard is ruled with the rubric; the sign-in fix waits for the kind fix after the ruling (developer).
- [2026-10-14 12:57] AGREED: L-200 fix-now: « Émettre » saves what is typed first, then the confirmation quotes the number, total and customer the API answered, then issues; cancelling leaves the typed changes saved on the draft (developer).
- [2026-10-14 12:57] AGREED: L-600 fix-now: a vendor's default expense category is shown and edited on the vendor record and saved with it, and the vendor export carries its column, so export then import keeps it (developer).
- [2026-10-14 12:57] AGREED: L-400 fix-now: a count line keeps the location it was scanned at; the count sheet groups lines by location and recording books each at its own; scanning another shelf's label starts that shelf's group (developer).
- [2026-10-14 12:57] AGREED: L-401 fix-now: a scanner burst ending in Enter into a quantity or price field is taken as a scan, never typing (the field keeps its value, the scan goes where scans go, said in a toast), amending the 2026-09-23 01:10 ruling for number fields only; and a quantity or price far above what is usual for that product asks before it is saved (developer).
- [2026-10-14 15:13] AGREED: Gates stay side by side as ruled 2026-10-08 22:34: a « Cannot rename … de~ already exists » red is the race on api/var/cache, and the red half is rerun alone (the CLAUDE.md lesson).
- [2026-10-14 15:13] AGREED: L-1080 is sourced first: the timbre on a credit note and the document discount on a partial return are researched with citations into docs/fiscal/TN.md, then fixed with tests, and the test's hand removal of the stamp goes; no rule is invented in code.
- [2026-10-14 15:13] AGREED: Q1–Q21, the maturity rubric and the twelve feelings families are ruled on one private marking page, read back into SPEC § 7, before round 2's instruments and three waves.
- [2026-10-14 15:14] AGREED: Fix-now before round 2: L-1000 (sign-out asks before typed work is lost), L-425 (the lent phone says « Envoyé » only once the API answered, and keeps an unsent scan) and L-820; L-603 (an expense with several taxes) gets its own plan, sourced first, and round 2's slice S8 waits for it to land.
- [2026-10-14 15:14] AGREED: L-820: turning the company's two-factor requirement off asks for a step-up proof and joins the step-up list; turning it on asks nothing. The module-switch half of Q14 stays on the marking page.
- [2026-10-14 15:19] AGREED: The fix-now batch (L-820, L-1000, L-425) is treated as Large: each item gets its own full plan and a hard stop for the developer's go before any code.
- [2026-10-14 15:19] AGREED: After the fix-now batch: the L-1080 sourcing into docs/fiscal/TN.md, then L-603's own plan for approval; the marking page is read back whenever the developer finishes it.
- [2026-10-14 15:23] AGREED: L-820 go as planned; the step-up dialog says « Pour ne plus exiger la vérification en deux étapes, saisissez votre mot de passe ou utilisez une clé d’accès. » (en « To stop requiring two-step verification, enter your password or use a passkey. »).
- [2026-10-14 16:18] AGREED: L-1000 go as planned: signing out asks the existing leave question (« Quitter sans enregistrer ? ») when something typed is unsaved, from AuthFacade.logout so every way out takes part; nothing unsaved, nothing asked.
- [2026-10-14 16:27] AGREED: The leave question (« Quitter sans enregistrer ? ») names its kind « Définitif » (typed work that is not saved cannot be got back), so « Quitter » takes the danger colour; it becomes a typed ActionConfirm, so no confirmation can lose its kind again (found with L-1000: it showed « actions.kind.undefined »).
- [2026-10-14 16:43] AGREED: L-425 go as planned: the lent phone says « Envoi… », then « Envoyé : <code> » only once the API answered; a scan, photo or choice lost to a drop stays « Non envoyé » with « Renvoyer », resent with its first id by hand or when the phone is back online; kept in memory (en Sending… / Sent / Not sent / Send again).
- [2026-10-14 18:03] AGREED: L-1080 stamp: a credit note gives the invoice's stamp back once at most, only on the note that completes the invoice (today's refund, bounded), held as a property of the fixed_document component in the TN preset; kept until an accountant reads docs/fiscal/TN.md § 4a (no text gives the stamp back; NC 6/2004 stamps the credit note itself).
- [2026-10-14 18:03] AGREED: L-1080 discount: go as planned: a partial credit note gives back its lines' shares of the document discount and of each line discount amount, prorated by quantity, the note completing the invoice taking what is left; deposit deductions are left to L-1097.
- [2026-10-14 18:07] AGREED: L-1080 line discounts: each credit line names the invoice line it credits, kept through edits as sourceDeliveryNoteLineId is (a column, an API field, a hidden editor control); its discount amount is prorated by quantity and the note completing that line takes the rest (crediting 3 of 10 at 100.000 with a 50.000 discount gives 285.000). Same commit as the document discount and the stamp.
- [2026-10-14 18:13] ASSUMED (review): FR preset states corrections.stamp: given_back_once — because the key is required of every preset so a company-made stamp-family charge has a rule, and France ships no stamp to source one from. Alternatives: kept (a credit note never gives a company-made fixed charge back).
- [2026-10-14 19:03] AGREED (ratified ASSUMED, 2026-10-14 19:19): L-1080 stamp rule shape: held as one preset key, corrections.stamp, covering every fixed charge per document, rather than as a property of the TIMBRE component the 18:03 ruling names — because a company-made fixed charge needs the same rule and a component property would need a column and a field on every fixed charge; for TIMBRE the behaviour is the one ruled. Alternatives: a property on each fixed_document component (preset YAML, tax_component column, settings field).
- [2026-10-14 21:03] ASSUMED (review): L-1099: the credit note completing an invoice takes what is left of each percentage tax the invoice charged (what it charged less the credit notes already issued), as the discount, the stamp and the withholding already do, so the credit notes of its lines one by one add up to its VAT and the last is no longer refused; only where the gap is the rounding of the credit notes taken alone, at most one smallest unit a credit note, so a note taxing other figures keeps its own VAT and the creditable check decides. Alternative: round every credit note on the invoice's group (a partial note's VAT would then depend on notes not yet written).
- [2026-10-14 21:54] AGREED: A credit note giving back only part of a final invoice that deducted a deposit carries no deposit line: only a credit note of the whole invoice gives the deposit back. The return is credited at its own net and taxes (200 net returned: 200 + TVA), the deposit stays deducted from what is kept, a return worth more than the final invoice still owes is refused on it, and the rest is corrected by a credit note of the deposit invoice (developer ruling, L-1097; TN.md § 2b, unvalidated).
- [2026-10-14 23:11] AGREED: On a credit note giving back part of an invoice, a hint under « Droits et retenues du document » says the stamp is given back by the credit note completing the invoice (« Le droit de timbre est rendu par l'avoir qui complète la facture. »); the chip stays as it is (L-1101).
- [2026-10-15 00:23] AGREED: L-1102: the preview answer says why a chosen fixed charge per document is left out of a credit note's figures (given back by the note completing the invoice, kept by the preset, or never charged by the invoice), and the hint under « Droits et retenues du document » shows only for the first.
- [2026-10-15 01:32] AGREED: L-1103 and L-1104 next: both hints under « Droits et retenues du document » are tied to the field for screen readers, and the preview names the position each answered line was sent from so the editor places figures by it rather than inferring the dropped deposit lines.
- [2026-10-15 02:48] AGREED: L-1105 next: the line under « Droits et retenues du document » saying the completing note gives the stamp back announces itself as it appears (role="status"), so a screen reader typing a quantity is told.
- [2026-10-15 04:28] ASSUMED (review): Round-1 rows taken next without a ruling, by harm: L-418, L-601, L-211, L-201, L-206 (its missing parameters only; « art. 293 B » on quotes is sourced into FR.md first), L-221, L-416, L-605, L-805 (return to an in-app path only) — because each is a defect against what is already ruled or built. Held for a ruling: L-402, L-403 (their rows ask one), L-409, L-415, L-604, L-1001, L-1034, L-216, L-204, L-414, L-422, L-602, L-800. Alternatives: strict ledger order (L-201 first).
- [2026-10-15 04:38] ASSUMED (review): L-418: a scanned location code used in several establishments is the one in the establishment of the place in hand (the count's place, the movement's place); none there, the scan is refused with « Le code « R1 » est celui d’un emplacement dans plusieurs établissements : scannez l’étiquette de l’emplacement. » — because the label's address names one place whatever the shop. Alternatives: refuse every code used twice; offer a choice of the matching places.
- [2026-10-15 04:55] ASSUMED (review): L-211: two numbering series of one document type may never print the same number; a format that could is refused with 409 when it is written (a sequence taken as any digits from its padding on, a month as any two digits, so a doubt refuses), naming the other series; the screen says « Une autre série de ce document pourrait imprimer les mêmes numéros : ajoutez {EST} au format, ou un texte que l’autre n’imprime pas. ». Not yet checked: a new establishment's own series (created with {EST}-) and a change of an establishment's code. Alternatives: check at issue instead (a refusal at the counter), or a uniqueness constraint on the printed number alone (refuses only once two collide).
- [2026-10-15 05:34] ASSUMED (review): L-605 split: the bar's save now shows every error and puts the person on the first field in error on customers, products, vendors, expenses and the company profile (4bb87340); the other half, a 422 naming a field and reason shown on that field instead of the generic « Vérifiez… » line, is held as L-605b because no screen reads a 422's fields today and it needs a mapping from each API's property paths to each form's field ids and translated reasons for the refusal codes. Alternatives: build the 422 mapping now for customers and vendors only.
- [2026-10-15 05:42] ASSUMED (review): L-221 split: a scanned line on an invoice or a delivery note is now priced from the customer's price list for the quantity the scans reached, as a picked line is (a scan put the shelf price on the document itself), and the customer display's total follows the figures as typed instead of the draft as last saved — because both make the counter and the document say the same as the screen with no product choice. Held for a ruling: whether the display shows the line's own price (after list and discount, worked out by the live preview) instead of the labelled shelf price of the 2026-10-02 10:40 decision. Alternatives: change the display's price now (a user-visible change of a recorded decision).
- [2026-10-15 05:48] ASSUMED (review): L-601: a customer or vendor file is held to the form's own rules for what the domain does not check (e-mail, telephone, website, country, IBAN, BIC, address and note lengths): each cell is checked against the constraints declared on the API resource's property it fills, in its write group, through one ImportExport service, so the rules live in one place; a refused cell names its column and a code the screen already translates, plus a new invalid_country. A country written in small letters is taken in capitals. The vendor template's example IBAN, which failed its own checksum, is replaced by a valid one. Alternatives: move the checks into the domain (rejected for now: Symfony's IBAN, BIC, URL and country rules would be written twice and drift).
- [2026-10-15 06:31] AGREED: L-221 display: once the live figures answer, the customer display shows the scanned line's own unit price with taxes, after its price list and discount, under « Prix unitaire, toutes taxes comprises »; until then, or with no customer chosen, it shows the labelled shelf price. Supersedes the 2026-10-02 10:40 shelf-price label.
- [2026-10-15 06:31] AGREED: Next after L-221's display: L-605b, each refusal of a customer or vendor save on its own field through a shared reader of the API's per-field answers; the texts are shown to the developer first.
- [2026-10-15 06:51] AGREED: L-605b: a refused field shows the import's own rejection text under itself (« E-mail refusé. », « Pays refusé : … », « Texte refusé : {{max}} caractères au plus. »), so a file and a form say the same; it covers all five forms in two slices, customers and vendors first, then products, expenses and the company profile, through one reader of a single refusal shape the API answers on both its constraint and domain paths.
- [2026-10-15 07:23] ASSUMED (review): L-605b slice 1 (customers and vendors): a refused save answers API Platform's own violations, each payload carrying the reason (the import's codes) and that text's parameters, through api_platform.validator.serialize_payload_fields; a domain refusal answers the same shape through DomainRule; the import reads its reasons from the same constraint payloads; the web puts each refusal on its field as a control error until the field changes, and a reason with no screen text (the domain's invalid_customer_number, invalid_length, invalid_rate, unknown_tax_regime, unknown_group_id, unknown_tax, regime_excludes_tax, and the vendors' invalid_value default) is said « Valeur refusée. » until its own words are ruled — because the 06:51 ruling names the import's texts and no new text is written before the developer sees it. Alternatives: a decorated violation normalizer computing reasons per constraint class; refusals kept in a signal beside the form.
- [2026-10-15 10:16] AGREED: L-605b words: invalid_customer_number is said « Numéro refusé. » and invalid_length « Texte refusé : {{max}} caractères au plus. » (the import's own texts); invalid_rate « Remise refusée : un pourcentage de 0 à 100, trois décimales au plus. », unknown_tax_regime « Régime inconnu : choisissez-en un dans la liste. », unknown_group_id « Ce groupe n’existe plus : choisissez-en un autre. », unknown_tax « Cette taxe n’existe plus, ou n’est plus active. » and regime_excludes_tax « Le régime {{regime}} ne facture pas {{tax}}. » join import.rejections, with their English pairs.
- [2026-10-15 10:16] AGREED: L-605b: a tax the regime does not charge stays refused on the taxes field, where the API names it; changing the regime or the taxes clears it.
- [2026-10-15 10:16] AGREED: Next after L-605b slice 1: slice 2, the same per-field refusals on products, expenses and the company profile.
- [2026-10-15 10:35] AGREED: L-605b: a tax the regime does not charge is refused as « Ce régime ne facture pas la taxe « {{tax}} ». » / « This regime does not charge the « {{tax}} » tax. », the tax named as the company named it; supersedes the 10:16 « Le régime {{regime}} ne facture pas {{tax}}. », which the API filled with codes (« exempt », « TVA19 »).
- [2026-10-15 10:35] AGREED: L-605b: a vendor's number, name and payment terms refuse with the import's existing texts (invalid_number, invalid_text with its max, invalid_payment_terms); an unknown expense category stays « Valeur refusée. » until it has words of its own.
- [2026-10-15 10:35] AGREED: L-605b slice 2 (products, expenses, company profile) is Large: explored first, then a full plan with any new texts, and no code before the developer's go.
- [2026-10-15 10:50] AGREED: L-605b slice 2 plan approved: per-field refusals on the company profile, then products, then expenses, one commit per form; every new word comes to the developer as one batch before it is written, with how an expense line's refusal is said.
- [2026-10-15 11:18] AGREED: L-605b slice 2 words: seven texts join import.rejections in fr and en as drafted — unknown_choice « Ce choix n’est plus proposé : choisissez-en un autre. », tax_on_document « {{tax}} se compte sur le document, pas sur une ligne. », tracking_kept_by_stock « Du stock de ce produit a déjà bougé : il garde son suivi. », service_untracked « Un service n’a pas de stock : il n’est suivi ni par lot ni par numéro de série. », invalid_amount « Montant refusé : un nombre au-dessus de 0, {{decimals}} décimales au plus. », one_vat_rate « Une ligne porte un seul taux de TVA : pour deux taux, faites deux lignes. », printed_tax_off « Ce montant s’écarte de plus de {{tolerance}} de ce que donne le taux. »
- [2026-10-15 11:18] AGREED: L-605b slice 2: an expense line's refusal is said under that line's own field in the bill, and the person lands on it.
- [2026-10-15 11:18] AGREED: L-605b slice 2: a product's name, description and substitution group refuse as « Texte refusé : {{max}} caractères au plus. » on the form and in the file, as a customer's name does.
- [2026-10-15 11:18] AGREED: L-605b slice 2 leaves the expense payment dialog (paid on, withholding, TEJ code) out; it goes to the ledger as a follow-up.
- [2026-10-15 12:52] AGREED: L-1112: a tracking refusal's top line falls back to the products form's generic refused text; the exact sentence stays under the tracking field, as every other refused product field (developer, closing question of slice 2).
- [2026-10-15 12:52] AGREED: unknown_identifier gets a French and English text now, its wording proposed and confirmed before it is written (developer, closing question of slice 2).
- [2026-10-15 12:52] AGREED: Next: all four of L-1113 (bell amounts), L-605c (payment dialog refusals), L-1111 (expense line decimals) and the held rulings; order and approach re-asked for confirmation or challenge (developer, closing question of slice 2).
- [2026-10-15 12:58] AGREED: unknown_identifier's text: FR « Ce pays ne prévoit pas de numéro « {{identifier}} ». », EN "This country has no “{{identifier}}” number." (developer).
- [2026-10-15 12:58] AGREED: Order: slice 2's tail (L-1112, unknown_identifier text), L-1111, L-1113, L-605c, then the held rulings one batch at a time (developer).
- [2026-10-15 12:59] AGREED: L-1113: each publisher of an amount-bearing notification adds the currency's scale beside currency; each notification type lists its amount keys, which the bell formats with the screen's separator; an older notification without a scale keeps the decimals it was sent with (developer).
- [2026-10-15 14:03] AGREED: L-1113: the bell's text in a browser is named not certified by execution; no credit-limit e2e (developer).
- [2026-10-15 14:03] AGREED: Before L-605c: all four tooling speed-ups (parallel gates, a fast pre-gate check, a mutation runner, tidying the noise), each challenged and re-asked before it is built (developer).
- [2026-10-15 14:10] AGREED: Tooling #2: PHPStan's result cache kept in api/var/cache/phpstan and a make check FILES=… quick filter (php-cs-fixer, Prettier, cached PHPStan in one container); make gate stays the authority; a gate is timed before and after (developer).
- [2026-10-15 14:10] AGREED: Tooling #3: our own small mutation runner (a list of chosen mutants, one container, landed and tally checks, plain-cp restore, cmp), tested in CI; not Infection or Stryker (developer).
- [2026-10-15 14:10] AGREED: Tooling #4: the vitest ESM warning and the Prettier two-pass check only; the skipped schema tests and colour-free logs dropped (developer).
- [2026-10-15 14:10] AGREED: Order: make gate from now on, then #4, #2, #3, then L-605c; a status check of the maturity rounds was asked first (developer).
- [2026-10-15 14:14] AGREED: Back to screens as maturity: the never-built promises (D1, D2) are built screen by screen, each screen closing its open ledger items by kind; round 2 runs once the marking page is ruled (developer).
- [2026-10-15 14:14] AGREED: The ruled tooling (#4, #2, #3) comes before the next screen (developer).
- [2026-10-15 14:14] AGREED: L-1114: the API's mails write amounts in the recipient's language through PHP intl, a test per mail, done with the tooling (developer).
- [2026-10-15 14:42] ASSUMED (review): make gate side by side timed out 41 web tests at load 15 on 8 cores (paratest one worker a core beside vitest's own): the gate is kept parallel only if, with the cores split between the halves, it is green and faster than the halves run one after another (measured); otherwise make gate runs them in turn on this machine. Alternatives: run the halves in turn without trying the split; raise vitest's 5 s timeout (refused: a bandaid over load).
- [2026-10-15 16:14] ASSUMED (review): Tooling #2 is make check alone: PHPStan's result cache already persists in the toolchain's TMPDIR (/tmp/twes-in-tools-<uid>/phpstan, 297 MB; composer stan answers in 2–4 s), so the ruled cache half had nothing to do. Alternatives: move tmpDir into var/ (no gain measured).
- [2026-10-15 16:14] AGREED (ratified ASSUMED, 17:02): L-1114 writes mail amounts through the PDF's decimal canon (DecimalExtension::format, from the decimal string), not PHP intl as ruled: NumberFormatter::format takes int|float, so intl would pass money through a float, against the float-casts invariant, and give a second canon beside the printed documents; the reader sees the same thing. Alternatives: intl NumberFormatter (a float), a string-to-intl shim.
- [2026-10-15 16:14] ASSUMED (review): A notification kind names its amounts where its module declares it (NotificationKind::$amounts, InvoiceNotificationKinds), not in a list of the modules' types inside the Inbox mailer: modules contribute by contract. Alternatives: a const map in SymfonyNotificationMailer mirroring the web's.
- [2026-10-15 16:14] ASSUMED (review): L-1114 follows the reader's language only. Found beside it and left for the developer to place, as L-1115: the company's or person's presentation.number-format (which the bell honours) is not applied in mails; days are written 2026-10-15 in mails (%date% x4, %until%) and in the bell; stock quantities (%on_hand%, %point%, %difference%, %counted%) are written as stored in both; the payment method is its code (%method%). Alternatives: fix the whole class now (scope beyond the ruling).
- [2026-10-15 17:02] AGREED: L-605c paid-on refusals: two texts, field + rule, no date: « Date de règlement refusée : le jour de la dépense au plus tôt. » / « Date de règlement refusée : aujourd’hui au plus tard. » (EN: Payment date refused: the expense's day at the earliest. / Payment date refused: today at the latest.)
- [2026-10-15 17:02] AGREED: L-605c withholding rate and TEJ code: two own texts, « Retenue refusée : un pourcentage de 0 à 100, trois décimales au plus. » and « Code TEJ refusé : il ne vaut que pour une entreprise sous le préréglage tunisien. » (EN as asked); invalid_rate (discount) unchanged.
- [2026-10-15 17:02] AGREED: L-1115 (days, quantities, method codes and the chosen number format in mails and the bell) goes right after L-605c, before the D1/D2 screens.
- [2026-10-15 17:02] AGREED: Ratified: mails write amounts through the PDF's decimal canon, not PHP intl, because NumberFormatter takes floats (float-casts invariant); one canon for mails and PDFs.
- [2026-10-15 17:10] ASSUMED (review): L-605c: the TEJ-code refusal (tej_outside_tunisia) is proved at the API on pay and classify only; the web classify path keeps its generic line, because no screen can produce it (outside Tunisia no TEJ field is offered, inside it the select offers valid codes only), so a routing there would be dead code. Alternatives: route it to the classify form as well.

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
- **Round 1** (product frozen at 68f607f3): 173 new findings, 1,076 of 4,323 cells assessed, so coverage is partial;
  `var/claude/maturity/round1/round-1.md`. Fix-now L-200, L-400 and L-600 are fixed on master (d1efa0e6, 4d677106 and ead9f589, 155cae41 and 2a10c0bf);
  L-401 has its own plan. Found while fixing: L-1088 (a select's hint never drawn), L-1089 (« rubrique » against « catégorie »), L-1090 (the import's refusal says whether a category name exists).
  Round 2 judges the commit that carries those fixes, not 68f607f3.
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
  outside · 53 focus and typing flow (arrival, in-place steps, after a refusal, returned on close, after add and remove,
  across saves and live reloads, Enter and Escape, tab order, focus visible, paste and phone keyboards).
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

**Round 1** (go 2026-10-14 12:00), on the product frozen at 68f607f3 (`web/src` and `api/src` unchanged after it):
1. Lens 53's instrument: the gallery's focus pass (`web/e2e-gallery/`, `GALLERY_FOCUS=1`), facts only and no
   pictures: what holds the focus and the first typeable field on every route, arrived at by a fresh load and from
   inside the app; the sign-in's code step (`codes@twes.local`, never submitted) and its refusal of an empty form; the
   step-up dialog (its refusal mocked, so no write reaches the API) and the account deletion dialog, both cancelled
   with every other write aborted, focus read on opening and after closing. Its own check focuses and blurs
   `#mfa-code` from the page.
2. Five read-only agents, one cluster each (sales and fiscal; catalogue and stock; customers, vendors, expenses,
   export; company, settings, account, sign-in, platform; the whole product: components, shell, fiscal rules, tests,
   promises), each given the ledger and asking 53 lenses of every screen, new findings only, in its own id range,
   with a coverage cell per screen × lens backed by a pointer; `fix-now` flagged for data loss, security, wrong money.
3. One synthesis agent: dedupe, ledger, `round1/round-1.md` (counts, share new, new categories, coverage), the draft
   rubric and the 58 feelings' looks and wordings for the developer's ruling; then a spot check of `ok` cells and the
   fix-now screening.

Known before round 1:
- « Gérer les pages légales » appears twice in « Gérer la plateforme » (Vue d'ensemble and Demande, one section copied):
  it becomes a tab of its own; the overview keeps a one-line status linking to it.
- « Mon compte → Notifications » lists every company in one run: to be restructured (grouped by company, one switch per
  kind across them).
- « Textes »: editable screen texts, as ruled below.

**Fix-now L-820** (done, 70e48091, SPEC § 8 row 280; its e2e also found and fixed L-1091, a sign-out told as an
expired session): turning the company's
two-factor requirement off waits for a recent proof; turning it on, or saying what is already so, asks nothing.
- API: `RequireSecondFactorProcessor` calls `RecentProof::demand` after the company and permission checks, only when
  the company requires a second factor and the request says it should not. `RecentProof`'s docblock lists it.
- Web: `company-security-api.ts` maps the `step_up_required:` refusal through the existing `stepUpAsked`;
  `CompanySecurityFacade.requireSecondFactor` runs through `StepUp.retrying` with the dialog intro the developer picks;
  a declined proof leaves the switch on and shows `company.security.errors.step_up_required`.
- Tests first: three cases in `SensitiveChangesStepUpTest` (off without a proof refused, the requirement kept and no
  audit row; on asks nothing; off when already off asks nothing), each with its mutant; facade and API specs; the
  e2e in `two-factor.spec.ts` signs the owner in again (a sign-in carries no proof), turns it off, meets the dialog,
  proves, and reads the switch off.
- Gates in turn, the licence gate for fr.json's budget, the touched e2e; one commit; a new SPEC § 8 row.

**Fix-now L-1000** (done, 1a6fb8b0, SPEC § 8 row 281; with it L-1094, the leave question's kind, ccd809a5, row 282):
« Se déconnecter » asks the leave question first when something typed
is unsaved, as switching company does; nothing unsaved, nothing asked.
- Web: `AuthFacade.logout()` asks `UnsavedChanges.confirmLeave()` before it ends the session and answers whether it did
  (`Promise<boolean>`, as `CompanyFacade.switchTo` does); the shell navigates to `/login` only on true, so « Rester »
  keeps the page and what was typed. The question lives in the facade, so every way out (the shell's menu, the
  awaiting-approval, two-factor and locked-subscription pages) takes part, and a later one too. The words are the
  existing `form.leave.*` (« Quitter sans enregistrer ? »), no new text.
- Tests first: the auth facade asks and stays signed in on « Rester », asks nothing with nothing unsaved; the shell
  stays put when the facade answers false; one mutant each (the question removed, the navigation unconditional).
  An e2e: a customer form typed, « Se déconnecter », « Rester » keeps the value, then « Quitter » reaches `/login`.
- Gates (api untouched: web and licences), the touched e2e, one commit; a SPEC § 8 row; L-1000 closed in the ledger.

**Fix-now L-425** (done, 0043cd2c, SPEC § 8 row 283): the lent phone says a send went only once the API answered, and keeps
what a short drop did not send (the bad-network standard, § 7 2026-10-13 16:52). The class is the phone's three sends.
- Web, `phone-scanner-page`: each scan, photo and choice is kept with the id it was first sent with, shown « Envoi… »
  until the API answers, then « Envoyé : <code> »; on a lost network it stays listed « Non envoyé » with « Renvoyer »,
  and everything unsent is sent again, in order, when the phone is back online. A refusal other than the network keeps
  today's behaviour (the pairing ends, or the photo is refused).
- Safe to resend: the tab acts once per scan or photo id (`phone-pairing.ts` `seen`) and once per offered choice.
- Kept in memory: it survives a drop, not a reload of the phone's page (the ruling asks for a short drop).
- Tests first: the page spec (sent only after the answer; a network failure kept and shown unsent; « Renvoyer » and
  coming back online resend with the same id; a pairing refusal still ends it), one mutant each; the pairing e2e with
  the network cut by `page.route` for one scan, then restored.
- New words in fr/en (fr.json has 1,067 B left), gates, one commit, a SPEC § 8 row, L-425 closed in the ledger.

**L-605b, slice 1: a refused customer or vendor field said on the field (ruled 2026-10-15 06:51).**
- One shape on both paths, API Platform's own: a 422 problem+json `violations: [{propertyPath, message, code, payload:
  {reason, params?}}]`, the payload serialized through `api_platform.validator.serialize_payload_fields: [reason, params]`.
- Constraint path: the customer and vendor resources' constraints carry `payload: {reason}` (a length also its `max`), the
  codes the import already names; `ResourceRules` takes a cell's reason from that payload, so a file and a form share it.
- Domain path: `InvalidCustomer` and `InvalidVendor` (field, reason, params) answer through one Shared helper as an
  API Platform `ValidationException` of one violation, its payload the reason and params, instead of `"field: message"`.
- Web: `shared/form/field-refusals.ts` reads the violations; `DescriptorForm` shows a `refused` control error as
  `import.rejections.<reason>` (a reason the screens do not know as « Valeur refusée. »), cleared once the field changes;
  the customer and vendor pages put each refusal on its field (`identifiers.x` on `identifier__x`) and the person on the
  first; the « Vérifiez… » line stays for what names no field.
- Tests first: API functional (each path's payload), web specs (reader, error, page), one e2e (an e-mail the browser takes
  and the API refuses, shown under the field), one mutant per guarantee. Slice 2: products, expenses, company profile.

**L-605b slice 2 (approved 2026-10-15).** The same shape on the three remaining forms.
- Inventory: `ProductResource` 12 constraints, `ExpenseResource` 27, `CompanyProfileResource` 18, none with a reason;
  domain throws `InvalidProduct` 34 (2 with a reason), `InvalidExpense` 27 and `InvalidCompanyProfile` 3 (neither takes a
  reason yet); five processors answer `"field: message"`. Product barcodes are out: their own endpoint already refuses
  per row (`BarcodesRefused`).
- API, per form, test first: payloads on the resource constraints (existing import codes where one fits); `InvalidExpense`
  and `InvalidCompanyProfile` gain `reason`/`params` like `InvalidCustomer`; each throw site names its reason; the five
  processors answer through `DomainRule::refused`; `ProductImport::REFUSAL_OF` reads the refusal's own reason first, as
  today, so a file and a form keep one text; `RefusalReasonsTest` scans the three contexts.
- Words: most refusals reuse existing texts (`invalid_text` with its max, `invalid_reference`, `invalid_price`,
  `value_required`, `unknown_tax`, `unknown_tax_regime`, the identifier ones). The rest need new words, shown to the
  developer as one batch before they are written; the budget (fr.json about 0.7 kB of gzip left) argues for a few shared
  texts (one for « this choice no longer exists ») over one per field.
- Web: `ExpensesRefused`, `ProductsRefused` and `CompanyRefused` carry `refusals` (422 only), the facades a `refusals`
  signal, the three pages call `showRefused`; an expense line's refusal (`lines[1].amountNet`) is not a form field, so
  `showRefused` hands it back and the page says it another way (to be ruled with the words).
- Evidence: functional cases per form (each path's payload), page specs, one e2e per form where the browser lets a value
  through that the API refuses, one mutant per guarantee, the three gates, CI.

## Status
<!-- progress-block v1 -->
| # | Step | Size | State | Evidence | Files |
|---|------|------|-------|----------|-------|
| 0 | Round 0: the instruments (inventory, gallery, timings, axe, route × role matrix) | L | done | 5f0b6440 | scripts/** var/claude/maturity/** |
| 1 | Rounds to maturity + unified artifact | L | doing | - | var/claude/maturity/** |
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
