# Pricing & documents Spec

Detailed spec for the developer's feature additions (F1–F4 in `build-waves.plan.md`). Written from the
developer's own description, not from upstream behaviour — three of the four have **no upstream
analogue**, so every rule here is ours to decide.

**Nothing here is implemented.**

## Decisions Log

- [2026-07-29 14:30] RULED **default currency TND, multi-currency from the start.**
- [2026-07-29 14:30] FOUND — **the most consequential fact in this spec.** TND is one of only seven
  ISO-4217 currencies with **three** decimal places: 1 dinar = 1000 millimes, so `0.100 TND` is 100
  millimes. **Any 2-decimal assumption is a bug for the DEFAULT currency**, not an edge case — no
  `round($x, 2)`, no `× 100` to reach minor units, anywhere. `Money` carries each currency's own scale
  and refuses to assume. `NUMERIC(19,4)` stores 3 decimals exactly with a 4th digit of headroom for
  unit prices and rates. [Verified: ISO 4217 minor-unit tables; TND/BHD/JOD/KWD/OMR/LYD/IQD are the
  3-decimal set.] Every prior mention of "cents" in this repo's planning docs was wrong for TND and is
  superseded by this entry.
- [2026-07-29 14:30] RULED **exchange rate is captured at document date and stored with the document**,
  never re-derived. An issued invoice's total must never change because a rate moved.
- [2026-07-29 14:30] RULED **merging invoices: drafts only.** Anything carrying a real invoice number
  cannot be merged in either direction — legally an issued numbered invoice is undone only by a credit
  note, never deleted. A merge touching a numbered invoice is a P0 for `domain-correctness-reviewer`.
