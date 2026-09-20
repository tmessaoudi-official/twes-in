# Archived artefact: Twes Fork Walkthrough, 16 September 2026

A published page titled **"Twes Fork Walkthrough"** (its own headline read *"What only you can check"*) was
deleted. What was only on it is copied here so nothing is lost.

| Original title | Headline | Date | Artifact id |
|---|---|---|---|
| Twes Fork Walkthrough | What only you can check | 16 September 2026, afternoon | not supplied with the saved copy |

The saved HTML was filed under a hexadecimal tool-results name; claude.ai artifact ids are of the form
`Lm3pMnxhiB8sLJyJcwgUs2`, and no such id appears anywhere in the page, so the id is simply not recorded
here. That is the one identifying detail this file cannot state.

## This is NOT twes-in

**Everything below belongs to the OLD project: a FORK of Invoice Ninja, which preceded twes-in.** twes-in is
the clean-room reimplementation that replaced it; the fork was abandoned at the reset of 9 September 2026 in
the sense that matters here — twes-in shares none of its code, none of its data and none of its accounts.
A reader who arrives at the account table below and assumes those are twes-in logins will be wrong in every
particular. The tells, all of them fork-only and none of them true of twes-in:

- the application runs at **`localhost:8537`**, not on twes-in's `:8090` / `:8091`;
- the database is **MySQL**, not PostgreSQL;
- work is measured in **upstream syncs** against Invoice Ninja's releases, and in a budget of registered
  patch files under **`patches/web/*`** against upstream's own source;
- the owner account is **`admin@example.com`**, an Invoice Ninja default;
- the screens are upstream's React admin, and the deferred mobile client is upstream's Flutter one.

**This file is historical and superseded.** It is a record of what was built, reported and ruled on a
handful of days in September 2026, not a statement of how anything works now. Nothing here is a source to
build from — in particular, `CLAUDE.md` § "Licensing invariants" forbids upstream code entering the twes-in
tree, and this page describes a tree in which upstream code *was* the base. It is kept so that a figure, a
ruling, a known issue or an account can be found again, and so that a later reader can see what the fork had
reached before it was set aside.

No tracked file in this repository and no memory note refers to the page, to `localhost:8537` or to
`patches/web` [Verified 2026-09-20: `git grep` over the working tree and `grep -rn` over
`~/.claude/projects/-stack-projects-twes-in/memory/` both returned nothing], so its deletion breaks no link.

---

## 1. Builds the page reported

The page was rewritten in layers rather than replaced, and each layer names its own tree and image. They are
collected here because they are the page's own provenance and appear nowhere else.

| When | Tree | Image | Note |
|---|---|---|---|
| 8 September, evening | not named | `fc1a6a3c` | Tier B's checks were written against tree `1d10691a` |
| 15 September, 13:56 | not named | not named | rows 52, 58, 60, 61, 62 entered the image |
| 16 September, 10:25 | `788cdb0339` | `21393d35e386` | row 64 entered the image |
| 16 September, afternoon | `b89edd4d98` | `35b82d8485c4` | rows 65, 66, 67 and the row 39 re-ruling |

Four application containers — app, scheduler and two queue workers — were recreated onto each new image and
checked one by one. MySQL, Redis and the uploads volume were never touched, so the data and the signed-in
session survived every rebuild; the code is baked into the image rather than mounted.

**A caveat the page records about its own timestamps.** The 8 September build ran at 22:56 and was a
*complete cache hit*, because the tree had not moved since the 22:31 build — so the image was genuinely
current and its timestamp legitimately did not move. Both times are the developer's clock; Docker reports
that date in UTC, which is how the first version of the page put 20:31 in its "Image built" cell.

Addresses: the application at `http://localhost:8537`, the client portal at
`http://localhost:8537/client/login`, and a locally served copy of upstream's documentation at
`http://localhost:8537/twes-docs`.

## 2. The accounts

Created 16 September; the page states all six logins were tried and found working. One account per kind of
person who uses the application. The shop, billing and read-only accounts are ordinary users with ticked
permissions, exactly what *Paramètres → Gestion des utilisateurs* creates, so any of them can be opened there
and its rights changed. Reproduced with the passwords removed — see the note under the table.

