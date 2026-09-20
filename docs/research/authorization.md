# Authorization: the three additions of 2026-09-20

The developer has ruled three additions in principle, and asked for them to be confirmed or corrected by
research before anything is built:

1. a membership may optionally name the **establishments** it applies to, empty meaning all, so a chain can
   hire a waiter at one café;
2. where ownership matters, a module declares **paired permissions** — `service.order.write_own` beside
   `service.order.write_any`;
3. certain acts — void a line, discount over a threshold, refund, reopen a shift — are declared as
   **requiring authorisation**: the cashier acts, the app asks for a code, a member holding the authorising
   permission gives it, and the audit log records who authorised what.

This file holds the research behind those three, the verdict on each, and the smallest shape that is still
correct. It describes nothing that is built. `docs/SPEC.md` § 7 remains the record of what is ruled; this
file is the evidence a future ruling can be checked against.

**On evidence grades.** Every claim below carries one of four labels. *Verified* means the cited text was
read. *Inferred* means it follows from something read, and says from what. *Unverified* means there is a
checkable fact that could not be checked, and says why. *Speculative* means there is no checkable fact,
only design judgement. Where a quotation came back from a research agent fetching the cited URL rather
than from a reading by the author of this file, the grade says so: the URL is the check, and anyone may
repeat it.

---

## 0. What exists today

The baseline the rest of this file measures against, read directly from the tree at `8f0e1c9`.

A permission is a dotted string. `Tenancy/Domain/Permission.php` accepts
`/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/` — lower-case segments, underscores allowed inside a segment, at
least two segments — plus the wildcard `*`. A `platform.` prefix marks the operator family, which belongs
to platform operators and to nobody else. [Verified: the file.]

A role is a row: `role(id, company_id nullable, name, permissions jsonb, created_at)`. `Role::grants()` is
an exact string match against that list, or the presence of `*`; there is no prefix matching and no
implication between permissions. The three built-in roles have no company and are redefined by the release
rather than by the database — `SeedPlatform::BUILT_IN_ROLES` holds `owner => ['*']`, `admin` with
twenty-three strings, `member` with twelve. [Verified: `Role.php`, `SeedPlatform.php`.]

A membership is `membership(user_id, company_id, role_id, created_at)`, unique on `(user_id, company_id)` —
one role per person per company. [Verified: `Membership.php`.]

`PermissionVoter` is the single decision point. It supports any well-formed permission string; it answers
`platform.*` from the account's operator flag, and everything else from the membership in the company being
acted on — the `Company` passed as the voter's subject, else the session company from `CurrentCompany`. It
then requires the company to be active and the subscription to permit that particular string. **The subject
is a `Company` or nothing; no other subject type is consulted.** [Verified: `PermissionVoter.php`.]

`Establishment` already exists, and is the fiscal one: a place a company issues documents from, whose code
is the establishment part of a registration number — the three digits closing a Tunisian matricule fiscal,
the NIC of a French SIRET. A company starts with one, exactly one is its default, and documents and
numbering series name the issuing establishment. A chain's second café is therefore this entity and not a
new concept. [Verified: `Establishment.php`, `docs/SPEC.md` § 4.]

There is **no notion of a current establishment**. `CurrentCompany` has no sibling: `git grep` for
`CurrentEstablishment` across `api/src` and `web/src` returns nothing. [Verified: the grep returned no
files.] `CompanyFilter` is a Doctrine `SQLFilter` scoping every query on a `CompanyOwned` entity to the
company a request acts for; there is no equivalent for establishments. [Verified: `CompanyFilter.php`.]