- [2026-07-29 14:30] RULED **VAT applies per line AND per document, plus fixed charges.** Lines may
  carry different VAT rates; each line shows net, VAT rate, VAT amount and gross. Fixed
  absolute-amount charges (Tunisia's stamp duty, `0.100 TND` per invoice) are document-scope charges in
  the same generic model — configuration, never hardcoded, never a special case in code.
- [2026-07-29 14:35] RULED **profit rate is bidirectional: last-edited-wins.** `cost`, `profit_rate`
  and `net_price` are three linked fields; editing one recomputes the dependent one. Default rate 30.
  Product-form helper only — never visible on an invoice.
- [2026-07-29 17:40] RULED **the profit-rate fix is BOTH parts**: a product persists `authored_by`
  (`profit_rate` or `net_price`) and the typed field is never recomputed, **and** `Rate` carries 12
  fraction decimals (10 on the percentage) rather than 6. Precision moves the boundary; authorship removes
  it. Supersedes the "stored authority is `net_price`" bullet under § F4, amended there in place.
- [2026-07-29 14:35] RULED **delivery notes are PERSISTENT, independently numbered documents**, not
  ephemeral children of an invoice — the developer asked for "maximum understanding and protection".
  Draft (previewable, mutable, unnumbered) → Issued (numbered, immutable, PDF stored). A re-download
  returns the **stored bytes**, never a re-render.

---

## F4 — Profit rate on the product

### The formula

Markup on cost, with VAT applied to the profit-inclusive net:

```
net   = cost + (cost × profit_rate)     ← the sellable unit price, NET
gross = net  + (net  × vat_rate)        ← VAT on top of net, NEVER on cost alone
```

Worked, `cost = 100.000 TND`, `profit_rate = 30%`, `vat_rate = 19%`:

| Component | Amount (TND) |
|---|---|
| cost | 100.000 |
| profit — 30% **of cost** | 30.000 |
| **net (unit price on the invoice line)** | **130.000** |
| VAT — 19% **of net** | 24.700 |
| **gross** | **154.700** |

The trap this avoids: VAT is 24.700, not `100 × 0.19 = 19.000`. The VAT base is the net (130), never
the cost.

### RULED 2026-07-29 — store WHICH field was typed, **and** carry 12 decimals on the rate

Both fixes, together, because they solve different halves of the same defect (developer ruling: *"can we
combine option 1 and 2"*). They are complementary rather than redundant: **precision moves the boundary,
authorship removes it.**

**The defect they fix.** Cost `10 000.000 TND`, typed price `10 000.001` — one millime of profit. The true
rate is `0.0000001`, which needs **seven** decimals; the rate was stored with six, so it rounded to zero.
The form then displayed `0.0000 %` for a product sold above cost, and the moment the cost changed the
price was rebuilt from that zero and the millime was **deleted**. At scale: `1 000 000.000` cost with a
typed price of `1 000 000.500`, cost rising to `1 100 000.000`, produced `1 100 001.100` where the exact
answer is `1 100 000.550` — `0.550 TND` out, and in that direction the customer overpays.

**Part 1 — `authored_by`, persisted on the product.** Either `profit_rate` or `net_price`. The typed field
is stored exactly as entered and is **never recomputed**; only the other one is derived, for display, with
no authority. `Domain/Pricing/ProductPricing.php` enforces it and there is no way to construct one without
declaring which field was typed.

**Part 2 — `Rate::FRACTION_SCALE` is 12**, percentage 10. Both motivating cases are then exact:

| cost | typed price | derived rate | cost becomes | price becomes |
|---|---|---|---|---|
| `10 000.000` | `10 000.001` | `0.0000100000 %` | `10 001.000` | **`10 001.001`** — the millime survives |
| `1 000 000.000` | `1 000 000.500` | `0.0000500000 %` | `1 100 000.000` | **`1 100 000.550`** — exact |

**What a cost change does, precisely.** The **rate** is preserved and the **price** moves — unchanged from
the original ruling, and the reason is unchanged too: holding the price instead would let the rate absorb
every cost rise and erode the margin unnoticed. When the *price* was the typed field, the rate implied by
the typed pair is what carries forward, so **authorship transfers to the rate**. That is the honest
outcome: a price typed against the old cost is no longer a statement about the new one, whereas the margin
accepted still is.

One case has no rate to preserve — a **zero old cost**, where the rate is undefined. The typed price is
then kept unchanged rather than invented from a rate that does not exist.

Ten decimals on the percentage is **not a display format.** Clients format for the locale; that string is
the canonical value, so comparing a rate across PHP, TypeScript and Dart stays an exact string comparison.

Pinned for all three tiers by the `authored_field` section of `docs/spec/pricing-vectors.json`, including
both worked examples above and the zero-cost case.

### Bidirectional editing — last-edited-wins

Three linked fields. Editing any one recomputes the dependent one, so the displayed rate can never
lie:

| User edits | Recomputed | Formula |
|---|---|---|
| `profit_rate` | `net_price` | `net = cost × (1 + rate)` |
| `net_price` | `profit_rate` | `rate = (net − cost) ÷ cost` |
| `cost` | `net_price` | `net = cost × (1 + rate)` — the **rate is preserved** |

Sequence, showing the field adapting:

```
cost 100.000, rate 30%           → net 130.000
user types net = 140.000         → rate becomes 40%     (140 − 100) ÷ 100
user types rate = 25%            → net becomes 125.000  100 × 1.25
```

**Editing `cost` preserves the rate and moves the price** (developer ruling, 2026-07-29). Cost rises
100.000 → 110.000 at 30%, and the net becomes 143.000. Rationale in the developer's words: if the
resulting price is then too high, they edit the profit rate deliberately. The alternative — holding the
price and letting the rate absorb the increase — would erode margins silently, which is the failure mode
this ordering avoids.

### Enforced in the domain AND in every client — with shared test vectors

The recalculation is **instant in the Angular admin, the Flutter client and the native builds, and
enforced by the API** (developer ruling). It is a domain rule, not a form convenience: a `POST` carrying
`cost` + `profit_rate` gets the net computed server-side; one carrying `cost` + `net_price` gets the rate
computed. The API never trusts a client-supplied trio that does not reconcile.

**The hazard this creates, and the mitigation — this is the "three codebases, one contract" problem in
its sharpest form.** Instant feedback means the arithmetic exists in **three** places: PHP in `Domain/`,
TypeScript in the admin, Dart in the client. Three implementations of the same money formula will drift,
and the drift will be a wrong price rather than a crash.

Mitigation, and it is mandatory: **one JSON fixture of test vectors is the single source of truth**, and
all three tiers run their own test suite against that same file.

```
docs/spec/pricing-vectors.json     # cost, profit_rate, net_price, vat_rate, currency
                                   # → expected net, expected vat, expected gross, expected rate
```

Vectors must include: TND (3 decimals) and EUR (2), a repeating decimal, `cost = 0`, a negative rate,
and each edit direction. A tier whose suite does not consume that file is a `completeness-reviewer` P0 —
this is exactly the "change reaches every tier" lens.

### Edge cases — decided now, because each is a division or a sign trap

- **`cost = 0`** → `profit_rate` is undefined (division by zero). The rate field shows empty/`—`, never
  `0` and never an error. Editing the rate with zero cost leaves `net` untouched.
- **`net_price < cost`** → the rate goes **negative**, and that is allowed: selling below cost is real
  (clearance, loss-leader). Surface it visibly rather than clamping it; never silently coerce to 0.
- **`net_price = cost`** → rate `0%`. Valid.
- **Rounding**: **AMENDED 2026-07-29 by the RULED section above — read that, not this bullet's original
  form.** It used to say "the stored authority is `net_price`; never re-derive it from a rounded rate",
  which contradicted the ruled behaviour that a cost change preserves the rate and moves the price. The
  authority is **whichever field the user typed** (`authored_by`), not unconditionally `net_price`. The
  original warning survives in the form that matters — *a typed price is never rebuilt from a rounded
  rate* — while a cost change legitimately rebuilds a **derived** price from a rate, which is the ruled
  behaviour and not drift.
- **Snapshot rule, non-negotiable**: the net price is copied onto the invoice line when the line is
  created. A later change to the product's cost or rate must never alter an already-issued document.

---

## F1/F3 — Delivery notes and invoice consolidation

### Two flows, both first-class

- **Direct (the default).** Create an invoice, pay it. No delivery note involved.
- **Accumulate then consolidate.** Delivery notes are issued as goods leave over time; later, one
  invoice consolidates the uninvoiced ones for that client.

### Delivery-note lifecycle

| State | Number | Mutable? | PDF |
|---|---|---|---|
| **Draft** | none | yes | preview only, nothing stored |
| **Issued** | own sequence, plain padded number | **no** | rendered once, **stored**; re-download returns the stored bytes |

Rules that follow, each from a developer requirement:

1. **Own numbering sequence**, independent of invoices, per tenant — and the **number carries no type
   marker** (developer ruling, 2026-07-29). No `DN-` prefix: it is a plain zero-padded number from a
   configurable pattern, exactly like quotes and invoices, using the *same generic numbering machinery*
   rather than a delivery-note-specific one. The document's **title and template** carry its identity,
   not its number.
   *Consequence to accept:* because sequences are per-type, invoice `0000041` and delivery note
   `0000041` can both exist. That is normal and correct on a printed document, where the title
   disambiguates — but any internal reference, search result or API payload must name the document
   **type alongside the number**, never the number alone.
2. **Prices are snapshotted per delivery-note line.** The same product may carry a different price on a
   later delivery note — the price is captured at issue time, never looked up from the product when the
   invoice is built.
3. **Re-download is byte-identical.** The PDF is stored on issue with a content hash; re-downloading
   never re-renders. (A re-render could differ if a template, logo or address changed in between — for a
   document a client already holds, that is a defect, not an improvement.)
4. **An issued delivery note is immutable, and corrections go by cancel-and-reissue** (developer
   ruling, 2026-07-29). The wrong note is marked **cancelled** and stays on file; a new one is issued
   with the correct figures. Nothing ever mutates, the audit trail is complete, and the
   byte-identical-re-download guarantee holds — which editing in place would have broken, since the
   client may already hold the old PDF. Same discipline as an invoice.
5. **Templates are customizable per document type**, on the same engine as the invoice template — a
   different title, and different content where the type needs it. The template system is therefore
   **generic across document types from the start**, never invoice-specific with others bolted on.
6. **Each delivery-note line becomes its OWN invoice line.** Never deduplicated or merged by product —
   see the worked example; the timeline is the feature.

### Worked example — including a price change between delivery notes

Client *Atelier Ben Ali* collects goods through March:

```
03 Mar   DN-0041   cement  10 × 12.000
11 Mar   DN-0053   cement   5 × 12.500     ← price rose since DN-0041
19 Mar   DN-0061   paint    2 × 85.000
```

28 Mar — they ask to pay. One invoice consolidates all three:

```
INV-0009 — Atelier Ben Ali
  1.  cement  10 × 12.000 = 120.000   03 Mar   DN-0041
  2.  cement   5 × 12.500 =  62.500   11 Mar   DN-0053   ← separate line, own price
  3.  paint    2 × 85.000 = 170.000   19 Mar   DN-0061
  ──────────────────────────────────────────────────────
  Subtotal net              352.500
  VAT 19%                    66.975
  Stamp duty (fixed)          0.100
  ──────────────────────────────────────────────────────
  Total                     419.575 TND
```

Lines 1 and 2 are the same product and **stay separate** — different dates, different delivery notes,
different prices. Deduplicating by product would destroy the timeline, so it is explicitly forbidden;
this is exactly the kind of "helpful" optimisation to refuse.

### Double-invoicing protection

A delivery note carries `invoiced / not-yet-invoiced`. Consolidation only offers uninvoiced ones, and
marks them invoiced in the same transaction. **A delivery note can be consolidated into at most one
invoice** — enforced in the domain, not merely in the UI.

---

## F2 — Invoice from a quote

One-to-one. Converting a quote that already has an invoice returns the existing invoice rather than
creating a second one.

---

## The charge model — generic, not a VAT column

A document carries **N charges**. Each has a scope, a type and an application order:

| Kind | Scope | Type | Example |
|---|---|---|---|
| VAT | line | percentage | 19% on one line, 7% on another |
| VAT | document | percentage | a single rate over the subtotal |
| Fixed charge | document | **absolute amount** | stamp duty `0.100 TND` |

Every line displays **net · VAT rate · VAT amount · gross**. The document displays subtotal net, a
**VAT breakdown grouped by rate**, fixed charges, then total.

Tunisia's stamp duty is not special-cased anywhere: it is a fixed document-scope charge, configured per
company. Any other country's equivalent then works without touching the domain.

**One implementation, parameterised.** Inclusive vs exclusive tax is a flag, never a parallel class
hierarchy — upstream maintains four classes that must be kept numerically in step by hand, and that
duplication is a large part of why their test suite is 167k LOC.

**Rounding point:** VAT is grouped by rate and rounded **once per rate group on the summed base**, which
is what an EN 16931 / Peppol validator recomputes — so the e-invoicing payload needs no reconciliation.
Configurable per company, with that as the default.

---

## Internationalisation, localisation and accessibility — first-version requirements

Developer ruling, 2026-07-29: **multi-currency, i18n, l10n and a11y are all in the first version**, not
deferred. Recorded here with the hard parts named, because three of them are genuinely difficult and
one is routinely discovered too late.

- **Translation-key parity is mechanically checked, not hoped for.** Every locale carries the same key
  set; a missing key fails the build. `pdfturbo` solved exactly this with a `PostToolUse` hook doing a
  three-way key diff on any locale write, and that hook is the model to port once the locales exist.
- **RTL is the expensive one, and it reaches the PDF.** If Arabic is a target locale — the natural
  assumption for Tunisia — then right-to-left affects the Angular admin, the Flutter client **and the
  generated PDF documents**. Browser and Flutter RTL are well-trodden; **bidirectional text and Arabic
  glyph shaping inside a generated PDF are not**, and this is the single most underestimated item in
  the whole i18n scope. It must be proven with a rendered document, not asserted.
- **Locale-aware formatting must agree between the clients and the PDF.** `Intl.NumberFormat` handles
  the admin and Flutter has its own; the PDF renderer needs the same rules, and **TND's three decimals
  are the case a 2-decimal default gets wrong**. The pricing test vectors cover the arithmetic; formatting
  needs its own vectors.
- **a11y needs a harness or it rots silently.** WCAG semantics, keyboard navigation, focus management,
  screen-reader labels and contrast, with an automated check (`axe-core` for the admin, Flutter's own
  semantics tests) in the tier gate. Accessibility asserted in prose and unmeasured is not accessible.
- **Currency ≠ locale.** A French-language user may invoice in TND, and an Arabic-language user in EUR.
  They are independent axes: `locale` drives text, dates and number *formatting*; `currency` drives
  amounts and scale. Conflating them is a common and confusing bug.

## Assumed, not blocking — stated so a wrong assumption is cheap to correct

**Locales in the first version: French, Arabic and English, with RTL in scope.** Not asked, because it
is inferable and the safe direction is clear: France and Tunisia both ship in Wave 5, so French is
certain; Tunisia makes Arabic near-certain; English is the usual third. **Arabic means RTL, and RTL
reaches the generated PDF** — bidirectional text and Arabic glyph shaping, the hardest item in the i18n
scope.

The asymmetry is what decides it: planning for RTL and not needing it costs a little over-engineering,
whereas needing it and not having planned for it means reworking every template and both clients. So RTL
is in scope from Wave 0's scaffolding.

**Say the word if Arabic is out** and the PDF bidirectional work drops out of scope with it — that is
the single largest saving available in the i18n budget.
