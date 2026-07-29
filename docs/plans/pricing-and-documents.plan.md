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

### Bidirectional editing — last-edited-wins

Three linked fields. Editing any one recomputes the dependent one, so the displayed rate can never
lie:

| User edits | Recomputed | Formula |
|---|---|---|
| `profit_rate` | `net_price` | `net = cost × (1 + rate)` |
| `net_price` | `profit_rate` | `rate = (net − cost) ÷ cost` |
| `cost` | **OPEN — see below** | |

Sequence, showing the field adapting:

```
cost 100.000, rate 30%           → net 130.000
user types net = 140.000         → rate becomes 40%     (140 − 100) ÷ 100
user types rate = 25%            → net becomes 125.000  100 × 1.25
```

### Edge cases — decided now, because each is a division or a sign trap

- **`cost = 0`** → `profit_rate` is undefined (division by zero). The rate field shows empty/`—`, never
  `0` and never an error. Editing the rate with zero cost leaves `net` untouched.
- **`net_price < cost`** → the rate goes **negative**, and that is allowed: selling below cost is real
  (clearance, loss-leader). Surface it visibly rather than clamping it; never silently coerce to 0.
- **`net_price = cost`** → rate `0%`. Valid.
- **Rounding**: the rate is a display-and-input convenience; the **stored authority is `net_price`**.
  Never re-derive `net_price` from a rounded rate on load, or prices will drift each time the form is
  opened.
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
| **Issued** | own sequence, e.g. `DN-0041` | **no** | rendered once, **stored**; re-download returns the stored bytes |

Rules that follow, each from a developer requirement:

1. **Own numbering sequence**, independent of invoices, per tenant.
2. **Prices are snapshotted per delivery-note line.** The same product may carry a different price on a
   later delivery note — the price is captured at issue time, never looked up from the product when the
   invoice is built.
3. **Re-download is byte-identical.** The PDF is stored on issue with a content hash; re-downloading
   never re-renders. (A re-render could differ if a template, logo or address changed in between — for a
   document a client already holds, that is a defect, not an improvement.)
4. **An issued delivery note is immutable.** Corrections do not edit it.
5. **Each delivery-note line becomes its OWN invoice line.** Never deduplicated or merged by product —
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

## Open — awaiting the developer

1. **When `cost` is edited, which of the other two adapts?** Keep the rate and recompute the net price,
   or keep the price and recompute the rate?
2. **How is an issued delivery note corrected?** Cancel-and-reissue, or a corrective note referencing
   the original?
