# Vision — twes-in

> This file holds direction that is **not** a commitment. Nothing here constrains current work, and
> nothing here may be cited as a reason to defer a decision or to design around an unknown. The rules
> that bind are in `CLAUDE.md`; the plan that binds is in `docs/plans/`.

## What twes-in is for

An invoicing and billing platform that is **both** the author's own internal invoicing **and** a
product sold to others — a Symfony REST API, an Angular admin client, and a Flutter client for mobile
with native desktop support — which is **ruled in scope for Wave 11, not "later"**: all six Flutter
targets ship (Android, iOS, Linux, Windows, macOS, Web). See `docs/plans/build-waves.plan.md`.

Licensed **AGPL-3.0-or-later plus a commercial licence** (`LICENSING.md`): open source, and sellable by
the author. Everything in it is ours — a clean-room reimplementation inspired by Invoice Ninja's
*functionality*, never its code. See `CLAUDE.md` § "Licensing invariants", which is the section that
makes that real rather than aspirational.

## The bar

Better than what inspired it, in specific and checkable ways rather than as a slogan. The defects we
are deliberately not reproducing, each one observed in upstream's source:

- **Money is never a float.** Upstream stores amounts as floats on models and reaches for `bcmath` only
  in places — its own tax helper mixes `BcMath::mul` with native float arithmetic in *adjacent methods*
  and skips rounding entirely on one Peppol path.
- **Tenancy scoping is default-on**, not an opt-in scope that every query has to remember.
- **Permissions are a real model**, not `stripos` against a string column.
- **Inclusive vs exclusive tax is one parameterised implementation**, not two parallel class hierarchies
  kept in step by hand.
- **One template language**, not Blade and Twig in the same PDF pipeline.
- **No dependency pinned to a moving branch** on the critical path.
- **State transitions go through guards**, never direct status assignment.

## Later — genuinely later

Ideas that are wanted but are not scheduled, not designed for, and not blocking anything.

**A rewrite of the core in [phorj](https://github.com/tmessaoudi-official/phorj).** phorj is the
author's own statically-typed, PHP-inspired language — stricter, typed, faster. **It is unfinished, it
is not a target, and no part of twes-in is built for it.** The honest status: if and when the language
is ready, a domain layer that is already framework-free is the part that could port, because it is plain
typed logic with no framework or I/O in it. That makes the idea cheap to keep open — but the hexagonal
architecture in `CLAUDE.md` is justified entirely by this being a billing system, and would be correct
if phorj never shipped. Do not let this item influence a single current decision.

**What such a port would actually need is now written down, and writing it down changed nothing here.**
`docs/PHORJ-REQUIREMENTS.md` measures phorj at `1.0.0-nightly.0` and lists the gaps as requirements addressed
**outward, to phorj** — it is not a plan twes-in has adopted, and nothing in it is a reason to defer anything.
Two findings from it are worth surfacing at this level, because they cut in opposite directions and both were
measured rather than assumed. In phorj's favour: its `decimal` is a **kernel fixed-point `i128` type whose
overflow faults**, which is stricter than the `bcmath`-over-strings `Money` this project had to write itself —
so the crown-jewel risk of a port is smaller than it looks. Against: its PostgreSQL driver connects with
**`NoTls` and nothing else**, and its guidance is to store decimal columns as `TEXT` — either of which would be
disqualifying for a billing system today. That is the honest shape of the gap: the foundation is better than
ours, the edges are not there yet.

**twes-in as a phorj showcase.** A real, non-trivial product in the language would be the strongest
argument for it. Downstream of the above, and equally not a commitment.

**Public hosted deployment.** Today it is Docker-only with no public domain. When that changes it must
be a configuration change and not a code change — which is why hostname, product name, logo, imagery
and e-mail identity are configuration from day one.

**Scope beyond the first release.** The first release is deliberately narrow (see
`docs/plans/reimplementation-strategy.plan.md`). Additional payment gateways, more e-invoicing
standards and tax jurisdictions, bank feeds, and richer reporting are all wanted eventually. Upstream
reached ~344k lines of backend code over twelve years; matching that is roughly 25–40 person-years, so
growth here is chosen one item at a time, with each addition earning its place.