`ModuleManifest` already carries a key, a label key, a list of module dependencies and a list of permission
strings, and modules already declare dependencies — `DeliveryNotesModule` requires customers and products.
[Verified: `ModuleManifest.php`, `DeliveryNotesModule.php`. Note in passing that row 94's "a manifest
declaring what its module needs" is therefore partly shipped already.]

`AuditLog` is append-only by construction: `company_id`, `entity_type`, `entity_id`, `action`,
`actor_user_id`, `changes jsonb`, `at`, `ip`. There is exactly one actor column and no second one.
[Verified: `AuditLog.php`.]

`Me` answers the SPA a flat `permissions: string[]` — "permission strings the user holds in the current
company; `["*"]` for an owner". The client checks it with an exact match plus the wildcard, at
`web/src/app/auth/auth-facade.ts:239`. [Verified: `Me.php`, `auth-facade.ts`.]

The account has a password, TOTP and WebAuthn passkeys, and recovery codes. It has no PIN of any kind:
`git grep -c pin` over `api/src` matches only substrings in unrelated words. [Verified: the grep.]

---

## 1. The three established models

### 1.1 RBAC, as NIST actually defines it

The NIST project page points at INCITS 359-2012 and at the reference model whose text is the published
draft. That document defines four components — Core RBAC, Hierarchical RBAC, Static Separation of Duty and
Dynamic Separation of Duty — and states that "Core RBAC is required in any RBAC system, but the other
components are independent of each other and may be implemented separately". Core requires many-to-many
user-role assignment, permission-role assignment and user-role review, and it "requires that users can
simultaneously exercise permissions of multiple roles. This precludes products that restrict users to
activation of one role at a time." A *session* is "a mapping between a user and an activated subset of
roles that are assigned to the user". SSD is "a pair (role set, n) where no user is assigned to n or more
roles from the role set"; DSD is the same shape constraining *activation* within a session rather than
assignment.
[Verified: `https://csrc.nist.gov/csrc/media/projects/role-based-access-control/documents/rbac-std-draft.pdf`
and `https://csrc.nist.gov/projects/role-based-access-control`, read by the research strand.]

Against the three needs the standard is blunt, and it is worth being exact about where it is silent rather
than assuming it covers them.

**Need (1) is outside it.** User assignment is a plain relation over users and roles. There is no notion of
an organisational unit qualifying an assignment, so "this membership applies to establishments A and B" has
no expression in the model. [Verified: the reference model defines `UA ⊆ USERS × ROLES` and nothing more.]

**Need (2) is outside it.** A permission is "an approval to perform an operation on one or more RBAC
protected objects" and an object is "an entity that contains or receives information". There is no
predicate relating a subject to an object, hence no notion of *own*. Expressing it in pure RBAC means one
role per owner. [Verified: the same document.]

**Need (3) has a name in the literature but not the mechanism the developer described.** SSD and DSD
constrain which roles one user may hold or activate; they prescribe nothing about sequencing, about a
second person acting, or about an audit record. The closer primary source is Ferraiolo and Kuhn's 1992
paper, which uses this exact case: "the separate transactions needed to initiate a payment and to authorize
a payment". It contrasts a static rule — nobody who may initiate may also authorise — which it calls
possibly "too rigid for commercial use", with a dynamic one that "allows the same individual to take on
both initiator and authorizer roles, with the exception that no one could authorize payments that he or she
had initiated", and concludes that "for the dynamic case, the system must use **both role and user ID** in
checking access to transactions".
[Verified: `https://csrc.nist.gov/CSRC/media/Projects/Role-Based-Access-Control/documents/ferraiolo-kuhn-92.pdf`,
read by the research strand.]

That last sentence is the useful one. The sanctioned answer to a two-person rule is a check against the
record's own history — who initiated this — and not a constraint on the role catalogue. It is application
workflow, and it is application workflow in all three models.

One thing twes-in should know about its own conformance: Core RBAC mandates many-to-many user-role
assignment and explicitly precludes restricting a user to one role at a time. One role per membership is
therefore a deliberate simplification below the standard, not a conformant reading of it. It is a defensible
simplification — row 53 rules it — but it should be recorded as a choice.

### 1.2 ABAC

NIST SP 800-162 frames the older models as special cases: "ACLs and RBAC are in some ways special cases of
ABAC in terms of the attributes used… RBAC works on the attribute of 'role'." Needs (1) and (2) are one
rule each in this model: `subject.establishments ∋ object.establishment`, and `object.owner == subject.id`.
Need (3)'s distinctness half fits as an environment condition, which § 3.1.3.5 describes as "context
information that generally is not associated with any specific subject or object but is required in the
decision process".
[Verified: `https://nvlpubs.nist.gov/nistpubs/specialpublications/nist.sp.800-162.pdf`, read by the research
strand.]

The cost is documented by NIST itself, and it lands directly on this product. § 3.1.2.3, "Need to Review
Privilege and Monitor Authorizations": "there are some requirements to know what access each individual has
before the requests are made. This is sometimes referred to as 'before the fact audit'. Before the fact
audit is often necessary to demonstrate compliance to specific regulations or directives… **An ABAC system
may not lend itself well to conducting these audits efficiently.** Evaluating the set of subjects that have
access to a given object requires a significant data retrieval and computation effort — possibly requiring
every object owner to run a simulation of the access control request for every known subject in the
enterprise." § 4 concludes without hedging that "an ABAC system is more complicated, and therefore more
costly to implement and maintain, than simpler access control systems". [Verified: the same document.]

This is the sharpest trade in the whole comparison, and twes-in is on the wrong side of it if it moves.
Core RBAC's user-role review is a table read. ABAC's is a simulation. `Me.permissions` — a flat list the
SPA reads to decide what to render — *is* a before-the-fact audit answer, produced by reading one row. A
company owner opening the member list and seeing what each person may do is the same read. Under ABAC
neither is a read.

### 1.3 ReBAC, and Zanzibar

The Zanzibar paper models access as relation tuples `⟨object⟩#⟨relation⟩@⟨user⟩`, where the user may itself
be a *userset* `⟨object⟩#⟨relation⟩`, which "allows ACLs to refer to groups and thus supports representing
nested group membership". Userset rewrite rules, declared per relation, compose three leaf operators —
`this`, `computed_userset` and `tuple_to_userset`, the last of which "computes a tupleset from the input
object, fetches relation tuples matching the tupleset, and computes a userset from every fetched relation
tuple. This flexible primitive allows our clients to express complex policies such as 'look up the parent
folder of the document and inherit its viewers'."
[Verified: `https://www.usenix.org/system/files/atc19-pang.pdf`, read by the research strand.]

Much of the system exists to prevent the *new enemy problem*, which the paper says "can arise when we fail
to respect the ordering between ACL updates or when we apply old ACLs to new content". The remedy is
external consistency plus a *zookie*, "an opaque byte sequence encoding a globally meaningful timestamp
that reflects an ACL write, a client content version, or a read snapshot", which the client stores beside
its own content and replays on later checks. SpiceDB reproduces this as ZedTokens, and documents the
trade directly: `minimize_latency` "can lead to a window of time where the New Enemy Problem can occur",
while `fully_consistent` "explicitly bypasses caching, dramatically impacting latency".
[Verified: the paper, and `https://authzed.com/docs/spicedb/concepts/consistency`, read by the research
strand.]

The scale the paper reports: more than two trillion relation tuples occupying close to 100 TB across more
than 1,500 namespaces, with the median namespace near 15,000 tuples; more than ten million client queries
per second, peaking in one December 2018 week at roughly 4.2M Check, 8.2M Read, 760K Expand and 25K Write —
reads outnumbering writes by two orders of magnitude; more than 10,000 servers in several dozen clusters,
replicated in more than thirty locations. On latency two different figures must be kept apart: the abstract
claims 95th-percentile latency under 10 ms sustained over three years, while § 4.2's measurements over that
week give 50th, 95th, 99th and 99.9th percentiles peaking at roughly 3, 11, 20 and 93 ms. Deeply nested
groups defeated the recursive evaluator entirely and needed a separate specialised index, Leopard (§ 3.2.4).
[Verified: the paper.]

Modelled here: need (1) is the parent-child pattern, which OpenFGA spells `define editor: [user] or editor
from parent` — an establishment becomes an object, a membership becomes one tuple per establishment, and
"empty means all" needs a separate wildcard or an `all_establishments` relation, because the absence of a
tuple is indistinguishable from a denial. Need (2) is a direct `owner` relation per order — which means
**one tuple per business row**: the order table gains a shadow write into a second datastore, and that
write must succeed or the authorisation data silently diverges from the business data.
[Verified: `https://openfga.dev/docs/modeling/parent-child`, read by the research strand; the shadow-write
consequence is Inferred from the tuple model, which the paper describes but does not editorialise on.]

The costs, stated plainly. A second service and a second datastore, each with its own consistency
protocol. The new enemy problem becomes the product's to reason about, and every read path must choose a
staleness level. The SQL join is gone: "the orders this waiter owns" can no longer be `WHERE owner_id = ?`
against the same database as the orders. [Inferred: the paper places tuples in a separate namespace store
and makes no claim about the caller's own database; the consequence follows.] And the direction a user
interface needs most — "what may this person do on this screen" — is the expensive one: `Expand` is
Zanzibar's second-rarest API, and OpenFGA and SpiceDB expose `ListObjects` and `LookupResources` per
`(type, relation)` pair, so a screen needing ten flags costs ten calls. [Inferred from the published API
shapes.]

ABAC inside ReBAC exists. OpenFGA *conditions* are CEL expressions which "can be used to represent some
Attribute-based Access Control (ABAC) policies", with context capped at 32 KB per tuple and an evaluation
cost cap; *contextual tuples* pass unstored relationships at check time but "do not persist in the store"
and work only on the Check, ListObjects and ListUsers endpoints. SpiceDB's caveats are the equivalent, and
return a third answer, `PERMISSIONSHIP_CONDITIONAL_PERMISSION`, when context is missing.
[Verified: `https://openfga.dev/docs/modeling/conditions`,
`https://openfga.dev/docs/modeling/token-claims-contextual-tuples`,
`https://authzed.com/docs/spicedb/concepts/caveats`, read by the research strand.]

**On need (3), plainly: neither system documents a two-person rule.** OpenFGA's modelling index lists
fifteen patterns and none of them is dual approval, four-eyes or separation of duty; SpiceDB's schema guide
documents additive composition and arrow traversal and nothing else. A `Check` evaluates one triple, so the
distinctness half — the authoriser is not the actor — is expressible as a condition over request context,
but the pairing of the two acts and the record of it is application work in all three models.
[Verified: `https://openfga.dev/docs/modeling`,
`https://authzed.com/docs/spicedb/modeling/developing-a-schema`, read by the research strand.]

### 1.4 Casbin

Casbin's *RBAC with domains* is the closest well-documented match to a multi-tenant role model: a
three-argument grouping function, `g(r.sub, p.sub, r.dom)`, where "the same user can have different roles in
different domains (tenants)" and `dom` may be renamed tenant or workspace. That maps cleanly onto a
company-scoped membership, and it improves on twes-in in one respect — it permits several roles per domain.
It stops exactly where the three needs begin: the documentation covers pattern matching on domain values
but describes no sub-domain scoping, no resource ownership and no domain hierarchy. Its ABAC page is
explicit about the boundary: "ABAC attribute access works only for request elements (`r.sub`, `r.obj`,
`r.act`). Policy elements like `p.sub` cannot use ABAC since policies cannot store struct or class
definitions", so `object.owner == subject.id` must be computed in the matcher at request time, at a stated
cost of roughly 1.1 to 1.5 times.
[Verified: `https://casbin.apache.org/docs/rbac-with-domains` and `https://casbin.apache.org/docs/abac`,
read by the research strand.]

### 1.5 What Symfony's own documentation sanctions

This matters more than the rest of section 1, because the standing ruling of 2026-09-17 is that every tool
is used as its own documentation recommends.

Symfony calls voters "Symfony's most powerful way of managing permissions", and the documented way to
express "may edit *this* object" is a voter with a subject:
`$this->denyAccessUnlessGranted(PostVoter::EDIT, $post)`, or declaratively `#[IsGranted('view', 'post')]`.
`supports(string $attribute, mixed $subject)` selects and `voteOnAttribute()` decides, with an optional
`$vote` argument to "provide an explanation for the vote". Two pieces of guidance bear on what follows:
the documentation concedes that "if you don't reuse permissions or your rules are basic, you can always put
that logic directly into your controller instead"; and it warns that inside a voter one must inject
`AccessDecisionManagerInterface` rather than call `Security::isGranted()`, because the latter "does not
guarantee that the checks are performed on the same token as the one in your voter". `access_control` is
the coarse counterpart — it matches on path, host, method and the like, "only the first matching
`access_control` is used", and it has no object, so it can express none of the three needs.
[Verified: `https://symfony.com/doc/current/security/voters.html` and
`https://symfony.com/doc/current/security/access_control.html`, read by the research strand.]

The conclusion is direct. `PermissionVoter` — a permission string plus an optional subject — is already the
shape Symfony documents. Needs (1) and (2) are additional subject types consulted by the same voter, not a
new mechanism and not a new library. No Symfony documentation was found comparing role hierarchy against
voters as alternatives; the documentation describes role hierarchy as static role expansion and voters as
the object-aware mechanism, and does not editorialise on choosing between them. [Unverified: the absence
was searched for and not found; a comparison may exist somewhere in the documentation set that the search
did not reach.]

### 1.6 At what scale a flat list stops being adequate

**No primary source gives a threshold, and the research did not find one.** Any figure of the form "RBAC
breaks past N roles" is quoting nothing. [Unverified: searched, not found.]

What the literature gives instead is a mechanism, and the authoritative statement is NIST's own, SP 800-162
§ 2.1: RBAC "does not easily support multi-factor decisions… role assignments tend to be based upon more
static organizational positions, presenting challenges in certain RBAC architectures where dynamic access
control decisions are required. Trying to implement these kinds of access control decisions would require
the creation of numerous roles that are ad hoc and limited in membership, **leading to what is often termed
'role explosion'**." [Verified: the same document.]

That is the test, and it is qualitative rather than numeric: a dotted-string permission list has been
outgrown at the moment **a new role must be minted per instance of something** — per establishment, per
owner, per approval pair. Judged that way, needs (1) and (2) as the developer framed them are *not* role
explosion. Scoping the membership to establishments and comparing ownership at decision time both leave the
role catalogue fixed; it is the naive alternative — a `manager@cafe-7` role per branch, a role per owner —
that explodes. The three additions are, on this evidence, precisely the changes that *prevent* the model
from breaking. [Inferred: from NIST's definition applied to the two designs.]

The contrary framing exists and should be read for what it is. OpenFGA states that "RBAC fits flat,
single-tenant access models but breaks down with hierarchy, sharing, or multi-tenancy" and that ReBAC "is a
superset of RBAC and natively covers ABAC scenarios when attributes are expressed as relationships"
([`https://openfga.dev/docs/authorization-concepts`], read by the research strand). That is **vendor
documentation about the vendor's own product's coverage**, unaccompanied by evidence, and twes-in is
multi-tenant today without having broken down.

---

## 2. What point-of-sale software actually does

All of section 2 comes from public help centres, user guides and one vendor-published PDF. No source code
and no code repository was read, under the licensing invariant in `CLAUDE.md`. Where a product's
documentation does not answer a question, that is recorded as an absence rather than filled by inference.

### 2.1 The manager override

Two designs run through the market, and choosing between them is the decision that matters.

**Pattern A — a second credential on the same terminal.** The restricted act raises a prompt where the
cashier is standing; a manager enters *their own* passcode or swipes *their own* card; the act completes,
attributed to the cashier who asked for it, with the approver recorded beside them.

**Pattern B — a role gate and nothing else.** The act is simply absent from the cashier's screen, and the
only recourse is a manager signing in, which makes the manager the actor and erases the person who needed
the act from the record entirely.

| Product | Pattern | Approver identified | Per-act configurable | Approver recorded |
|---|---|---|---|---|
| Toast | A — passcode or swipe card | individual, unique per employee per location | yes, ~30 numbered permissions | yes, "by Approver" columns |
| Shopify POS | A — manager's own PIN | individual | yes, three states per permission | yes, in the event details |
| TouchBistro | A — manager passcode per act | individual | yes, one toggle per act | not documented |
| Revel | A — manager PIN or swipe card | individual (implied) | yes, rank-tiered | not retrieved |
| Lightspeed L-series | A — "elevated permissions" | individual, unique 4-digit | yes, named roles | not documented |
| Lightspeed K-series | B | actor only; no approval event | yes, granular | no — see below |
| Square | B | personal **or shared** passcode | yes, per task | not in the help centre |
| Loyverse | B — override is a user switch | individual, unique 4-digit | yes | no log documented |
| Odoo (core) | B — three fixed tiers | individual PIN or badge | no | no |

[Verified: the URLs listed in § 6, read by the research strand.]

Toast is the reference implementation of Pattern A. Permissions attach to a *job* rather than to a person,
and an employee lacking one is interrupted: "a prompt for a manager POS access code or swipe card appears";
"restaurant employees that do not have manager permissions must ask a manager to enter a passcode before
the discount can be applied". Passcodes are individual — "a unique three-to-eight digit number assigned to
each employee… no two employees at the same location can share the same code" — and swipe cards exist
explicitly to reduce "the chances of passcode theft and high-level manager permissions abuse". Each act is
its own permission, including Void Items, Bulk Void, Void/Refund Payments, Discounts, Price, **No Sale**,
**Pay Out** and Shift Review. And the two-party record is real: the exceptions report carries "Voids by
Server" *and* "Voids by Approver", and the same for discounts and no-sale. The per-check update history is
retained for two weeks only. [Verified: the Toast URLs in § 6.]

Shopify POS is the cleanest model to copy, and the one closest to the developer's proposal. Every point-of-
sale permission has three states — Allowed, Denied and **Approval required** — and approval "requires staff
with manager approval and [permission] permissions allowed to enter their PIN". The consequence is worth
stating in full because it simplifies the design: **a manager can only approve what they could do
themselves.** The log is explicit — "when a manager approves one of these actions, the approving manager is
recorded as part of the event details, alongside the staff member who performed the action" — though
"manager approvals aren't recorded as their own separate events", and drawer opens and cash in and out are
not logged at all. [Verified: the two Shopify URLs in § 6.]

Square is the cautionary case, and it says so itself: alongside personal passcodes it offers a shared team
passcode, and the help centre states that "a team passcode does not support the ability to track time,
sales, or activity by team member". Its activity log appears on marketing pages and not in the help centre,
so who approved what is genuinely unanswered there. Lightspeed's K-series has a narrower but sharper hole:
the Cancellations and Corrections report's columns are receipt, account, item, quantity, amount, date and
reason — **no user column and no approver column**. [Verified: the Square and Lightspeed URLs in § 6.]

Odoo's core product has no per-act approval at all, only three fixed tiers that silently allow or hide
refunds, manual discounts, price changes and closing the register; the gap is filled by paid third-party
modules, one of which advertises exactly the anti-pattern to avoid — a single configured PIN shared by
everyone, plus automatic silent approval when the cashier already holds the manager role. That listing is
evidence of demand, not of Odoo's behaviour. [Verified: the Odoo documentation URL and the apps-store
listing in § 6.]

Three findings follow, and they bear directly on the developer's proposal (3).

**Individual credentials are common practice.** A shared PIN appears only as a documented downgrade (Square
saying plainly what it costs) or as an aftermarket shortcut. Every product that documents Pattern A
documents the approver's own credential.

**A percentage threshold carried on the employee is *not* common practice.** Only Lightspeed's X-series
documents one. The convergent shape is a flag on the *discount object*: Square's per-discount "require a
passcode" plus a maximum discount value, Toast's per-discount permission level, Loyverse's discounts with
restricted access. This is a correction to the data model implied by the developer's wording, not a
correction to the intent.

**Remote approval and an unreachable-manager fallback are documented by none of the twelve products
examined.** The absence is the answer: every one of them assumes a manager physically present. [Verified:
the twelve help centres were searched for it and none was found — bounded, for Square, by roughly
twenty-five pages fetched before the search budget ran out, so that one is an absence in a large sample
rather than an exhaustive sweep.]

### 2.2 A waiter's own tables

Scoping is **per-permission on the role**, never a global "restaurant mode" switch, in four of the five
products that document it at all.

Toast states it exactly: "only employees with the 1.8 View Other Employees' Orders permission see other
servers' tables in the pane", and removing it "also removes other servers' checks from the All checks and
Payment Terminal screens". That is the `_own` / `_any` pairing in another vocabulary. Lightspeed's K-series
makes the same ownership visible in the interface — "Green tag: the occupied table belongs to the logged-in
user… Red tag: belongs to another user and the logged-in user does not have permission to open the order" —
governed by a "Table access" permission sitting beside "Table transfer" and "Deactivate table protection".
Square's evidence is a permission's name, "view all open tickets for individual team members"; Loyverse's
is "Manage all open tickets". **None of them states the out-of-the-box default.** TouchBistro is the
outlier: its server-to-table assignment is cosmetic and "does not lock tables to particular servers". Odoo
documents no table ownership at all. [Verified: the URLs in § 6.]

Transfer is its own permission everywhere it is documented, and Lightspeed's L-series splits it along
exactly the axis under discussion: "allow transfer ownership of **user's own** open receipts" against
"**any** open receipts". Toast gates it on its own permission, notes that the receiving server "must be
clocked in", and records that "there is no available feature to accept, review, and reject transfers" — a
transfer is a push, not a handshake. [Verified: the Lightspeed and Toast URLs in § 6.]

Handover has two documented shapes and they are worth choosing between deliberately. **Retroactive**
(Toast): reopen the departed server's shift by removing their clock-out, transfer, then re-run the shift
review. **Preventive** (TouchBistro): clocking out is blocked until every order, tab and table is closed or
transferred. Square documents the case itself — "when one team member ends their shift, leaves in the
middle of service, and needs to pass the check to another clocked-in team member" — gated by a passcode or
the permission. Lightspeed documents no handover procedure in either series. [Verified: the URLs in § 6.]

Note that twes-in has already ruled the substance of this, differently and better, at § 7 2026-09-20 02:20:
the tables stay open, each order keeps the waiter who took it, and what a handover settles is the cash the
waiter holds. That ruling stands; what this section adds is that the *visibility* question is separate from
it and is solved per-permission everywhere.

### 2.3 Staff across several sites

**Unanimous: one employee record carrying a list of locations.** Not one product documents a separate
record per site. Square: "the locations you assign to a team member determine what they can access… A team
member assigned only to Location A won't have access to data or features associated with Location B."
Lightspeed K: "POS and Back Office users are shared across locations, but users only have access to the
location they were created in by default." Loyverse, Shopify POS, SumUp and Lightspeed's retail line are
the same shape. [Verified: the URLs in § 6.]

The market splits on a second question, and the split is the one that matters for the verdict below:
**whether the role itself is scoped per location.** Toast says yes explicitly — the same person may hold
Assistant Manager at one restaurant and Server at another, so they may void at the first and not at the
second, and an administrator may only assign a job if they themselves hold all its permissions *at that
location*. Revel says yes: profiles link to multiple establishments with establishment-specific roles.
Shopify POS says no: the role is global to the profile and only the PIN is location-scoped. Square and
Lightspeed K leave it undocumented. Two secondary facts: Toast scopes passcode *uniqueness* per location,
and Lightspeed K splits point-of-sale users from back-office users, so a manager needing both holds two
records. [Verified: the URLs in § 6.]

---

## 3. The legal angle

### 3.1 France, which is the better-documented comparable

**The obligation.** Article 286-I-3° bis of the code général des impôts requires a VAT-taxable person
making supplies *not giving rise to an invoice*, and recording the payments received in software or a cash
system, to use one satisfying conditions of *inaltérabilité, sécurisation, conservation et archivage*,
evidenced either by a certificate from an accredited body or by an individual attestation from the editor.
In force since 1 January 2018; pure business-to-business activity, the franchise en base and exempt
operations are outside it.
[Verified: `https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051764897` and
`https://www.impots.gouv.fr/professionnel/questions/quel-est-le-champ-dapplication-de-lobligation-de-detenir-un-logiciel-de`,
read by the research strand.]

Two forward-looking facts to carry: article 286 was amended by loi n° 2026-534 du 25 juin 2026, article 87,
in force 27 June 2026 — the current Légifrance text still shows both proof routes, but what article 87
changed was not read [Unverified: the amending text was not reached]; and the article is abrogated from
1 January 2027 by ordonnance n° 2025-1247 du 17 décembre 2025, article 9, recodified into the code des
impositions sur les biens et services at article L. 215-26. [Verified: the Légifrance page.]

**The attestation timeline, which is commonly reported wrongly.** Article 43 of the loi de finances 2025
removed the editor's individual attestation from 16 February 2025, leaving certification only from
1 September 2026. **That removal never took effect.** Article 125 of the loi de finances pour 2026 (loi
n° 2026-103 du 19 février 2026) "rétablit la possibilité, à compter du 21 février 2026, de justifier […]
par la production d'une attestation individuelle délivrée par l'éditeur". Any source still saying
"certification only from September 2026" is describing the pre-reversal timeline and is stale — several
vendor pages do. The attestation must follow the model at BOI-LETTRE-000242, be nominative, and name the
software, its version, the licence number and the acquisition date.
[Verified: the 20260325 version of BOI-TVA-DECLA-30-10-30 §§ 270, 360–390 at
`https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20260325`, and the
20251001 version § 275 for what was reversed, read by the research strand.]

**What must be recorded, and the correction rule.** § 50 enumerates, line by line for every transaction:
the receipt number; the date to the minute; the register's number; the total including tax; the detail of
each article or service (description, quantity, unit price, line total excluding tax, associated VAT rate);
all data relating to the receipt of payment, the means of payment in particular; **"les traces de
modifications et corrections apportées aux transactions enregistrées"**; and "l'ensemble des données
permettant d'assurer la traçabilité et de garantir l'intégrité des données".

§ 90 is the decisive sentence for proposal (3):

> Si des corrections sont apportées à ces données […] ces corrections (modifications ou annulations)
> s'effectuent par des opérations de « plus » et de « moins » et non par modification directe des données
> d'origine enregistrées.

A void is a new, dated, signed counter-entry. Deleting or editing the original line is unlawful.
*Inaltérabilité* (§ 100) has a high-level limb — remove any user function that could modify, and detect
circumvention — and a low-level limb — "empreinte numérique à clé privée, chaînage" — and must cover "toutes
les données (enregistrement initial comme correction[s])" together with "une fonctionnalité de suivi des
modifications". *Sécurisation* (§ 130) forbids "suppression ou modification sans laisser de trace"; § 140
names record chaining and electronic signature as accepted techniques. *Conservation* (§§ 155, 180)
requires keeping the line-by-line detail and not merely the daily totals — a business keeping only Z totals
is in breach. *Archivage* (§§ 220–230) requires freezing the data, giving it a certain date, an open format
and French documentation. *Données cumulatives* (§ 170) requires daily, monthly and annual closures, each
producing "données cumulatives et récapitulatives, intègres et inaltérables" — the period's grand total and
a perpetual total since first use, never reset except on a change of equipment or software, and inalterable
even after a purge. [Verified: the 20260325 BOFiP text, read by the research strand.]

**Operator identification — the crux, and the answer is no.** The § 50 enumeration contains no cashier or
operator field. The only express operator-identification clause in the whole doctrine is § 150, on the
training or test mode, which must be secured by clear marking of the fictitious data *and* by
"l'identification de l'opérateur sous la responsabilité duquel le personnel en formation enregistre les
données". So French law requires that the *act* be traced, not that the *actor* be named — except in
training mode. The catch-all in § 50 is elastic enough that an inspector could argue for it, but there is no
express requirement. [Verified: the same text, searched for the field and not finding it.]

**NF525.** Infocert's public page documents only the high-level ISCA principles — detecting and tracing any
access or modification, generating a digest and applying an electronic signature, periodic closures, secure
fiscal archiving in an open format. It does **not** publicly document the journal of events, the sequential
numbering, the chaining specifics, duplicate-ticket handling or operator identification; the référentiel
itself is a paid document and was not read. The LNE public page did not resolve. The widely-circulated
details about the journal and SHA-256 chaining trace to vendor pages, which are plausible and unverified.
**Explicitly: nothing in any official or standards-body text found requires a manager's authorisation to
cancel a sale.** On present evidence it is a commercial control, not a legal one.
[Verified for what Infocert publishes: `https://www.infocert.org/certifications/nf525/`. Unverified for the
référentiel: it is paywalled and was not read.]

### 3.2 Tunisia

The instrument is **décret gouvernemental n° 2019-1126 du 26 novembre 2019**, activated by an **arrêté du
ministre des Finances du 14 octobre 2025**, published in **JORT n° 125 du 14 octobre 2025**.

**The JORT text was not read.** `iort.gov.tn`, `legislation.tn` and `legislation-securite.tn` returned
nothing usable, and `finances.gov.tn` has no reachable homologation page. The enabling loi de finances
article is therefore not established and is not guessed at here. Everything in this subsection is press,
corroborated across outlets, and is labelled as such.
[Unverified: the official text was sought at four government sources and not reached.]

**Scope** is *services de consommation sur place* — restaurants, salons de thé, cafés, fast food — that is,
on-premises food and drink, and **not retail shops generally**. [Press, consistent across four outlets: see
§ 6.] This matches, and now sources a little better, what `docs/SPEC.md` § 7 already records at
2026-09-20 03:55.

**Dates.** 1 November 2025 for legal persons running classified tourist restaurants, salons de thé and
second- and third-category cafés; **1 July 2026 for all other legal persons** in the sector. The 1 July 2026
date the spec already carries is corroborated by several press outlets and by no text that was read. Later
phases of 1 July 2027 for natural persons under the real regime and 1 July 2028 for others come from a
single vendor page and are weaker still. [Press and vendor; see § 6.]

**On voids and journalling** there is one genuinely useful sentence, attributed to the regulation by press:
the register must record the customer's purchases of goods or services, the issue of pro-forma tickets,
**"les opérations de remboursement"** and **"les opérations effectuées durant la période de formation"**.
Refunds and training-mode operations are therefore inside the recording perimeter, exactly as in France and
Germany. Homologation is by the Ministry of Finance, and the device comprises a data-entry module and a
"module de sécurisation des données" responsible for protecting and transmitting the data — so Tunisia is a
*transmitting* fiscal-register regime, closer to Italy than to France. Claims of real-time transmission,
mandatory daily closures and a prescribed ticket layout come from a vendor page and are unconfirmed.
Penalties are referred to as "sanctions financières" with no amounts, and no official penalty text was
found. [Press and vendor; see § 6.]

**Net: Tunisia's exact inalterability and correction rules are not established from any source reached.**
`docs/fiscal/TN.md` currently says nothing at all about cash registers — a grep for *caisse*, *homolog* and
*NACEF* returns no line — and that is where this must be sourced before a till is sold to a Tunisian café.
[Verified: the grep.]

### 3.3 Germany and Portugal, briefly, to test whether the pattern is universal

**Germany** is the strongest primary confirmation. § 146a AO requires each recordable transaction to be
recorded "einzeln, vollständig, richtig, zeitgerecht und geordnet" and protected by a certified technical
security device. DSFinV-K 2.4, the mandatory audit export, is precise about voids at § 4.2.3: a flag on the
original is permitted only before the security device has signed it, and "sobald die Transaktion in der TSE
signiert ist, darf das Feld `P_STORNO` nicht mehr verwendet werden" — after signature the only lawful void
is "ein neuer Datensatz mit umgekehrtem Vorzeichen […] der wiederum abgesichert werden muss". § 4.2.6
requires training bookings to be logged and secured precisely because training mode has been used "um
steuerpflichtige Bareinnahmen nicht zu erfassen" — the same rationale as France's § 150. On the operator,
DSFinV-K defines `BEDIENER_ID` ("Unternehmensinterne Kennung der Person, die den Vorgang erfasst") and
`BEDIENER_NAME` in the receipt header of the mandatory export, and anticipates operator sign-ins being
journalled. Whether the law obliges the field to be populated was not established — the KassenSichV § 2
text did not resolve.
[Verified: `https://www.gesetze-im-internet.de/ao_1977/__146a.html` and the BZSt DSFinV-K page, read by the
research strand. Unverified: KassenSichV § 2.]

**Portugal** requires both halves. Portaria n.º 363/2010, article 3, conditions certification on, among
other things, "c) Possuir um controlo do acesso ao sistema informático, obrigando a uma **autenticação de
cada utilizador**" and "d) **Não dispor de qualquer função** que, no local ou remotamente, **permita
alterar, direta ou indiretamente, a informação de natureza fiscal, sem gerar evidência agregada à
informação original**", with each document signed and chained to the signature of the previous document of
the same type and series. Note the precise scope: authentication *of each user*, which is not literally the
same as every record naming its operator.
[Verified: the Portaria PDF at `portaldasfinancas.gov.pt`, read by the research strand.]

