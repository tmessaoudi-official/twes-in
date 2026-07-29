# Licensing — twes-in

> **Summary.** twes-in is dual-licensed: **AGPL-3.0-or-later** for everyone, and a **separate
> commercial licence** available from the copyright holder for anyone who does not want AGPL's
> obligations. Copyright is held solely by Takieddine MESSAOUDI.

This satisfies the requirement recorded on 2026-07-29: *open source, but sellable by the author.*

## The two licences

**1. AGPL-3.0-or-later** (`LICENSE`) — the default, for everybody.

Anyone may use, study, modify and redistribute twes-in. In exchange, AGPL §13 adds the clause that
matters for a billing platform: **if you run a modified version and let users interact with it over a
network, you must offer those users the corresponding source.** A competitor cannot take twes-in,
close their changes, and run it as a proprietary SaaS.

**2. A commercial licence** — by agreement with the copyright holder.

Because Takieddine MESSAOUDI owns 100% of the copyright, the same code can be licensed on different
terms to anyone who wants to embed or host twes-in without AGPL's source-disclosure obligation. That
is not a loophole — it is the ordinary consequence of sole authorship, and it is the mechanism that
makes "open source but sellable" work.

## Why AGPL and not something else

| Candidate | Why not |
|---|---|
| **MIT / Apache-2.0** | Fully permissive: a competitor could host a closed fork as a paid service and owe nothing. It gives away the exact position the commercial licence is meant to sell. |
| **GPL-3.0** | Copyleft on distribution but **not on network use** — the SaaS hole. For server software this is the flaw AGPL exists to close. |
| **Elastic 2.0 / BUSL-1.1** | Would achieve the commercial protection, but they are **source-available, not open source** (not OSI-approved). Ruled out because open source was an explicit requirement — and because ELv2 is exactly what makes Invoice Ninja unusable as a base for this project. |
| **AGPL-3.0-or-later + commercial** | ✅ OSI-approved open source, closes the network hole, and leaves the commercial licence to sell. |

## Three obligations this creates — treat them as invariants

**1. Copyright must stay wholly owned.** Dual licensing only works while one party can relicense the
whole work. Any outside contribution must therefore arrive under a copyright assignment or a
permissive grant (a CLA), or it cannot be included. There is no CLA yet because there are no outside
contributors; **one is required before accepting the first external patch.** Merging a
contribution without it silently forecloses the commercial licence for that file.

**2. Every dependency must be AGPL-3.0-compatible.** Record each one in `THIRD-PARTY-NOTICES.md`
with its licence *before* adding it. Notably:

- **Apache-2.0, MIT, BSD, ISC** — compatible. These cover essentially every library this project needs.
- **GPL-2.0-only is NOT compatible with AGPL-3.0** — there is no "or later" clause to upgrade through,
  and the two licences' terms cannot both be satisfied. This is the general rule and it holds.
  **On `invoiceninja/dockerfiles` specifically, be precise rather than convenient:** it ships the
  standard GPL-2.0 text with **no per-file version notice** [Verified: `grep -rIl GPL` over the tree
  matches only `LICENSE` itself]. GPL-2.0 §9 says that where a program does not specify a version, the
  recipient may choose any version ever published — so whether it is "only" or "or later" is genuinely
  **ambiguous**, and the convenient reading would be that we could copy from it. We do not rely on that
  reading. It is moot anyway: copying any file from it would make **that file** copyleft with a
  source-disclosure duty regardless of which GPL version applies, so the decision to write our own
  Docker and deployment files stands on the first reason alone.
- **Elastic License 2.0** — not open source and not compatible. Invoice Ninja's API and web UI are
  ELv2; see `CLAUDE.md` § "Licensing invariants". Never vendor, copy or link them.
- **Attribution Assurance License** — permissive but carries a per-launch attribution duty. We use no
  AAL code: the Flutter client is written from scratch (developer ruling, 2026-07-29).

**3. AGPL's obligations apply to us too, once we distribute.** If twes-in is offered to third parties
over a network under the AGPL licence, the corresponding source must be offerable. The commercial
licence is the escape hatch for customers who need one — not for us.

## Notices

Source files carry an SPDX identifier rather than a licence header block:

```php
// SPDX-License-Identifier: AGPL-3.0-or-later
```

`SPDX-FileCopyrightText: Takieddine MESSAOUDI` accompanies it where a copyright line is wanted. A
short identifier is machine-readable and does not rot the way a pasted paragraph does — and note that
Invoice Ninja's own headers reference their licence **by URL**, which is precisely why text-matching
licence scanners miss 2379 files' worth of ELv2 encumbrance. Ours must be scannable.

## What this file is not

Not legal advice. Two points genuinely warrant a lawyer before the first commercial licence is sold or
the first outside contribution is accepted: the CLA's exact wording, and whether the intended hosting
arrangement triggers AGPL §13 for us as well as for others.