| Who | Where | E-mail | Password | What they can do |
|---|---|---|---|---|
| **Owner** | App | `admin@example.com` | *(not reproduced)* | Everything, including the account itself. |
| **Manager** | App | `gerant@example.com` | *(not reproduced)* | Administrator but not the owner: every screen and setting. |
| **Warehouse** | App | `magasin@example.com` | *(not reproduced)* | Sees clients, products and invoices; creates and edits delivery notes; **cannot edit clients**. Use this one for the second half of row 64's last check. |
| **Billing** | App | `facturation@example.com` | *(not reproduced)* | Creates and edits clients, invoices, payments and credit notes; **sees delivery notes but cannot create them**. |
| **Read-only** | App | `lecture@example.com` | *(not reproduced)* | Sees everything, changes nothing. |
| **Client** (SIPA) | Portal (`/client/login`) | *(the developer's own address)* | *(not reproduced)* | SIPA's contact in the client portal: its invoices, and its delivery notes when the portal setting for them is on. |

> **The six passwords and the portal address are deliberately not carried into this repository**
> (developer ruling, 2026-09-20). The fork stack they belonged to no longer exists, so they open nothing;
> what a password table in a tracked file does do is trip a secret scanner and teach the wrong habit. The
> rest of the table is kept because *which* accounts existed, and what each could do, is the part that
> made the walkthrough worth salvaging. The same ruling opened a row on generating an installation's
> operator password rather than publishing one — see `docs/SPEC.md`.

Two notes travelled with the table.

- **checked — the permissions are enforced by the server, not only hidden on screen.** Warehouse and
  read-only are refused when they save a client, and billing is allowed. Billing and read-only are refused
  when they create a delivery note, and warehouse gets through. All three can read the delivery-note list.
- **note — no e-mail goes out.** The install writes e-mails to a log file, so « mot de passe oublié » and
  user invitations never arrive. These passwords were set directly. The same behaviour is why the row 66
  check says that if the backup link never arrives, the file must be asked for.

**An accident in the developer's data, disclosed on the page.** While checking that the billing account can
edit clients, the check really saved: SIPA's public note became « x ». It was set back to empty. The client's
activity history keeps one entry for that edit and nothing else changed. The old value was not in any backup
— SIPA was created on 14 September, after the last one — but empty is what its private note holds and what
the client form saves, so it was almost certainly empty; if a public note had been written on SIPA, it would
need writing again.

## 3. The numbered rows

Every automated lane was reported green for each of these: the whole module suite of **1,091 tests**, the
patch round-trip and the smoke boot of the image. The check items below are what those could not prove.
Where a commit sha is given it is the page's own; three rows carry none and that is stated per row.

### Row 67 — « Enregistrer qui a reçu les marchandises » turns back on — `2480a6cb54`

The reported bug: switching the setting off worked, switching it back on never stuck. Turning it on sends an
empty value, upstream's settings save stores that as an empty string, and the screen read an empty string as
« off ». The server already read it as on, so only the screen was wrong.

Check: at *Réglages → Bons de livraison*, switch it off, save, reload — off survives and « Reçu par »
disappears from the BL editor; then on, save, reload — it stays on and the field is back. The company was
stored in exactly the state that had been stuck, so the first reload should already have shown it on.

### Row 65 — merging two clients keeps the delivery notes — `8adf478d44`

Upstream's merge moves invoices, quotes and payments, then permanently deletes the merged client — and every
delivery note, return and stamp duty exemption of that client was deleted with it. They now move to the
client that is kept, in the same transaction. No new registered file.

Check: two clients, the second with a delivery note (and a stamp duty exemption if desired) → second client →
*Plus d'actions → Fusionner* → choose the first. The first client's delivery notes should now include the
second's with lines intact, and its exemption should have moved too. The page insists on **two throwaway
clients** — a merge cannot be undone, and SIPA was the only real one.

### Row 66 — a company backup carries the delivery notes — `5e91669026`

A backup used to contain no delivery note, return, stamp duty row or numbering counter, and a restore dropped
the files attached to a BL. Now every module table travels in the backup, and a restore puts it back pointing
at the restored clients, products, contacts and invoices. The stated cost: **one registered patch of three
event lines** in upstream's backup jobs, with the count moving **43 → 45** and the floor unchanged, because
it is offered upstream as a generic hook. (The page describes one patch and a count rising by two; it does
not reconcile the two figures, and neither does this file.)

Check: *Réglages → Sauvegarde / Restauration → Sauvegarde*, then add a company to the same account and
*Restaurer* with « Importer les données ». The new company should show SIPA, the products and invoices **and**
the delivery notes with their lines, « Reçu par », returns, stamp duty rates and a BL's attached files, and
the next BL number should continue from the existing counter. Mail is written to a log here, so if the backup
link never arrives the file must be asked for.

### Row 39, re-ruled — only an OLD backup is refused — `b89edd4d98`

As ruled by the developer: the upload is no longer refused outright. The restore job reads the backup, and
only a backup made **before that day** — which holds no delivery notes — is refused, and only when it would
wipe the company's notes, returns or exemptions. The owner gets upstream's « import failed » mail with the
counts, in the company's language, and nothing is deleted. A backup made from then on restores normally.

*What the first ruling of row 39 said is not on the page* — only that the upload used to be refused. The
original rule is therefore lost here.

Check (only possible with a backup made before that day): restore it with « Importer les données » into a
company that has delivery notes. The data should be left untouched and the failure mail, in the mail log,
should name how many notes, returns and exemptions it protected. Settings alone still restore.

### Row 38 — synced with upstream — no sha given

API **v5.13.37 → v5.13.40**, web application **07.09.2026.1**. The three-decimal rounding patch was
re-derived onto upstream's rewritten surcharge-tax code; totals parity and every lane were green. Nothing to
click.

- **open — upstream's own change: surcharge tax differs for PEPPOL clients and for negative surcharges.**
  v5.13.40's server taxes a surcharge for a PEPPOL e-invoicing client and never taxes one of zero or less;
  the live preview in the browser does not yet do the same, so the preview can disagree with the saved total
  in those two cases only. Stamp duty is not affected. Recorded, not fixed.
- **next — « Bon de livraison » beside « Facture » everywhere.** The places are mapped (product list and row
  actions, the client's « Nouvelle ressource », the + menu, shortcuts, quote conversion, permissions, search,
  reports and so on). As ruled, each one was to be put to the developer before being built; nothing there was
  built.

### Row 52 — who the delivery note is addressed to — `3c6412c4df`

Ruled: a delivery note gets the invoice's invitations. The client's contacts appear as checkboxes in the
editor, the checked ones receive the e-mail, and the first one checked is the person the PDF is made out to.
Each mail can carry « Voir le bon de livraison », a link that opens the note on the client portal. No new
registered file; the count stays **43**.

Checks:

- The client's contacts appear as checkboxes under the client, like an invoice's. Contacts with « Envoyer un
  e-mail » on are already ticked; a copy-only (CC) contact is not listed. Choosing a different client
  re-ticks that client's contacts.
- Only the ticked contacts get the e-mail — one mail, to that contact, **even if its « Envoyer un e-mail » is
  off**, as on an invoice. The toast counts one recipient. A copy-only contact is copied on that mail. A
  locked (unsubscribed) contact is never mailed.
- The PDF is made out to the first ticked contact, not the client's primary contact.
- The mail's button opens the note on the portal: it logs the reader in as that contact and opens the note.
  In the editor the contact then shows « Voir dans le portail » with a copy button. With the portal password
  on, the link lands on the portal's login page first. On a draft, or with « publier sur le portail » off,
  the mail has no button.
- A delivered note can change who it is addressed to: the boxes stay open on a delivered note (not on a
  cancelled one), the save goes through, and « Corrections » gains **no** line — who a note is sent to
  changes nothing it says.
- An existing note was given its contacts: a BL created before the build opens with its « Envoyer un e-mail »
  contacts already ticked, the migration having given every existing note the same default a new one gets.

Notes:

- **design — unticking everyone does not leave a note with no one.** On save the server re-ticks the client's
  default contacts, exactly as an invoice does; a note nobody can be sent to is never stored.
- **design — a company that already customised its BL template gets no button.** Only the shipped default
  gained `$view_button`; a saved template belongs to the company, so `$view_url` or `$view_button` must be
  added to it from the sidebar.
- **design — with the portal password on, a contact with no password lands on the login page.** Upstream
  shows its « choose a password » form there, but that form only knows upstream's documents; the login page
  offers the reset link.
- **open — no delivered, opened or bounced status yet.** The note records when each mail left and when the
  link was first opened; the invoice's e-mail history panel was ruled out of this row.

### Row 58 — the delivery note's own terms and footer — `5249d55a9a`

Ruled: a delivery note gets its own terms and footer with the invoice's complete behaviour. So it works the
way an invoice does — company, group and client defaults on « Paramètres par défaut », copied onto a note
when it is *created* and never re-applied when it is edited; the two tabs and « enregistrer comme défaut »
toggles in the editor; printed under the delivery note's own label. The invoice made from a note keeps the
*invoice's* defaults, as ruled at 15:27. This is the one new registered file the ruling budgeted
(`patches/web/23`); the count became **43**, floor **25**.

Checks:

- Two rows, first, on *Réglages → Paramètres de l'entreprise → Paramètres par défaut*:
  **« Conditions du bon de livraison »** and **« Pied de page du bon de livraison »** above the invoice's
  rows, each with the override checkbox. Turning the delivery module off under *Modules activés* makes both
  rows disappear.
- A new note takes the defaults on save, an edit never re-applies them. The editor's notes card now has four
  tabs — Notes publiques, Notes privées, Conditions, Pied de page. On the new form both boxes are empty;
  after *Enregistrer* they hold the company defaults. Editing the draft and clearing Conditions leaves it
  empty, as on an invoice. A group or a client with its own box ticked wins over the company.
- « Enregistrer comme … par défaut » writes the company only: *Paramètres par défaut* shows the new terms, a
  client group's page does not. Unsaved changes on a settings screen are saved first rather than lost —
  upstream's invoice does the same. On a delivered note both toggles are locked.
- The PDF prints them under the BL's own label: « **Conditions du bon de livraison:** » then the terms, never
  « Conditions de facturation », with the footer at the bottom. A BL with no terms shows no label. A BL made
  before the build prints neither, as before.
- The invoice keeps its own defaults, not the BL's; the template sidebar offers `$terms` and `$footer`, and a
  sent BL mail substitutes the note's own.

Notes:

- **design — plain rows, not a boxed card.** The ruling said "a card"; upstream's *Paramètres par défaut* has
  no cards at all — every document is two bare rows — so the BL's are two bare rows too. The heading was
  offered if wanted.
- **design — the editor's two toggle labels are ours, not upstream's.** Upstream's French « Définir comme
  pied de facture par défaut » names the invoice; on a delivery note that is the exact wording this row
  removes.
- **open — under a JSON-designer design a hand-typed `$terms_label` still says "facturation".** No stock
  design uses it, and upstream's own invoice prints no terms label on that path at all. Recorded in Known
  issues.
- **open — a BL's public and private notes are still stored unsanitised.** Terms and footer are now cleaned
  the way an invoice's are; the two notes predate this row and were not widened without a ruling. The portal
  never shows them as HTML — only the PDF does. Recorded in Known issues.

### Row 60 — correcting a delivered note, with a log — `0191b1ef75`

Ruled: a delivered or invoiced note may have exactly three things corrected — when it was delivered, who
received it, and which invoice it is meant for. Nothing that touches lines, prices, quantities or stock
opens. Every value that moves is written down with its old value, its new value, who changed it and when. A
cancelled note stays locked, and a draft still uses the editor, where every field is open anyway.

Checks:

- On a delivered note exactly three fields open — « Livré le », « Reçu par » and « Facture cible ». The
  client, the date, the lines, the notes and the custom fields stay locked. The header offers
  **Enregistrer** again instead of « Plus d'actions » alone.
- Correcting the receiver produces a success toast and a **Corrections** card under the editor: the moment in
  the client's time zone, who, the field, before « — », after the name. The card is absent on a note that has
  never been corrected, on purpose.
- Correcting the target invoice logs both invoices by their **numbers**, not their ids. The select offers
  only that client's live drafts, as it did on the draft. If the chosen invoice is sent from another tab
  before the save, the save is refused and *nothing* in it is written — not even a receiver typed at the same
  time. Leaving an already-sent target untouched does not block a correction of the other two fields.
- Saving without changing anything adds no row: a value put back to what it already was is not a correction.
- A cancelled note offers no *Enregistrer*; an archived corrected note still shows its Corrections card and
  cannot be corrected any further.

Note:

- **open — touching the « Livré le » box logs a correction of a few seconds.** Marking a note delivered
  records the moment to the second; the box shows minutes. Change the time and put the same minute back, and
  the save sends `…:15:00` over a stored `…:15:37` — a real change, so it is logged. A save that never
  touches the box sends no moment at all. Recorded in the plan's Known issues, with the offer to handle it
  differently.

### Row 61 — an unnumbered draft reads with its date and amount — no sha given

Check: on a client with two drafts that have no number yet, both « Facture cible » on a new BL and « Ajouter
à la facture » should read « Brouillon · date · montant », with the date in the company's date format and the
amount in the **client's** currency. Only reachable on a company that numbers invoices when they are sent —
the Tunisia preset does; a company that numbers on save has no unnumbered drafts to show.

Note:

- **ruled — the BL list and the slider still say a bare « Brouillon ».** Left out of row 61 by the
  developer's ruling: showing a date and amount there needs the delivery to carry them from the invoice.
  Recorded in Known issues.

### Row 62 — the unsafe-pattern refusal names where the pattern was set — no sha given

Check, at *Réglages → Numéros générés → Bons de livraison*: give the **client** a pattern with no date token
while the company counter resets yearly and create a BL — refused with « … défini dans les paramètres de ce
client … ». Move that pattern to the **group** (client box unticked) — « … défini dans les paramètres du
groupe de ce client ». The company-level sentence cannot be reached from the screen, because the company save
refuses that combination first.

### Row 64 — « Reçu par » picks a contact — `aac69803e9`

Ruled: « Reçu par » works like the product field on an invoice line — the client's contacts to choose from,
any typed name kept, and « Ajouter un contact » at the bottom of the list adding one to the client. The note
still stores a name, so the printed note does not change. No new registered file (the count stays **43**) and
no migration. For the last check the user who cannot edit clients is `magasin@example.com`.

Checks:

- Picking one of the client's contacts: clicking the field opens a list of the client's contacts, each with
  its e-mail under the name. Pick one, click elsewhere, the name stays; save and reload and it is still
  there.
- Typing a name that is not a contact — « Chauffeur Sami » — then clicking elsewhere or pressing Tab: the
  text stays as typed, with no trailing space, and is saved as before.
- Emptying the field: delete the name, click elsewhere, save, reload — the field is empty and the printed
  « Reçu par » row is blank.
- « Ajouter un contact » opens a small window with prénom, nom, e-mail and téléphone. *Enregistrer* stays off
  until a first name or an e-mail is typed. Saving with a first name only closes the window, puts the new
  name in « Reçu par », and does **not** tick it in the note's « Contacts » list. Opening the client shows
  every previous contact still there, the primary contact unchanged, and the new contact with « Envoyer un
  e-mail » off.
- On a delivered note the picker and « Ajouter un contact » still work and saving records a correction; a
  user without « modifier les clients » sees the list without « Ajouter un contact ».

Notes:

- **ruled — the note keeps the name, not the contact.** Renaming or deleting the contact later leaves the
  note as it was delivered.
- **provisional — a contact with no first name is listed by its e-mail.** Same rule as the note's
  « Contacts » list beside it; two contacts with the same name appear once.
- **provisional — « Ajouter un contact » is offered during a correction too.** It changes the client, not the
  note, so the lock on a delivered note does not apply to it.

### Rows 17, 21 and 23 — every refusal speaks French

Reported from the browser: an English sentence on a French screen. Chasing it turned up that the module
refuses through **three** unrelated code paths that all end in the same red toast, each needing to be found
and translated on its own — **32** thrown exceptions (row 17), **18** form-validation messages across 10
request classes (row 21) and **16** sentences returned directly by 9 controllers (row 23). A test for one is
structurally blind to the other two, which is why they are three rows rather than one. All three ended in
French and Arabic as well as English, with three test suites holding them there.

**The counting is the lesson, and the page says so.** Every one of those three rows was written with a number
produced by a text search, and every one was wrong: 8 became 32, 12 became 18, 13 became 16. A search cannot
see a sentence that sits in a default argument or spills onto a second line. The last row was re-counted with
PHP's own tokeniser, and a test now tokenises all nine controllers so a new English sentence cannot be added
without the suite going red.

The three checks, one per mechanism:

- A validation refusal: a line quantity of `0` on `/deliveries/create` must read **« Chaque ligne doit livrer
  une quantité strictement supérieure à zéro. »** The English of that sentence was measured coming back from
  the stack while row 21 was written — one POST, one untranslated refusal — so this check is about the
  translation reaching the screen rather than only the lang file.
- A controller refusal: opening a BL and replacing the id in the URL with nonsense must read **« Bon de
  livraison introuvable. »** A malformed id, a non-existent id and another company's id all say exactly the
  same sentence, **deliberately**: saying "malformed" for one and "not found" for another would confirm to a
  stranger which ids exist on the server, which is a way of enumerating a customer's documents. A test drives
  all three and fails if they ever start to differ.
- An exception refusal: consolidating the same BL twice must read **« Le bon de livraison BL-… figure déjà
  sur une facture. Supprimez cette facture pour le libérer. »** — in French, and naming *which* note. A
  refusal that does not name the document is unusable when six are in hand.

### Row 18 — a reported bug that turned out not to be one

**Conception du bon de livraison** on *Modèle de facture* was reported as a dead setting and asked to be
hidden. It is not dead, and hiding it would have broken a working upstream feature: that setting styles an
*invoice* reprinted as a delivery note — upstream's own « Bon de livraison (PDF) » action on an invoice — and
that route is live. What made it look dead is that the *bulk* version of that action really is an empty stub
upstream, with a bare `break` where the code should be. So the row was re-aimed: instead of hiding a live
control, it now **says whose document it styles**.

That relabel cost a permanent patch — upstream's own screen now carries a sentence about a fork entity, which
is not something upstream would ever take, so it never retires. The count became **42**, floor **24**. The
page states this out loud because the alternative was spending the same permanent patch to make a working
feature unconfigurable.

Checks: the row should read « Conception du bon de livraison (reprise de facture) » with a help line —
*Applique un modèle à une FACTURE réimprimée en bon de livraison — l'action « Bon de livraison (PDF) » d'une
facture. Vos propres bons de livraison ne sont pas concernés : ils suivent le Modèle de facture.* With the
module switched off under *Modules activés*, upstream's own label must come back verbatim, with no mention of
the fork — the relabel exists to stop the row misdescribing a screen the fork added, and with the entity off
there is nothing to disambiguate. And the setting must actually do something: pointed at a design other than
the invoice design, an invoice's « Bon de livraison (PDF) » reprint must follow it. That last check is what
would prove the retraction right rather than merely argued.

## 4. Thirteen things that are not bugs

The page's loudest block, headed "Read this first". Each was ruled or accepted deliberately and recorded in
the plan; seeing one was not a finding.

1. **design — stock moves on the delivery, not the invoice, on purpose.** The one line the reset changed.
   Ownership is off whenever the setting is absent, because an absent setting must behave exactly as upstream
   always has, and the previous account predated the ruling so never received it. The preset sets it, so
   goods leave stock when the BL is delivered and the invoice moves nothing.
2. **design — the e-mail template *preview* substitutes more variables than the sent mail does.** Typing
   `$amount` into the Bon de livraison template renders `37,500 DT` in the preview pane; the sent mail
   contains the eight literal characters `$amount`. Upstream renders every preview through a real *invoice*,
   and that is upstream's code. It is why the variables sidebar on that tab lists only 37 entries instead of
   the invoice's full set: **the sidebar is the honest surface, the preview is not.** Type only what the
   sidebar offers and the two always agree.
3. **ruled — inclusive-tax documents truncate TND at 2 decimals.** Four getters round to 2 even for a
   3-decimal currency, because the upstream test pins it and upstream tests are not edited. Exclusive
   documents are correct to the millime.
4. **open — a BL ignores a delivery-note design** and falls back to the invoice design. Upstream looks the
   design up through invitations, which a transient invoice has none of.
5. **open — a failed BL e-mail surfaces late.** Render and send run on the queue, so a Chromium or mail
   failure lands in `failed_jobs` after the toast already said the mails were on their way — the same shape
   as upstream's invoice mail.
6. **open — deleting a delivery return leaves the restored stock un-restored.** Behaviour undefined, carried
   as an accepted-open.
7. **open — a draft credit survives its delivery's cancellation.**
8. **ruled — no bilingual FR/AR delivery-note template.** Deferred by ruling.
9. **ruled — nothing works on mobile.** Flutter parity is deferred; `mobile/` carries no fork code
   whatsoever. It was the one plan row still open, and closing the milestone left it open on purpose.
10. **design — a client with no company name sorts by an empty string while the list shows its contact's
    name.** The Client column displays upstream's `present()->name()`, which falls back to the primary
    contact; the sort orders by the real `clients.name` column, because that is what the database can index.
    So for a contact-named client, display order and sort order legitimately disagree. Every upstream list
    has the same property.
11. **design — a client with neither a name nor a contact reads `No Contact Set`, in English, in a French
    UI.** That string is upstream's own and deliberately left untranslated: translating it here would make
    the cell differ from every other client cell in the application, which is the opposite of what the
    milestone was for.
12. **open — the shared padding field is labelled « Marge interne du numéro » in French, which does not mean
    what it says.** It means zero-padding — `0001` is four. "Padding" was read in the CSS box-model sense by
    whoever translated it, and "marge" reads as a profit margin on a screen with no money on it. The
    developer asked what it meant on 1 September, which is why the fork tab used to carry its own label; now
    that the fork tab uses the shared field, upstream's string is what is read. It is an upstream translation
    bug, not a setting — **worth one small PR, and it would fix it for every Invoice Ninja user in French.**
13. **design — Client appears third in the delivery list only if the column picker was never opened.** A
    default reaches nobody who has already saved a column choice — the saved preference wins, exactly as on
    invoices. A list with no Client column wants the picker, not a bug report.

## 5. What the fork added — six features

Everything in this list was built, tested and in the final build. None of it is a separate "Twes" area of the
application: every setting sits on the upstream screen that owns the feature it extends, which is the
property the last milestone was spent on.

| # | Feature | Where it lives |
|---|---|---|
| P1 | **Stamp duty — *droit de timbre*.** A fixed per-document duty with a dated rate table and exemptions, occupying one of upstream's four surcharge slots so it lands inside the totals rather than beside them. | *Settings → Tax Settings*; visible as a line on any invoice, quote or credit |
| P2 | **Markup and profit rate.** A per-product markup over cost with an optional price floor, applied live in the invoice editor and again on the server. | *Settings → Products*; cost and markup fields on each product |
| P3 | **Tunisian money, correct to the millime.** TND carries three decimals end to end — both totals engines, the PDF, the API. Upstream seeds TND, BHD, OMR and JOD at two decimals; fixed here and offered back upstream. Also the `Africa/Tunis` timezone, which upstream does not ship, and the amount-in-words footer on invoices. | throughout |
| P4 | **Delivery notes — *bons de livraison*.** A full document entity: its own list, editor and PDF, e-mail to the client, a client-portal view, partial returns, stock movement on delivery, consolidation of several notes into one invoice with provenance both ways, its own numbering pattern and counter, and its own permissions. The largest of the six. | throughout |
| P5 | **One-command account provisioning.** `twes:create-account --preset presets/tunisia.json` builds a ready-to-use Tunisian company — taxes, formats, numbering, portal and e-mail behaviour — instead of forty settings clicked by hand. It is how the stack was rebuilt and how a deployment would start. | CLI |
| M1 | **Native settings integration.** The fork's own settings were moved onto upstream's four native screens so nobody has to know which features are "ours" before knowing where to configure them. | Products, Tax Settings, Generated Numbers, Client Portal |

## 6. The Tunisia preset, and the data behind the checks

**What the preset set, so that nothing had to be switched on by hand:** Tunisia; **TND at three decimals**;
**`Africa/Tunis`**; **TVA 19 %**; French; the country's date and number formats. Delivery notes on, priced at
invoice time. **Stamp duty** on, with the `0,100` TND rate row. **Markup** on, with the **price floor** that
refuses a product sold below its own cost. Product cost fields on. Stock moving when goods leave on the BL
rather than when the invoice is raised. And the numbering rules, including the **padding of `10`**.

**The padding is worth its own paragraph**, because the page flags it as a decision left open. The preset
sets the shared padding field to `10`, the native maximum — so the next BL numbers `BL-2026-0000000001` where
it used to be `BL-2026-0001`. That is what making the field shared costs, and it is what was asked for.
Nothing is renumbered retroactively. Setting it to `0001` brings `BL-2026-0001` back **and makes invoices
four digits too, because it is one setting**. The page asks which is wanted and offers to change the preset;
no answer is recorded on it.

**The preset also grew by twenty settings**, read off the developer's own live account rather than picked
from the key reference: dates all-numeric `d/m/Y` and 24-hour time; invoice, quote and purchase-order
signatures required with the signature on the PDF; portal dashboard, password, tasks and both upload toggles
on; PDF, UBL and document attachments on e-mails.

**The database contents, both tallies stated as counted rather than assumed.** On 8 September: **1 client, 2
products, 4 delivery notes and 0 invoices**. On 16 September: **1 client, 1 product, 3 delivery notes and 2
invoices**. The page offers no explanation for the product going from two to one or the notes from four to
three; it simply reports each count on its own day. *(Flagged here rather than reconciled.)*

That emptiness mattered to the checks. With no invoice ever raised on the account, the page names the
consolidation block — adding two delivered BLs to one invoice, and the client-balance check after it — as
**the largest untouched block on the whole page**: the entire delivery-to-invoice path was unexercised.

**Four setup steps were kept on the page for their reasoning rather than for the doing:**

1. Log in and confirm the company came up Tunisian — French UI, TND currency. English and USD would mean the
   preset did not apply and nothing below was worth testing.
2. Create one client, with **two e-mail contacts** if the per-contact greeting check is to mean anything.
3. Create one product with a description and a **cost**. The description is what a later check removes a
   column to look for; the cost is what the markup and price-floor checks read. Saving it priced *below* its
   cost should be refused by the floor.
4. Raise a delivery note with one line of that product and mark it **Livré**. **Leave the price box empty** —
   the catalogue price shows as a placeholder, and typing it back in is what marks the line hand-priced and
   stops the invoice re-pricing it later. An undelivered note is a draft, moves no stock, and is not what the
   PDF checks want.

**Prices are off on a new note, so out of the box the BL prints three columns.** That is the module default
and the preset does not change it: many businesses hand the warehouse a priceless note. Turning « Afficher
les prix sur le bon » on makes the note follow the invoice's column list — and on this account that prints
**five** columns (item, description, prix unitaire, quantité, total) even though the list holds seven:
upstream hides *Remise* whenever product discounts are off, and drops *TVA* when no line carries a per-item
tax rate. Both are off on a new company, and an invoice on the same account prints exactly the same five. So
five is right; the other two are upstream's *Activer la remise sur les produits* and per-item tax rates, and
they would appear on the note and the invoice together.

A database dump was taken by the reset script at step 1 even though the developer said not to bother; the
script does it regardless and was not edited to override the instruction for one run. It sits in
`var/claude/backups/`, gitignored, and nothing needs it.

## 7. The unnumbered checks — Tiers A to E

These sections carry no row number on the page. They are condensed here; the design and open notes inside
them are kept in full because they are rulings.

### Two defects that stopped a delivery note saving at all

A BL would not save a catalogue-priced line. It was real, and the investigation found a second defect behind
it that nothing would ever have reported: the editor names a product by its **key** — the string in the
picker — and the server insisted on an internal id. Fixing the first exposed that when a line's product is
*changed*, the form posted the **old** product's id alongside the **new** product's key, so the server priced
the line from the product just replaced. Same number of lines, same names on screen, wrong price, nothing
logged.

Checks: a line with no price typed must save and come back with the catalogue price applied; changing a
line's product must come back at the **new** product's price (use two differently priced products or the
check cannot fail); and two products sharing one key must be **refused with a message naming the key** rather
than silently resolved to one of the two — a resolver that guesses is the same class of bug as the one above.

### The BL editor takes upstream's document shape

The editor gained the anatomy every upstream document editor has: a **Produits** section tab and **no
Tâches** tab, because a delivery note delivers goods and there is no such thing as delivering an hour; a
**+ Nouveau client** action beside the client picker, which had been deliberately suppressed with an explicit
prop and should not have been; and **Créer**, **Documents** and **Paramètres** tabs, with *Utilisateur
assigné* moved into Paramètres where every other document keeps it. The notes got a public rich-text note and
a private one and — at the time — *no* terms or footer field, **superseded on 15 September by row 58**, which
added the Conditions and Pied de page tabs beside them.

One check is singled out as uncovered: switching tabs mid-edit and confirming the lines, the client and the
notes are all still there. **"This is the one property on this page that no test covers, and I want to be
plain about that"** — the suite can prove each tab renders, but nothing in the repository renders a React
component, so it cannot prove state survives the switch.

An earlier note on the same editor: the old form was one card of label-left / control-right rows — upstream's
*settings* idiom applied to a document, which announced on sight that the entity was not one of upstream's.
The cards' *contents* are deliberately not the invoice's: no due date, partial, PO number or terms, because
the server has no rule for any of them and would silently drop them.

### The documentation served locally, and the fonts

The docs are served from the machine at `/twes-docs`, and until that day every page still fetched its two
typefaces from Google — so the "local" copy was local except for the part that made it readable, and on a
disconnected machine it rendered in a fallback font, silently. The builder now downloads both families into
the site. **511** shipped files carried the Google stylesheet before; **0** after, with thirteen font files
served from the stack.

**A correction to how that was verified, which the page makes about its own method.** The first version of
the check grepped the *build directory* — which proves what the builder wrote, not what a browser receives.
It now fetches the stylesheet through the running server and asserts each of the thirteen fonts comes back as
`font/woff2` with the right four magic bytes. "The two are different claims and only the second one is about
you."

Five related items shipped with no screen to look at:

- **The application's documentation links are repointed by one build transform, not twenty patches.**
  Forty-four links across twenty upstream files said `invoiceninja.github.io`. Editing those files would have
  cost twenty registered patches forever; a Vite plugin rewrites them as the bundler reads them, so the files
  stay pristine and the cost is **one**. Serving the docs locally added **zero** more — the Caddy routes live
  inside a block the repository already owns, and the byte-compare against Laravel Octane's own config still
  passes.
- **Nine more links moved — the ones that had stopped telling the truth about the install.** The rule, and
  why nine moved and roughly forty did not: *change a link when it misdescribes THIS installation, keep it
  when it still tells the truth.* Four support links (forum, Slack, status, the mobile page), two Peppol
  credit buttons and the **Étiquettes personnalisées** reference now point locally. That last is a
  correctness fix rather than a de-pointing: it used to open upstream's `master` translation table, which
  lists keys this pinned install does not have and omits the `twes::` keys it does; it now serves the
  container's own `/app/lang`, in the reader's locale as well as English. Deliberately kept: the About
  modal's attribution, Zapier, the Chrome extension, the pricing banner, and the white-label purchase route —
  repointing that last one would have deleted the developer's own way of buying the licence that permits
  removing the branding.
- **The local docs no longer need the internet, and no longer invite the reader back out.** Nineteen absolute
  links inside the docs still pointed at the public site, **eleven of them inline images**, so the "local"
  copy was still fetching gateway logos over the network to render. All nineteen are now site-relative, so
  they survive a change of host or port. Upstream's own broken `terms_of_service` slug is fixed in passing.
  The site chrome is adjusted through a `--config` override rather than by editing upstream's config, which
  keeps their file byte-clean: "Edit this page" is gone, the Community column is dropped and "Open App"
  points at this stack. The GitHub and Website attribution links stay where upstream put them.
- **ruled — the translated doc links are not being repointed, and that is a decision.** Measured rather than
  assumed: what looked like eighty-eight links across forty-four locale files is *two* keys replicated across
  languages — one client-facing, which the developer's own ruling puts out of scope, and one about migrating
  a hosted account, which cannot be reached in this fork. The price would have been forty-four registered
  files.
- **given — the documentation is served locally and every link resolves**, with no fork needed. Upstream's
  docs site is built into `var/docs/src/build` by `docker/twes/docs-build.sh` and served by two routes in the
  stack's own Caddy config — same origin, so the panels that fetch markdown need no CORS.

Two retractions attach to that work. **An earlier build of the page said six panels would look broken and
that this was the repoint working. That was wrong.** The links had been pointed at a localhost path nothing
served, so all of them dead-ended — including the "list of variables" link in *Numéros générés*, which is how
it was found. "Pointing links somewhere empty is not a feature working."

And **the one number worth knowing: 35 of 39.** The help icon on nearly every settings screen carries its own
documentation link, and four of the thirty-nine fail — `en/documents` and three `advanced-settings` links.
They fail *identically on invoiceninja.github.io*, measured side by side. They were broken before any of this
and are upstream's to fix; the local copy matches upstream exactly rather than improving on it. Everything
else — all seventeen page links, the example-import CSV, and the six panels that fetch markdown and render it
inline — was fetched and returns real documentation.

Two surfaces still point at Invoice Ninja and neither is a defect: the two translated strings inside the
bundled `en.json` (ruled out above), and `api/public/main*.dart.js` — upstream's pre-compiled *Flutter* admin
portal, fourteen doc links per file — which is out of a build transform's reach entirely and is not reachable
on this install: `BaseController::flutterRoute()` serves it only when `accounts.set_react_as_default_ap` is
false, it is true here, and the React UI exposes no switch for it.

### The delivery-note e-mail and its placement

- **Bon de livraison is the first tab on *Modèles & Rappels*, before Facture.** The label is upstream's own
  translation key, so it reads in French without shipping a string. One caveat that is not a bug: the page
  still *opens* on Facture, because upstream hardcodes the initial tab independently of tab order — order was
  what was asked for, and changing which tab opens is a behavioural change to their screen nobody asked for.
- **Bon de livraison sits directly above Factures in the left menu.** It used to sit after Bons de commande,
  with four unrelated entries between it and the invoice screen an operator alternates with. Free to move:
  that file was already a registered patch.
- **The template opens on real default text, not an empty box** — subject `Bon de livraison $number de
  $company.name` and a matching body. That default is served by the API and is the *same* text the mailer
  falls back to, one source, so what is read there cannot differ from what a client receives.
- **A custom subject persists** in the company settings blob and cascades client → group → company, so the
  per-level *Enabled* checkbox on that tab is a live control, not decoration.
- **The variables sidebar** offers the note's own `$number`, `$date` and `$invoice`, plus **upstream's
  complete client, contact and company lists**, resolved by upstream's own engine so a future sync enriches
  them for free. `$client.credit_balance` is there on purpose — an attribute of the client, not a price on
  the note. Deliberately absent: `$amount` and `$balance`, which would leak prices that the "show prices"
  setting hides, and `$view_url`, measured to resolve to a link for an invitation that is never saved, i.e. a
  dead link in a client's inbox. The "Facture" heading above the first group is upstream's fixed group name,
  not a mistake.
- **E-mailing a delivered BL** is the only step that exercises the queue workers rather than the web
  container. Sent to a client with two e-mail contacts, each should be greeted by their own name.

### The delivery note's own custom fields and printed rows

Both were built as ruled: *Champs personnalisés* has a Bon de livraison tab before Factures with four label
slots the delivery note owns, and *Modèle de facture* has one before Facture choosing which rows the printed
note carries. Together they cost two permanent patches — **the count went 36 → 38** — and every other part
cost nothing, because the columns, the validation and the PDF hook were already there.

**A correction that nearly went out as a reason to spend budget.** The case for the second tab was drafted
around a claim that delivery notes print *Date d'échéance*, *Montant* and *Solde* today. That is **false**.
With the default settings a BL prints its number and its date and nothing else — every empty value is hidden,
and a delivery note has no due date, no PO number and no project. The real reason to spend was narrower: the
two documents shared ONE list, so a row could not be shown on one without appearing on the other. That only
starts to matter once the BL has custom fields, which is what the first tab adds.

Behaviour worth keeping:

- The Bon de livraison custom-fields tab has four label boxes with type dropdowns, minus the Factures tab's
  four surcharge rows, which are an invoice's money lines and cannot apply here.
- A slot that has **not** been named draws nothing at all. That is deliberate, and it protects anything
  writing those columns through the API: an unnamed slot is never posted by the form, so it cannot be
  overwritten by a save from that page.
- On a **delivered or invoiced** note the fields show their values but cannot be edited — upstream's
  custom-field input has no read-only mode of its own, and these would otherwise have been the only live
  boxes on a locked form.
- The PDF prints under the BL's own label, not the invoice's, and a slot typed `date` prints as a date rather
  than as raw stored text.
- The *Modèle de facture* row list offers six rows — number, date and the four custom fields — and no more.
  Amount, balance, due date and project are deliberately absent: none can render on a delivery note, and a
  control that silently does nothing is the exact failure the milestone was about.
- **The empty list is not a bug.** Nothing is selected until something is selected, and the help line says
  what that means: the note shows its number and its date. This is upstream's own behaviour for any row group
  added after a company was created. Seeding it would have written a default into the settings blob, "which
  is a mistake this repo has already made once".
- The *Modèle de facture* tab is mounted in an unusual way — a second route branch beside upstream's, to
  avoid a third permanent patch — so the other tabs (Facture, Devis, Designs personnalisés) were worth one
  click each.

### The printed note follows Colonnes de produits

The exploration turned up something bigger than the question asked: the BL *editor* had always read
*Réglages → Modèle de facture → Colonnes de produits*, and the printed note read it **nowhere**. Columns were
configured, seen on screen, and not delivered on the page — nothing logged, nothing failing, because no test
compared the two.

**One argument lost and one overruled, both recorded.** The recommendation was **against** hanging the BL off
upstream's existing *Partager les colonnes facture/devis* switch: that boolean already means "quotes follow
the invoice" in three places, one of them the client portal, and a single switch cannot say *quotes get their
own list, the BL follows the invoice*. The delivery note got its own switch instead. The developer then put
that switch on **upstream's own card** rather than on the fork's tab, which costs one permanent patch — **the
count became 39** — and buys two things: it is where it was looked for, and because the switch is no longer
on the fork's tab, that tab can hide itself when not in use, exactly as upstream's quote tab does.

- Two switches, the new one below upstream's: *Partager les colonnes de produits de la facture avec les bons
  de livraison*, on by default. Turning either off renames the card and its tab to *Colonnes de produits de
  la facture* — upstream's own rule, so that two tabs are never both called "Colonnes de produits".
- Removing *Description* from the list removes the column and the line's text from the printed note. Before,
  the note printed the same three columns whatever was chosen.
- **Prices never print when the setting says they must not** — the rule stated twice by the developer, and it
  wins over the list. With *Prix unitaire* and *Total* in the columns and prices off, neither appears: not
  blank, not zero, absent. Discount and tax-rate columns are dropped the same way, because a discount shows
  commercial terms even with no price beside it.
- The BL columns tab appears only when unshared, and its list starts empty, which means "follow the invoice"
  rather than "print nothing"; the help line says so.
- **The editor keeps showing unit cost and total even on a note whose print hides them** — as ruled: the
  setting is about what the *client* sees, and staff need the figures while preparing the note.
- Quotes are untouched: upstream's own switch and its *Colonnes de produits du devis* tab behave exactly as
  before, independently of the new one. That independence is the whole reason the BL was not bolted onto
  their switch.

### Bon de livraison as a module that can be switched off

Built as asked, in *Gestion des comptes → Modules activés*, with switching it off hiding every delivery
interface the way unchecking Facture hides the invoice screens. It cost **one** permanent patch — **39 → 40**
— because the eleven other surfaces that had to learn to hide were already patched or already the fork's.

**One design decision put up for scrutiny, because it is not what the screen looks like it wants.** Upstream
stores those switches as bits of a number, and no bit was taken. The obvious free one, 32768, is *burned* —
that file still carries `Transactions = 256, // old: 32768`, so it meant something else once. The next clean
bit is 65536, and the default every company is created with is 65535, which does not include it. A bitmask
switch would therefore have been **off for every account** the moment it deployed, and would have needed a
migration to turn back on. So the row writes the kill switch the server already obeys, and the *second*
switch that used to sit on the fork's own Delivery settings card is gone. One feature, one switch.

- Switching it off must remove the sidebar entry, the four settings tabs (Champs personnalisés, Modèle de
  facture twice, Numéros générés), the Bon de livraison template tab, the delivery panel on an invoice, the
  client-portal switch and the fork's own Delivery settings screen. Any survivor is the finding most wanted:
  a test can only prove each screen *asks* the question, not that it acts on the answer.
- **A typed URL does not get in either.** Hiding a menu entry is not access control; every delivery route
  carries the same gate, so the page must refuse rather than render.
- **Disabling FREEZES, it does not revert.** Notes, numbers and stock movements survive being switched off
  and back on. "Turning a module off must never be destructive."
- The old *Activé* toggle really is gone from the Delivery settings card, which keeps only the behaviour
  settings. Leaving both would have cost nothing mechanically and would have taught the developer that the
  fork's settings cannot be trusted: turn it off in one place, find it on in the other.

### Numéros générés — the tab lost two fields, and that is the fix

Four things were asked for and all four were done, including the placeholders, argued against twice before
the developer ruled. What settles the first three is one measurement: upstream's own **Facture** tab contains
a number pattern, a number counter and the variable list, and nothing else. Padding and the reset date live
once, on the shared *Paramètres* tab, and govern every series.

**The self-correction is the valuable half.** The reason the fork's tab had a reset date at all was a claim
written into four docblocks in September without ever opening the tab it said was being matched — *"upstream
shows its own reset date on this tab."* It does not. The page records that the developer's instinct on all
three counts was better than the record left behind.

- The tab now has no « Nombre de chiffres » and no « Prochaine réinitialisation ». Both fields, and the four
  settings keys behind them, are gone from the server as well — not merely hidden, which would have left a
  stored value quietly governing numbers from a screen that no longer shows it.
- The shared reset date now governs the BL register too. The *frequency* was always shared; the date is now
  too. It is applied as an anchor the first time a BL is numbered, and moving it re-anchors both series.
- The placeholders match Facture's, all eight, built rather than merely listed. `{$user_id}` and
  `{$user_custom1..4}` are substituted by the server with **upstream's own semantics**: the id is zero-padded
  to two, so user 3 renders `03` exactly as on an invoice, and an unset custom field renders empty rather
  than staying literal. The token names *whoever raised the note*, not whoever marks it delivered, so a
  number never changes hands. Two tests make the sidebar and the server the same list by construction, in
  both directions — the standing objection was about that gap, and it was closed rather than argued.
- **If `{$user_id}` is used, know what the register looks like.** The counter stays company-wide, so notes
  raised by different people render `BL-03-2026-0001` beside `BL-07-2026-0002`. Nothing is missing — the
  sequence is unbroken — but it *reads* gapped to someone auditing one person's notes.
- A cost worth knowing about the reordering: the tab used to be appended last precisely so an upstream sync
  could never conflict with it, and now a sync that inserts a tab near the top will. Cheap to re-resolve, but
  a real trade.

**Why the shared reset date could not simply be read** — the page's own box, and the reason the row took a
server change rather than deleting a text box. When an *invoice's* reset falls due, upstream does not just
read that date: it pushes it forward a year and saves. If the delivery counter followed the same field, then
every year the first invoice numbered in January would move the date into the future, and the BL counter
would conclude no reset was due. It would never reset at all, and it would be found out a year later when a
January number collided with one an invoice already cites. So the shared date *seeds* the delivery register's
own marker and the module advances that itself. Upstream's push is invisible to the module because it saves
silently, while a developer's edit on the settings screen does not — that difference is what tells the two
apart, and it is asserted by a test rather than assumed.

### The price flag — the one that changed a number nobody could see

Creating a BL with the price box **empty**, saving, reopening it, changing *only the date* and saving again
used to silently convert every catalogue-priced line into a hand-priced one, so the company's
`price_at_invoice` mode re-priced nothing — same number on the note either way, no toast, no log line,
nothing on screen. "This is what a company setting doing the exact opposite of what it says looks like."

The negative control matters as much: a price typed by hand must stay hand-priced through any number of
saves, and `price_at_invoice` must leave it alone. Both halves have to hold, or the fix has merely moved the
bug.

### The list, and other editor behaviour

- **The empty list keeps its card** — card, header row, sort arrows, and the message *inside* the table, the
  same shape as an empty invoice list. It used to draw one line of bare text on the page background, because
  the empty-state prop replaces the whole table and upstream passes it on none of their document lists.
- The column picker persists its choice; status leads and the number is second, matching invoices, quotes,
  credits and purchase orders. The status filter should read `Statut:` — `Tous les statuts:` means a
  translated string is being translated twice.
- The Client column links to the client, and on a user without `view_client` permission the same cell must
  render as **plain text**, not a dead link.
- **All four sort headers must actually reorder.** Every header in that table is a sort control with no way
  to opt out, so three of them drew arrows, sent their key, had it silently discarded by the server and left
  the order untouched. A header that draws an arrow and does nothing is the failure being tested for.
- **Archiving a client that has a delivery note** must leave the list rendering with that client still named.
  A bare relation would have thrown for the **whole page**, not one cell — every page holding one of its
  notes, not just the row.
- Reordering lines is upstream's own, reused whole; the row's per-line note must travel with the row, not
  stay at the old index.
- **Product custom fields on a delivered BL** showed four **blank** cells in the read-only table, which read
  as "the operator never filled these in": the column is `$product.product1` but the value lives at
  `custom_value1`, and only the editable branch renamed them.
- A button disabled by validation must still show **its label**; a spinner makes the page read as hung when
  it is merely waiting for input.
- A return quantity must enable "Record the return" **as it is typed**, not only after clicking elsewhere. It
  once deadlocked: the field reported its value on blur, and the only thing to blur onto was the button that
  was disabled because the field looked empty.

### Uploads — and the two steps where a wrong answer is a security finding

- **The company logo must actually appear**, on the settings page and on an invoice PDF. The upload was never
  the broken half — the file landed and the setting was written — but nothing served it, and the browser was
  handed the SPA's index page instead of an image. **Never judge this by a status code**: an unserved path
  answers `200` with `text/html`, so 200 proves nothing. The content type is the answer.
- **A missing upload must answer a real 404.** Already measured on the final build:
  `curl -sI http://localhost:8537/storage/nope-does-not-exist.png` answers `HTTP/1.1 404 Not Found`; before
  that morning the same path returned `200` with `text/html`, which is why a status code could never have
  told anyone the logo was broken.
- **Uploaded PHP must not run.** Making uploads servable is exactly what would make an uploaded `.php`
  execute, and since client-portal uploads are on by default that is a path a *client* can reach. Four
  spellings must all answer 404 — `x.php`, `x.php/x`, `x.php/` and `x.php%2Fx` — and **three of them executed
  on the first attempt at this guard**, because the extension matcher never saw them. Measured on the final
  build: all four answered 404, the file neither executed nor served as source, while a real PNG in the same
  directory came back as `image/png`. It must be re-run if anything touches `docker/twes/Caddyfile`, and
  **the marker in the probe file must never be a string that also appears in its own source**, or serving the
  source reads as execution.

### Tier C — the delivery screens, twelve components with zero test files

Three checks vanish the moment anything is created: the empty delivery list (a deliberate empty state, not a
bare table header, a spinner that never resolves or a stray "0 of 0"); the create form with no products to
pick (it should say so and stay usable rather than render an empty dropdown that looks broken); and an
invoice with no delivery notes to attach — worth its own step because that block is a fork patch on an
upstream screen, so the failure mode is it breaking the whole invoice editor rather than only itself.

Then: the list renders once one exists, with the number appearing when the note is marked delivered rather
than when it is saved, because this account numbers on delivery so a gap in the register cannot open; the
documents tab accepts an upload, lists it and downloads it; the row slider opens from the list with summary,
lines and actions; marking one delivered moves the status and the chip colour follows.

**Consolidating into an invoice**, the untouched block: two delivered BLs added to one invoice must both land
priced by the company's mode rather than by whatever the BL showed; the invoice must show a delivery-notes
block listing which BLs it covers (the clause a per-line note alone did not answer, and it cost a registered
patch); consolidating the same BL twice must be refused with **no second provenance note** on the invoice;
and the client balance must equal the invoice total — **a mismatch of exactly the stamp amount means a money
field landed after the balance snapshot.**

### Tier D — four changes carried over from 5 September, never seen rendered

- The BL PDF renders correctly — commit `93be04974d`; three defects found in the browser were fixed and had
  not been looked at since.
- E-mailing a BL to a French-locale client — commit `1f1b5eb96e`; the date in the mail follows **the
  client's** locale, not the server's. Check the received mail, not the preview.
- No raw translation keys anywhere: thirteen orphaned keys were dropped, and a literal `twes::…` string on
  screen means one is still in use.
- Cancelling a delivery that an invoice cites — commit `bb41c85f55`; provenance is written by one path now,
  the refusal should be clean, and the invoice's note list must stay consistent with what actually happened.

### Tier E — nothing should look like ours

The property the milestone bought: four native pages carry one insertion each, which is what four permanent
patches were spent on. Markup sits on *Settings → Products*, reading as one of its sections rather than a
separate fork page in the sidebar. Stamp duty sits on *Settings → Tax Settings* — and its label field must
show **a real default or nothing**: displaying `twes::stamp_duty.label` means a translation key has been
persisted into the settings blob again. Deliveries has its own *Generated Numbers* tab alongside invoices,
quotes and credits, with its own pattern and counter. The client portal offers deliveries as a row in the
portal sidebar, **registered after every upstream provider has booted**, because upstream rebuilds that whole
menu and last writer wins — so its mere presence is the thing being tested. And markup must move a line total
live, with the preview total agreeing with the invoice once saved; any disagreement is a totals-mirror bug.

## 8. Proving you are on the build — and three wrong instruments

The containers bake the application in and do not mount `api/` or `web/`, so they can be arbitrarily older
than the tree, "which has already cost one false bug report". Two checks, both of which fail on the previous
image:

```sh
docker exec invoiceninja-twes-app-1 sh -c \
  'grep -l "choisissez un contact du client" /app/public/i18n-*.js'
want=$(docker inspect --format '{{.Id}}' invoiceninja-twes:latest)
for c in app-1 app-scheduler-1 app-worker-1 app-worker-2; do
  [ "$(docker inspect --format '{{.Image}}' invoiceninja-twes-$c)" = "$want" ] \
    && echo "$c current" || echo "$c STALE"
done
```

The first prints a file name on that build and nothing on the one before — the sentence is row 64's help
text, and row 64 changed only the web application. The second checks every container, not just the one that
would be exec'd into: recreating `app` alone once left the scheduler and both workers on an old image.
Measured 16 September at 10:25 — one file printed, all four current.

Three instruments the page retracts, and the reasoning is the point:

- **The `md5sum` of `DeliveryController.php` no longer tells you anything.** None of the commits since the 15
  September image touched PHP, so the sum is `cd3803be…` on that image and on the next alike. **A hash is
  only a staleness check when the file is one the build actually changed** — the page once hashed
  `DeliveryService.php` on a day nothing touched it, and certified a six-hour-old image as current.
- **A `php -r` one-liner is the wrong instrument and the page shipped one for twenty minutes.** Without
  `require vendor/autoload.php` there is no autoloader, so `class_exists` is false for every class and the
  check reports a stale image on a perfectly current one. It cannot pass. `md5sum` needs no framework and
  errors loudly on a wrong path instead of answering false.
- **Greping the build directory proves what the builder wrote, not what a browser receives** (the font check,
  § 7 above).

The reporting format the page asked for, four lines, the fourth being the one that saves a round: the route,
what was clicked or typed, what was expected, and **what was actually seen — the literal text, if there was
any**.

## 9. The registered-patch budget

The fork measured itself by how many upstream files it had to modify permanently. Every count the page states,
in the order it states them, because no single figure is the answer:

| Moment | Count | Floor | What moved it |
|---|---|---|---|
| Custom fields and printed rows | 36 → 38 | not stated | two permanent patches, one per tab |
| The BL columns switch on upstream's card | 39 | not stated | one, for putting the switch where it was looked for |
| Module on/off in *Modules activés* | 39 → 40 | not stated | one; eleven other surfaces were already patched or already the fork's |
| Docs link transform | 41 | 23 | zero — a Vite plugin instead of twenty patches |
| Row 18 relabel | 42 | 24 | one permanent patch, never retires |
| Row 58 terms and footer | 43 | 25 | one, `patches/web/23`, budgeted by the ruling |
| Rows 52 and 64 | 43 | 25 | none |
| Row 66 backup | 43 → **45** | unchanged | described as **one** registered patch of three event lines, offered upstream as a generic hook |

**The row 66 line does not add up on its own terms and is left as the page wrote it**: a count rising by two
described as one patch. The page offers no reconciliation and neither does this file.

---

## What is ambiguous or unresolved in this record

Stated plainly rather than guessed at:

- **The artifact id is not recorded.** The saved copy carries a tool-results filename, not a claude.ai id.
- **The database contents disagree between the two tallies**, both of which the page calls counted: 2
  products → 1 and 4 delivery notes → 3 between 8 and 16 September, with no explanation offered.
- **Row 66's patch count** rises by two while describing one patch (§ 9).
- **Row 39's original ruling** is not on the page; only the re-ruling is.
- **Rows 38, 61 and 62 carry no commit sha**; every other row does.
- **The padding decision was left open** — `10` versus `0001` — with no answer recorded (§ 6).
- **Row 38's "next" item** — « Bon de livraison » beside « Facture » everywhere — was mapped and never built;
  whether it was ever built afterwards is not knowable from this page.
- The page's tier labels ("Tier 0", "Tier A" to "Tier E") are its own ordering device and correspond to
  nothing in any plan file preserved here.