**Not established, and stated rather than paraphrased from memory:** the OECD's 2013 report *Electronic
Sales Suppression* (403 on four URLs), Austria's RKSV (BGBl. II 410/2015, empty responses), and Italy's
Agenzia delle Entrate registratore telematico specifications (404). The pattern is therefore *evidenced*
across France, Germany and Portugal — three jurisdictions, three different mechanisms, the same two rules —
and not *proven* near-universal.

### 3.4 What this means for the three proposals

Three conclusions, and the third is the one that reframes proposal (3).

**Second-person authorisation exceeds every text found.** No jurisdiction that could be read requires a
manager to approve a void, a discount or a refund. It is a good internal control, and no certification
scheme found will object to it — but it earns no compliance credit and must never be presented internally
as satisfying a legal requirement. [Verified for France and Germany and Portugal by absence in the texts
read; Unverified for Tunisia, whose text was not reached.]

**What the design must not fall short on**, each with its source: the original sale is never edited or
deleted, and a void is a new, dated and signed counter-entry (France § 90; Germany DSFinV-K 4.2.3; Portugal
article 3 d); every modification leaves a trace inside the recorded data (France §§ 50, 130); inalterability
covers corrections as well as initial records (France § 100); daily, monthly and annual closures produce
inalterable cumulative data including a perpetual total that survives any purge (France § 170); and a
training mode exists, is journalled, is marked fictitious and **names the supervising operator** (France
§ 150; Germany 4.2.6; and Tunisia's regulation places training-mode operations inside the recording
perimeter, per press).

**"Reopen a shift" is the one act in the proposal that risks *conflicting* with the law rather than
exceeding it.** Once a daily closure has produced inalterable cumulative data, a reopen must not recompute
or amend that record. But the developer's wording lumps two different things together, and they must be
split. The till's shift close *is* the day's Z (§ 7 2026-09-20 02:20); that one is never reopened — what can
be opened is a *correction period* whose movements are counter-entries carrying their own timestamps. A
waiter's purse count at a handover is not a fiscal closure at all, and reopening it is an ordinary
commercial act, authorisable like any other.

---

## 4. Verdicts

### 4.1 Establishments named on a membership — **CONFIRM WITH A CHANGE**

**Confirmed**, and by the strongest agreement in the whole research: one person record carrying a list of
locations is *unanimous* across every point-of-sale product whose documentation answers the question, with
no product documenting a separate record per site. [Verified: § 2.3, the Square, Lightspeed, Toast,
Shopify, Loyverse and SumUp help centres.] It is also the shape that avoids role explosion rather than
causing it — the alternative, a `manager@cafe-7` role per branch, is exactly what NIST SP 800-162 § 2.1
names. [Verified: the NIST text; the application to this case is Inferred.]

**Three changes.**

*First, and much the largest: this is not a column on `membership`.* There is no current-establishment
notion in the product at all — `CurrentCompany` has no sibling, and `CompanyFilter` has no counterpart.
[Verified: the grep and the two files.] The real work is a request-scope concept and a second Doctrine
filter, mirroring what already exists for companies. The membership's list is the small half.

*Second, the filter is narrower than `CompanyOwned` and must be declared, not derived.* A waiter scoped to
café A still needs the shared products, the shared customers and the shared menu; only what *carries* an
establishment is filtered — documents, numbering series, stock locations, register sales, table orders.
A separate marker interface beside `CompanyOwned`, with an architecture test naming which entities carry it,
is the shape; deriving it from the presence of an `establishment_id` column would be silent and wrong the
first time an entity gains one for a different reason. [Speculative on the mechanism; the narrowness is
Verified from `Establishment.php`'s own docblock, which names documents and numbering series as what
references it.]

*Third, "empty means all" needs a second bit.* A membership with no establishments and a membership whose
every establishment was deleted are the same stored state with opposite meanings. An explicit
`all_establishments` flag beside the list distinguishes them; a join table rather than a JSON array gives
the foreign key that makes a deleted establishment's disappearance from the list a database fact rather than
an application promise. [Speculative, but the failure mode is concrete: a chain closing a café would
silently widen one waiter's access to every remaining site.]

**And one thing to record as known and deferred.** Toast and Revel scope the *role* per location, not only
the visibility: the same person is Assistant Manager at one restaurant and Server at another, so they may
void at the first and not the second. [Verified: the Toast enterprise documentation.] twes-in cannot
express that, because row 53 rules one role per member and the unique constraint on `membership` enforces
it. This is a real limit on the chain case and it collides with row 53 head-on; it should be recorded now
and decided when a chain asks for it, not designed around today.

**The owner must be exempt.** Nothing in the design should allow an owner's membership to be scoped to a
subset of establishments, or a chain owner can lock themselves out of their own company. The last-owner
rule of row 53 is the existing precedent for this shape of guard.

### 4.2 Paired `_own` / `_any` permissions — **CONFIRM**

**Confirmed, on three independent grounds.**

It is what the market does, in the developer's exact vocabulary: Toast's "1.8 View Other Employees' Orders"
is the `_any` half of precisely this pair, and Lightspeed's L-series splits transfer as "user's own open
receipts" against "any open receipts". [Verified: the Toast and Lightspeed URLs.]

It is what Symfony documents: a voter with a subject is the sanctioned way to express "may act on *this*
object", and the standing ruling of 2026-09-17 is that every tool follows its own documentation. [Verified:
`symfony.com/doc/current/security/voters.html`.]

And it is free in the grammar: `Permission::isWellFormed` already allows underscores inside a segment, so
`service.order.write_own` is a valid permission string today with no regex change. [Verified: the regex in
`Permission.php`, read directly.]

**Three notes rather than changes.**

*`_any` must imply `_own`, and the implication belongs in the voter.* `Role::grants` is an exact match, so
without an implication every manager role must list both strings and every role template must be kept in
step — a drift waiting to happen. One rule in `PermissionVoter` — an `_own` check is satisfied by holding
the `_any` string — puts it in one place. [Speculative on the placement; the drift risk is Verified from
`Role::grants`'s exact-match implementation.]

*The check needs a subject the voter does not accept today.* `PermissionVoter` consults a `Company` subject
and nothing else. Answering `_own` means a second marker interface — an entity that can say which member
owns it — and a branch in the voter that consults it. A request that passes no subject must keep working
exactly as it does now. [Verified: `PermissionVoter.php` lines 54–65.]

*The client does an exact match too.* `web/src/app/auth/auth-facade.ts:239` checks `permissions.includes('*')
|| permissions.includes(permission)`. Either the client learns the same implication, or `Me` expands
`_any` into both strings before answering. Expanding in `Me` keeps one rule on the server and leaves the
client unchanged, which is the smaller change. [Verified: the line; the recommendation is Speculative.]

**And one addition the pair does not cover.** Reading and writing are different acts on a table: a waiter
who may not *write* another waiter's order may still need to *see* the floor. Toast's permission is a
*view* permission. Transfer is a third act, and Lightspeed splits it own-versus-any separately from
viewing. The pairing is right; the set of acts it applies to is larger than "write".

### 4.3 Acts requiring authorisation — **CONFIRM WITH A CHANGE**

**Confirmed in substance.** It is Pattern A, which the best-documented products implement — Toast, Shopify
POS, TouchBistro, Revel and Lightspeed's L-series — and the alternative, Pattern B, has a specific and
serious defect: when the manager signs in to perform the act, *the manager becomes the actor and the person
who needed the act disappears from the record*. For a product whose register will one day face a fiscal
inspection, keeping the cashier as the actor and recording the approver beside them is strictly better.
[Verified: the help-centre documentation in § 2.1.]

**Four changes.**

*First — and this is the one that must not be compromised — the credential must identify the authoriser
individually.* Square documents what the alternative costs, in its own words: "a team passcode does not
support the ability to track time, sales, or activity by team member". A shared till PIN produces an audit
row that names nobody, which is worse than no control at all because it looks like one. [Verified: the
Square help article.]

*Second, "over a threshold" does not belong on the member.* Only one product of twelve puts a percentage
limit on the employee; the convergent shape is a flag on the discount itself. There is no discount object
in twes-in yet — row 82 is `todo` — so the twes-in-native interim is the settings engine, which already
exists and already resolves per company: a `register.discount.max_unauthorised_percent` value on the
company chain maps onto Lightspeed X-series' documented maximum, needs no new entity, and is replaced by a
per-discount flag when the discount object arrives. [Verified: the market shape in § 2.1 and the settings
engine in `api/src/Settings/`; the mapping is Speculative.] Note that a threshold does not fit the
authorisation rule recommended just below unless the permission is a *pair* — see § 5, where
`register.discount.apply` is bounded by the setting and `register.discount.apply_any` is not.

*Third, split "reopen a shift" in two*, per § 3.4. The till's Z is never reopened — a correction period is
opened. A waiter's purse count is not a fiscal closure and may be reopened under authorisation like any
other act.

*Fourth, be explicit internally that this is an internal control and not compliance.* Nothing in any text
that could be read requires it, and what the law does require — the preserved, signed, chained counter-entry
— is a different record entirely. That is § 4.4.

**On the mechanism, a smaller option than the one proposed.** The developer's wording implies a separate
authorising permission per act. Shopify's documented rule is simpler and produces the same outcome: a
manager can only approve what they could do themselves. Applied here, the manifest declares which acts are
*authorisable*; a role either grants the act or does not; a member whose role lacks it may perform it under
authorisation from any member whose role *holds* it. That mints no new permission strings, adds nothing to
any role template, and cannot drift out of step with the act it guards. The separate `.authorise` string
remains the alternative, and it buys one thing the smaller option does not: true separation of duty, where
the authoriser is someone who could *not* do it themselves. Ferraiolo and Kuhn already called that variant
"too rigid for commercial use". [Verified: the Shopify article and the 1992 paper; the recommendation is
Speculative.]

One open question that only the developer can answer: whether a role needs a third state — *never, not even
under authorisation*, which is Shopify's "Denied" — or whether every authorisable act is askable by anyone
with register access. Shopify's three states exist because the answer is sometimes no.

### 4.4 What the three miss

**The largest gap, and it is larger than all three additions together: the fiscal journal.** All three
proposals are about *who may act*. None is about *what the record must look like*. The law read in § 3 says
almost nothing about the first and a great deal about the second: a void is a new, dated, signed
counter-entry, the original untouched (France § 90, Germany DSFinV-K 4.2.3, Portugal article 3 d);
inalterability covers the corrections as well as the original; the records are chained or signed; and daily,
monthly and annual closures produce cumulative data that survives a purge. `audit_log` is an application
log — it is append-only by construction, which is good, but it is not chained, not signed, and not the
sales journal. Recording *who authorised a void* in `audit_log` while the void itself deletes or mutates a
line would satisfy the developer's rule and breach the law. The Register module needs its own journal, and
that is a larger piece of work than these three additions. [Verified: the BOFiP and DSFinV-K texts;
`AuditLog.php` for what exists.]

**Training mode is absent from the spec and required by two jurisdictions.** France § 150 and Germany
§ 4.2.6 both require it to exist, be journalled and be marked fictitious — and France requires it to name
the supervising operator, which is the *one* place in the French doctrine where an operator must be
identified. Tunisia's regulation places training operations inside the recording perimeter too, per press.
A register sold to a café will be operated by someone being trained on their first day. Nothing in
`docs/SPEC.md` mentions it. [Verified: the BOFiP § 150 and DSFinV-K § 4.2.6 texts; the absence from the
spec by grep.]

**Time-boxed rights and delegation: the pattern already exists here, and should be reused rather than
invented.** No point-of-sale product documents either. [Verified: absence across twelve help centres.] But
twes-in already ruled one, at § 7 2026-09-17 (6): support reaches a company's data only through access its
owner grants, **time-boxed, read-only by default, visible while active and audited in the company's log**.
That is exactly the shape a delegation while someone is away needs — a grant with an end, visible while it
runs, in the audit log. Building a second mechanism for the same idea would be the mistake.

**Break-glass: recommend building none, and solving the real problem differently.** No product documents an
unreachable-manager path; every one assumes a manager physically present. A break-glass route is a hole
that will be used routinely within a month of shipping. [Speculative, but the absence across twelve
products is Verified.] The real problem — a night shift with no manager on site — has a better answer that
twes-in is unusually well placed to give: the manager authorises **from their own phone**, over the
realtime channel that already exists (`REALTIME_CONNECTOR`, Centrifugo), using the second factor they
already hold. None of the twelve products documents remote approval. That is a genuine differentiator, and
it makes the individual-credential requirement easier rather than harder to satisfy.

**Offline and a second person are in direct tension.** Row 82 rules offline selling deliberately out of the
first version but requires every sale to be "idempotent and queued in shape" so it can be added later. An
act that requires a second person's live authorisation cannot be queued: either the act is refused offline,
or it is recorded as pending and reconciled — and "pending" means a void that has not yet happened, which
runs into § 90's ordering. This should be decided when offline is designed, not discovered then.
[Verified: row 82's wording; the tension is Inferred.]

**A member with no establishments left** — see § 4.1, third change. It is small and it is a silent widening
of access, which is the worst combination.

---

## 5. The proposed shape

The aim is the smallest change that is still correct. Everything below keeps the dotted string, the role
row, the one role per membership, and the single voter.

**A permission string keeps its grammar.** Where ownership matters, a module declares the pair as two
strings whose last segment ends `_own` and `_any` — `service.order.read_own` / `read_any`,
`service.order.write_own` / `write_any`, `service.order.transfer_own` / `transfer_any`. No change to
`Permission.php`: the current regex already accepts them. A module declares the *pairing* in its manifest,
so the role editor of row 53 can render a pair as one three-state row (none / own / any) rather than two
tick boxes a person can set incoherently.

**A role row is unchanged.** `role(id, company_id, name, permissions jsonb, created_at)`, exact match plus
the wildcard. Custom roles tick the paired strings like any other. `SeedPlatform::BUILT_IN_ROLES` gains the
`_any` strings for `owner` implicitly through `*`, and for `admin` explicitly.

**A membership gains a scope, not a second role.** A join table
`membership_establishment(membership_id, establishment_id)` with a foreign key and `onDelete: CASCADE`, plus
a boolean `all_establishments` on `membership` so that "every establishment" and "none left" are different
stored states. The owner role is never scoped. One role per membership stands, and the limit it imposes on
a chain (§ 4.1) is recorded rather than worked around.

**The scope needs a home in the request.** A `CurrentEstablishment` beside `CurrentCompany`, resolved from
the membership's list and from what the request acts on; and an `EstablishmentScoped` marker beside
`CompanyOwned`, with a second Doctrine `SQLFilter` mirroring `CompanyFilter` — switched on by the same guard
that switches the company filter on, off by the same lifter. Only entities that carry an establishment are
marked, and an architecture test names them, in the same shape as the existing `CompanyColumnTest`.

**The voter grows two branches and no new mechanism.** `PermissionVoter` keeps deciding every well-formed
permission string. It gains: a subject implementing an ownership marker answers an `_own` check by comparing
the owning membership; an `_any` string held by the role satisfies an `_own` check; and a subject
implementing an establishment marker is refused when the membership's scope excludes it. A request passing
no subject behaves exactly as it does today. This is the shape Symfony documents.

**`Me` answers what the SPA needs to render.** It keeps the flat `permissions` list, and `_any` is expanded
into both strings there, so `auth-facade.ts` needs no change. It gains the establishments the membership is
scoped to, and a flag for "all", so the shell can offer an establishment switcher the way it offers a
company switcher.

**A module declares an authorising act explicitly.** `ModuleManifest` gains one list beside `dependencies`
and `permissions`: the acts that may be performed under another member's authorisation, named by the
permission string that governs them — `register.sale.void`, `register.sale.refund`,
`register.discount.apply_any`, `register.shift.correct`. An explicit list, not a naming convention: a
convention hides the rule inside a string and cannot be read by the role editor. A member whose role lacks
the string may perform the act with an authorisation from a member whose role holds it.

**A threshold is the same pair shape, and this is why it must be.** The rule above turns on *lacking* the
string, so a cashier who holds `register.discount.apply` and enters thirty per cent against a limit of
twenty would never trip it — they hold the string. The discount permission is therefore a pair like the
ownership ones: `register.discount.apply`, bounded by
`register.discount.max_unauthorised_percent` on the company settings chain, and
`register.discount.apply_any`, unbounded; the authorisable act is `apply_any`, so a cashier exceeding the
limit is asking for a string their role does not hold and the ordinary rule applies unchanged. *Own against
any* and *bounded against unbounded* are one mechanism, which is the argument for declaring pairs in the
manifest rather than scattering special cases.

**The authorisation is synchronous first.** The manager authorises on the same screen; the request carries
both identities; the use case writes one audit row. A separate `authorisation` table is needed only when
approval becomes remote or asynchronous, and should wait for that. **The credential is the authoriser's own
second factor** — TOTP or a passkey, both of which exist (`TotpCodes`, `PasskeyCeremonies`) — with the
constraint that a role holding an authorisable act requires its members to have enrolled one. A password
typed on a cashier's screen is the thing to avoid. A short per-member register PIN is what the market uses
and would be a deliberate addition if speed at the till demands it; it must be per member, never per till.
[The credential choice is Speculative; that it must be individual is Verified from § 2.1.]

**The audit log gains one column, not a key in a JSON blob.** `audit_log.authorised_by_user_id`, nullable.
"Who authorised this void" is exactly the question an inspector asks, and a column is queryable where a
JSON key is a scan. The action names the act; `changes` carries the reason and, where one applies, the
amount and the threshold that was exceeded.

**And separately from all of the above**, the Register module needs its own sales journal, append-only,
chained or signed, in which a void is a counter-entry and the original line is never touched, with daily,
monthly and annual closures producing cumulative data that survives a purge. That is not authorisation
work. It is the larger requirement, and § 4.4 is where it is argued.

---

## 6. Sources

**Standards and papers.** NIST RBAC project, `https://csrc.nist.gov/projects/role-based-access-control`;
the RBAC reference model,
`https://csrc.nist.gov/csrc/media/projects/role-based-access-control/documents/rbac-std-draft.pdf`;
Ferraiolo and Kuhn 1992,
`https://csrc.nist.gov/CSRC/media/Projects/Role-Based-Access-Control/documents/ferraiolo-kuhn-92.pdf`;
NIST SP 800-162, `https://nvlpubs.nist.gov/nistpubs/specialpublications/nist.sp.800-162.pdf`;
Zanzibar, `https://www.usenix.org/system/files/atc19-pang.pdf`.

**Vendor and project documentation.** OpenFGA: `https://openfga.dev/docs/modeling`,
`/modeling/parent-child`, `/modeling/conditions`, `/modeling/token-claims-contextual-tuples`,
`/docs/authorization-concepts`. AuthZed/SpiceDB: `https://authzed.com/docs/spicedb/concepts/consistency`,
`/concepts/caveats`, `/modeling/developing-a-schema`. Casbin:
`https://casbin.apache.org/docs/rbac-with-domains`, `/docs/abac`. Symfony:
`https://symfony.com/doc/current/security/voters.html`,
`https://symfony.com/doc/current/security/access_control.html`.

**Point-of-sale help centres.** Toast: `https://doc.toasttab.com/doc/platformguide/adminVoidingOrders.html`,
`/platformDiscountsOverview.html`, `/adminEditingEmployeeInformationJobsAndPermissionsInEnterprises.html`,
and `https://support.toasttab.com/en/article/` for Access-Permissions-Reference,
Find-or-Edit-an-Employee-s-POS-Access-Code, Assign-a-Swipe-Card-to-an-Employee-Manager,
Exceptions-Report-Overview, View-Update-History-Feature1, Shift-Review-Overview, New-POS-Managing-Tables,
Transferring-a-Check-to-Another-Employee-1493069784457. Shopify:
`https://help.shopify.com/en/manual/your-account/users/roles/permissions/pos-permissions`,
`/manual/sell-in-person/shopify-pos/staff-management/pos-activity-log`, `/manage-staff`. Square:
`https://squareup.com/help/us/en/article/` 8357, 8356, 8166, 5337, 3955. Lightspeed:
`https://k-series-support.lightspeedhq.com/hc/en-us/articles/` 4403189318043, 360050328494, 23973504428059;
`https://resto-support.lightspeedhq.com/hc/en-us/articles/` 115002299368, 1260802923390. Loyverse:
`https://help.loyverse.com/help/how-manage-access-rights-employees`. Odoo:
`https://www.odoo.com/documentation/19.0/applications/sales/point_of_sale/extra/employee_login.html`,
`/17.0/applications/sales/point_of_sale/restaurant/floors_tables.html`;
`https://apps.odoo.com/apps/modules/19.0/pos_manager_approval` (a third-party listing, cited as evidence of
demand only). Clover: `https://www.clover.com/en-US/help/manage-roles-and-access-permissions`.

**Legal, official.** `https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000051764897`;
`https://www.impots.gouv.fr/professionnel/questions/quel-est-le-champ-dapplication-de-lobligation-de-detenir-un-logiciel-de`;
BOI-TVA-DECLA-30-10-30, versions 20251001 and 20260325, at
`https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20260325`;
BOI-LETTRE-000242 at `https://bofip.impots.gouv.fr/bofip/10692-PGP.html/identifiant=BOI-LETTRE-000242-20260325`;
`https://www.gesetze-im-internet.de/ao_1977/__146a.html`;
`https://www.bzst.de/DE/Unternehmen/Aussenpruefungen/DigitaleSchnittstelleFinV/digitaleschnittstellefinv_node.html`;
`https://info.portaldasfinancas.gov.pt/pt/informacao_fiscal/legislacao/diplomas_legislativos/Documents/Portaria_363_2010.pdf`.

**Legal, standards body.** `https://www.infocert.org/certifications/nf525/`. The LNE page did not resolve.
Avoid `nf525.com`: that domain currently serves unrelated spam.

**Legal, press (Tunisia).** `https://www.tunisienumerique.com/services-de-consommation-sur-place-la-mise-en-place-des-caisses-enregistreuses-sera-effective-a-partir-de-ces-delais/`;
`https://managers.tn/2026/06/30/tunisie-caisses-enregistreuses-obligatoires-des-demain-pour-ces-structures/`;
`https://www.leconomistemaghrebin.com/2026/05/21/caisses-enregistreuses-obligatoires-juillet-2026/`.

**Legal, vendor (Tunisia and France).** `https://caisses.tn/caisses-obligatoires-en-tunisie/` (the 2027 and
2028 phases and the real-time transmission claim rest on this alone);
`https://www.legifiscal.fr/impots-entreprises/lutte-contre-fraude-fiscale/controle-fiscal/logiciels-caisse-securises.html`
(the €7,500 penalty figure).

---

## 7. What could not be read

Listed so that no claim above is mistaken for one it does not rest on.

- **ANSI/INCITS 359-2012 itself.** It is a paywalled standard. Everything in § 1.1 comes from the
  NIST-published reference model that INCITS 359 was drawn from, which NIST presents as the standard's
  content but which is not formally identical to the published ANSI text.
- **The NF525 référentiel.** A paid document. § 3.1 reports only what Infocert publishes openly; the
  journal of events, the sequential numbering, the chaining specifics, duplicate-ticket handling and
  operator identification under NF525 are **not** established here, and the vendor pages describing them are
  plausible and uncorroborated.
- **JORT n° 125 du 14 octobre 2025 and décret 2019-1126.** Sought at `iort.gov.tn`, `legislation.tn`,
  `legislation-securite.tn` and `finances.gov.tn`; none returned usable text. The whole of § 3.2 —
  scope, dates, the recorded-operations list, homologation, penalties — is press and vendor reporting. The
  1 July 2026 date is corroborated across outlets and by no text that was read.
- **KassenSichV § 2.** Did not resolve on two URLs. Whether German law *obliges* `BEDIENER_ID` to be
  populated, as opposed to defining it in the export format, is therefore unestablished.
- **The OECD's 2013 *Electronic Sales Suppression*** (403 on four URLs), **Austria's RKSV** (BGBl. II
  410/2015, empty responses) and **Italy's registratore telematico specifications** (404). Because of these
  three, § 3.3 claims the preserve-and-identify pattern is *evidenced across three jurisdictions*, not
  *near-universal*.
- **What article 87 of loi n° 2026-534 du 25 juin 2026 changed** in article 286 CGI. The Légifrance page
  records the amendment; the amending text was not read.
- **Clover's help centre**, whose pages are client-rendered and returned no body; only its role model and
  passcode length are cited. The frequently-quoted "manager passcode" sentence traces to a community forum,
  not to Clover's documentation.
- **TouchBistro's and Revel's help sites**, which would not render; those two rows of the table in § 2.1
  rest largely on search-index descriptions of specific article URLs, plus one TouchBistro release-notes PDF
  that was fetched in full.
- **Any francophone or Tunisian point-of-sale product's permission documentation.** `aide.laddition.com`
  does not resolve, `support.zelty.fr` returns 403, Hiboutik's help path 404s, Zelty's only public help
  source is a code repository and was skipped under the licensing invariant, and Dolibarr's TakePOS wiki
  covers installation and hardware only. § 2 is therefore an Anglophone sample.
- **Square's override behaviour** is an absence found across roughly twenty-five fetched pages, not an
  exhaustive sweep of its help centre.
