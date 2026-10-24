# Starting twes-in

How to bring the project up, sign in, create every kind of user, get data into it, start clean, and run the checks.
Every command runs from the repository root unless it says `cd`. For version bumps, see `docs/UPDATE.md`.

## 0. What `make up` gives you

| Service      | Address on your machine                     | What it is                                                     |
|--------------|---------------------------------------------|----------------------------------------------------------------|
| `web`        | http://localhost:8090                       | the application (nginx serving the Angular build, proxying `/api`) |
| `api`        | http://localhost:8091/api                   | the Symfony API directly (FrankenPHP)                          |
| `mailpit`    | http://localhost:8092 (SMTP on 8093)        | **every mail the application sends lands here**, none leaves   |
| `postgres`   | `localhost:5433`, user `twes`, password `twes`, databases `twes` and `twes_test` | PostgreSQL                        |
| `gotenberg`  | `localhost:8094`                            | renders PDFs                                                   |
| `centrifugo` | not published (reached through `web`)       | realtime updates between tabs, and a phone lent as a scanner   |
| `worker`     | not published                               | the work a request leaves for later (the forgotten-password, signup and invitation mails), read from the `messenger_messages` table |
| `demo-worker` | not published                              | builds the demo sandboxes, on a queue of its own, so no mail waits behind a build |
| `lan`        | https://<this machine's address>:8443 (certificate root on http://…:8095/root.crt) | a phone's HTTPS door to `web`; started by `make up` only |

Ports come from `.env`. To change one, set it in your shell (`WEB_PORT=9090 make up`) or in a `.env.local` next to
`.env`. Only `web`, `api` and `lan` listen on every interface. The rest listen on `127.0.0.1` only.

**A phone as a scanner** (the phone button in the top bar) works with the computer on `localhost`. `make up` finds
this machine's address on the network (the source of its default route; `LAN_HOST=192.168.1.20 make up` to choose
another) and starts `lan`, a Caddy proxy serving the application there over HTTPS on port 8443. The QR code then
names that address, and Centrifugo accepts it. Phone and computer must be on the same network. The certificate comes
from Caddy's own local authority, so the first time the phone either:
- accepts the browser's warning for `https://<address>:8443`, which is enough on Android Chrome; or
- installs the root from `http://<address>:8095/root.crt` as a trusted certificate. On iOS, install the profile, then
  turn on full trust under Settings > General > About > Certificate Trust Settings.

The root is kept in the `lan-data` volume, so the phone trusts it until `make reset`. Without a route (offline), or
under a plain `docker compose up`, `lan` stays off and the QR code names the computer's own address.

**The whole application from a phone.** The same door serves everything, not only the scanner: open the address
`make up` prints at the end (`From a phone on this network: https://<address>:8443`) and sign in as on the computer,
with the authenticator code. While `lan` is on, that address is also the application's own (`DEFAULT_URI`), so a
mailed invitation or signup link opens on the phone as on the computer. Passkeys work on the computer only: a passkey
belongs to a host name, and the phone reaches this machine by its address.

**The API documentation is at <http://localhost:8090/api/docs>** (or `:8091/api/docs` on the API directly). `/api`
on its own is the entrypoint, not the documentation, and answers 401 without a session. Development only: production
sets `enable_docs: false`, and the contract ships inside the generated TypeScript client instead.

**Browse `http://localhost:8090`, not `127.0.0.1`**: passkeys are bound to `localhost` (`APP_WEBAUTHN_RP_ID`), and
the links in the mails point to `localhost`.

## 1. What to install

**On the host: Docker with Compose v2, `make`, `bash` and `git`.** Nothing else. No PHP, Composer or Node is installed
on the host and none is used: every test, gate, console command and the browser tests run in containers, as your own
uid, with the working tree mounted at its own path (`compose.yaml`, services `tools` and `web-tools`, profile `tools`).
The Makefile is the one entry point:

| You want | Run | Where it runs |
|---|---|---|
| the whole stack | `make up` | the stack's own services |
| every gate | `make gate` (= `gate-licences`, `gate-api`, `gate-web`) | `tools` (PHP 8.5, the gates' own tools, the Docker CLI) and `web-tools` (Node 26, Playwright's system libraries) |
| one API suite | `make test-api` (starts postgres first; `composer test`: the test database migrated, then the suite on paratest, a copy of it and a cache directory a worker, a worker a processor or `PARATEST_PROCESSES`), or `make tools CMD='cd api && vendor/bin/phpunit tests/Unit'` for one part on one process (start postgres first: `docker compose up -d --wait postgres`) | `tools` |
| the web unit tests | `make test-web` | `web-tools` |
| the browser tests | `make e2e` (`E2E_ARGS=--shard=1/3` passes options), `make gallery` | `web-tools`, on the host's network so `127.0.0.1:8090` is the stack's published port |
| the OpenAPI document, the types, the notices, the pins | `make api-openapi`, `make api-types`, `make notices`, `make versions` | `tools`, `web-tools` |
| a PHP syntax check | `make php-lint FILE=<path>` | `tools` |

The first run of each builds its image (`make tools-image`, `make web-tools-image`; both are no-ops when nothing
changed) and installs the dependencies into the working tree's own `api/vendor` and `web/node_modules`, which are
gitignored; Chromium and WebKit (Safari's engine, for the scenarios tagged `@phone`) are downloaded once into `var/cache/ms-playwright`. `make` hands the services your uid, the
checkout's path, the Docker socket's group and a temporary directory outside the tree (`/tmp/twes-in-tools-<uid>`:
a gate that builds a fixture in a directory git ignores would see nothing in it). A bare `docker compose --profile tools run …` falls back to
uid 1000 and the current directory but not to a temporary directory the Makefile made: use `make`.

The Docker-driving gates (`compose-log-rotation`, `forwarded-proto`, `access-log`, `live-proxy`) start containers through the host's
socket, which is why the tree is mounted at its own path: a path the gate gives the daemon is one the daemon sees.
The repository's git directory is mounted beside it (`HOST_GIT`): in a linked worktree (the snapshot gate's, § 7, or a
writer's) `.git` is a file naming a directory outside the tree, and every gate asking git what is tracked or ignored
would fail there.

## 2. Bring it up

```sh
make up
```

This does three things, in order:

1. **Builds the images and starts every service** (`docker compose up -d --build --wait`), live (below). It waits
   until each one is healthy: the first time, the web tier installs its dependencies before it serves. The first build downloads and compiles everything (see § 9 for a measured time). Later builds
   reuse Docker's cache.
2. **Migrates the database.** The one-shot `migrate` service (`infra/api/migrate.sh`) runs on every `make up`, before
   `api` and `worker` start: it applies pending migrations all or nothing as the schema's owner, then gives the
   application's role, `twes_app`, its password (`POSTGRES_APP_PASSWORD`) and its grants
   (`app:database:app-role`): rows only, the journal added to and never rewritten, no schema. `api` and `worker` wait
   for it to succeed and connect as that role; only `migrate` is handed the owner's address
   (`DATABASE_MIGRATION_URL`). A failed migration leaves `api` unstarted instead of serving a half-migrated schema.
   You never run migrations by hand; `make migrate` runs the service again. Under `make up` a migration written while the
   stack runs is applied by `migrate-watch` (`infra/api/migrate-watch.sh`, `compose.live.yaml`) within seconds, through
   the same script, so the live api never serves code ahead of its database; `docker compose logs migrate-watch` says
   what it applied or why it failed. Once `api` starts
   (`infra/api/docker-entrypoint.sh`), it tells the companies that asked « Me prévenir » for a module this release brings
   (`app:modules:announce-arrivals`); each is told once, so a restart tells nobody again. It then chains the issued
   invoices and credit notes no hash chain holds yet, such as those issued before the chain existed, in their issue
   order (`app:invoices:seal-chain`); a restart chains nothing twice. `make tools CMD='cd api && bin/console
   app:invoices:verify-chain'` (or `--company=<id>`) walks every chain and names each place one does not hold.
   Every night at `app.data_check.at` (02:30, server time) the worker runs the data checks (`app:data:check`, below
   in § 9): the chains to their recorded ends, the issued documents' sums, numbers without gaps and rows pointing into
   another company.
3. **Seeds** (`make seed`, see § 4). This step is idempotent: running it again creates nothing new.

`make down` stops everything and **keeps** the data. `make up` again brings it back as it was. `make logs` follows
every service's output. `docker compose logs -f api` follows one.

**`make up` is live: an edit shows without a rebuild or a restart.** `compose.live.yaml`, which the Makefile adds to
`compose.yaml`, mounts `api/` and `web/` from your working tree into the containers:

- **The API** runs in FrankenPHP's worker mode as in the images, and FrankenPHP's watcher restarts its workers when a
  file under `src/`, `config/`, `templates/`, `translations/` or an `.env` file changes. The next request runs the
  edited code; with Symfony's dev container to rebuild first, that took 6 to 18 seconds on this machine under heavy
  load. `vendor/` and `var/` are volumes of the container's own (`api-vendor`, `api-var`), never your `api/vendor` or
  `api/var`: it runs `composer install` at every start, so a lock change arrives with a restart, and its autoloader
  finds a class the moment you write it. OPcache stays on and checks every file's time (`infra/api/conf.d/30-app.live.ini`).
- **The web tier** is the Angular dev server (`ng serve`, the `live` stage of `infra/web/Dockerfile`), which rebuilds
  what changed and updates the open page. Through the phone door too: 3 seconds from saving a template to the phone's
  page showing it. `node_modules` and the build cache are volumes of their own, installed inside the container (Alpine,
  not your system's libc), again when `web/package-lock.json` changes. The dev server proxies the API and Centrifugo on
  the same paths nginx does (`web/proxy.live.json`; `infra/web/tests/live-proxy.test.sh` checks they stay the same).
  At each start it regenerates `web/src/app/api` from the contract the API serves.
- **A resource property added or removed** may not reach the API's answers and its OpenAPI document: API Platform
  keeps resource metadata in cache pools that neither a worker restart nor Symfony's dev rebuild reliably empties.
  `make live-refresh` empties them, restarts the workers and regenerates the web tier's types.
- **What live development does not run**: nginx. Its security headers (the nonce CSP, `X-Frame-Options`, `nosniff`),
  its cache headers and its compression exist only in the images, and the production bundle is only built there.
  `make up-images` brings up the same stack from the built images, exactly as CI runs it; the next `make up` switches
  back to live. The other targets work on whichever is running.

**The images are built from your working tree, not from the last commit.** Under `make up-images`, uncommitted and
untracked files under `api/` and `web/` go into the build (`COPY api/ ./`), and a web or API change is visible only
after `docker compose up -d --build web` (or `api`). Half-finished code that does not compile fails that build, usually
at the api image's `cache:clear` step. To bring up the committed code while work is in progress, use a separate
checkout: `git worktree add ../twes-in-clean HEAD`, then run `make up-images` there (with § 8's variables if your own
stack is up).

**The api runs its development image.** `infra/api/Dockerfile` has two targets: both `make up` and `make up-images`
build `dev` (Symfony's dev mode, the profiler, `php.ini-development`, the dev packages). The production image is § 10.

## 3. Sign in as the platform operator

| Field    | Value                                              |
|----------|----------------------------------------------------|
| Address  | `operator@twes.local`                              |
| Password | `twes-operator-dev`                                |
| Code     | the 6 digits printed by `make operator-code`       |

Open http://localhost:8090, enter the address and password, then the code on the next screen. Operators always
carry a second factor. The seed enrolled a **known development secret** (`JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`) so
that scripts can compute codes. You can also add it to any authenticator app to get codes on your phone.

- A code is valid for about 30 seconds, and **an expired code spends the budget exactly like a wrong one**:
  `make operator-code` therefore waits for a fresh window rather than handing you a code with four seconds left, and
  prints how long the one it gives you lives. **Five wrong, reused or expired codes in five minutes lock the second
  step** for the rest of those five minutes — after which a perfectly correct code still fails, which is what the
  lockout feels like from the login screen. Playwright spends the same budget, so do not sign in by hand while
  `make e2e` runs, and if you are locked out, wait five minutes rather than trying again.
- A code from a phone authenticator only works if you added **this** secret to it; a secret enrolled any other way
  is not the one the seed stored.
- `make seed` makes a **second operator**, `codes@twes.local`, with the same password and secret, also an owner of
  Demo. The browser scenarios that sign in with a code use it, because the first one's budget of five also pays for
  every proof of who is at the screen that the scenarios sharing its session give. Sign in by hand as either; locking
  one out leaves the other free.
- The operator is also the **owner of the Demo company**, so after signing in you land in Demo's home page — unless
  you have run `make fixtures`, which makes the operator a member of Demo, Carthage Conseil and Atelier Mercier.
  A sign-in picks a working company only when there is exactly one, so with the fixtures loaded you land on a
  company choice instead. Pick **Demo**; that is the state this file describes. Its
  **Manage the platform** link (*Gérer la plateforme*) opens **Platform** (http://localhost:8090/platform), where the
  operator runs the platform.
- Being Demo's owner is development data only: an operator keeps no standing access to a company. A company's owner
  lets support in from **Settings → Support access** (*Paramètres → Accès du support*) for 1, 24 or 72 hours; the
  company then appears in the operator's company switcher marked **Support**, read-only (no write, no export, no cost
  price), under a banner saying until when, and every page read is in its activity log. To try it, use a company
  the operator is not a member of, such as one opened from **Platform** for a new owner.

**The interface opens in French**, Demo's language. The **FR** button at the top right switches to English. This
file quotes the English labels. Their French counterparts:

| English | Français |
|---|---|
| Platform · Manage the platform | Plateforme · Gérer la plateforme |
| Companies · Open the company · Invite an owner | Entreprises · Ouvrir l'entreprise · Inviter un propriétaire |
| Signup · Anyone may sign up · A company that signs up waits for approval | Inscriptions · Tout le monde peut s'inscrire · Une entreprise inscrite attend l'approbation |
| Waiting for approval · Approve · Reject | En attente d'approbation · Approuver · Refuser |
| Accounts · Deactivate · End sessions | Comptes · Désactiver · Terminer les sessions |
| Members · Add · Invitation pending | Membres · Ajouter · Invitation en attente |
| Security · Require two-step verification | Sécurité · Exiger la vérification en deux étapes |
| Sign out (account menu, top right) | Se déconnecter |

## 4. Data: what exists after `make up`, and fixtures

`make seed` runs `bin/console app:seed` inside the `api` container. It creates exactly this, and nothing else:

- the three built-in **roles** and their permissions (`owner`: everything; `admin`: company settings, members,
  and read and write on every module; `member`: day-to-day work, read-only on products, stock, vendors and expenses).
  The authoritative list is `SeedPlatform::BUILT_IN_ROLES` in `api/src/Tenancy/Application/Seed/SeedPlatform.php`.
- the **operator** of § 3, with its authenticator;
- the **Demo** company (Tunisia, TND, French, `Africa/Tunis`), `active`, with the operator as its owner;
- for every company: its **taxes, units, establishments and numbering series** from its country's fiscal preset
  (`api/config/fiscal/<CC>.yaml`), and the customer tax regimes of every preset.

On an empty database it prints exactly that:

```
[OK] Created: role owner, role admin, role member, operator operator@twes.local, authenticator for
     operator@twes.local, company Demo, membership operator@twes.local owns Demo, customer tax regimes of FR,
     customer tax regimes of TN, tax components of Demo, units of Demo, establishments of Demo, numbering series of Demo.
```

At every start the api also brings the database up to the release (`app:platform:converge`): the built-in roles'
permissions, and into each company what its fiscal preset has and the company lacks, such as the numbering series of a
kind of document a release added. It is the seed without its operator and first company; what exists is kept.

Apart from the seed, the api writes the **legal pages' shipped drafts** at every start
(`app:legal:seed-drafts`, from `api/resources/legal/<page>.<language>.md`), only where a page has no version yet in that
language, so what an operator wrote is never replaced. The operator edits and validates them at `/platform/legal`
(« Gérer les pages légales » on the platform page); anyone reads them at `/legal/<page>`. The publisher's and host's
facts the texts name are filled in at the top of that screen; once its security contact is,
`/.well-known/security.txt` (RFC 9116) answers with it, and until then it answers 404.

The seed loads no business rows. **`make fixtures`** adds two demo companies, which the operator owns:

| Company | Country, currency | What it holds |
|---|---|---|
| Carthage Conseil | Tunisia, TND, `Africa/Tunis` | 30 customers (2 deactivated, 2 abroad, 6 key accounts subject to the 1 % withholding), 30 products and services, stock, 8 vendors |
| Atelier Mercier | France, EUR, `Europe/Paris` | the same shape: a cabinetmaker's workshop, with EU and non-EU customers |

Each company has five months of activity, ending a few days before the load. This includes:

- 29 issued invoices, one of them drafted from a delivery note: paid, part paid, overdue, credited in full, and not
  yet due. There are also two drafts and a cancelled draft.
- Six delivery notes, one in each state, including one invoiced.
- Stock received and moved by those deliveries.
- Sixteen expenses: drafts, recorded and paid.

Dates are relative to the day you load, and nothing else varies: every load writes the same rows. The dataset is
`api/src/DataFixtures/` (DoctrineFixturesBundle, dev and test only). Every row is written through the application's
use cases, so numbers, totals, PDFs and the audit trail are what the product itself would have made. A load is
therefore also a smoke test of those workflows.

- Run it after `make seed`: the companies belong to `operator@twes.local`, and without it the load refuses.
- It only appends. A company that already exists is left as it is, so a second `make fixtures` changes nothing.
  To reload, start clean (§ 6).
- Never run `bin/console doctrine:fixtures:load` without `--append`: that form **empties the whole database** first,
  including the operator and Demo.

Demo stays empty of business rows; the e2e suite writes into it.

### A large company: `make scale-data`

To know the product holds for years of use, **`make scale-data SIZE=100k`** (`1m`, `5m`, `10m`) grows Carthage Conseil
to that many invoice rows in **its own database, `twes_scale`**: the development data and the tests are never touched,
and the command refuses any database not named `*_scale` or `*_test`. It clones the company's invoice graph in SQL,
spread over ten years: invoices with their lines, taxes and payments, credit notes, and the customers they name, each
customer set with fake names from a fixed seed. Every clone is a state the application can produce, and its numbers
follow its dates through the real numbering series, so the next real issue continues after them.

- **Resumable.** It works in chunks and keeps its progress in the `scale` schema of that database (never a migration).
  If a run stops, run it again; a run with nothing to add adds nothing; raising `SIZE` adds copies and renumbers.
  A different seed or span needs a fresh database.
- **Not grown yet:** expenses, stock movements, audit rows, delivery notes, contacts and stored files keep their base
  rows. A clone has no stored PDF: it is rendered on first request, as an issued document without one already is.
- **Older unpaid invoices stay unpaid**, so the open-invoice figures grow with the size; read the home summary at scale
  with that in mind.
- **It ends with `VACUUM (ANALYZE)`**: the planner's statistics are what a measurement reads, and the first page of the invoices
  read 3 to 4 s at 100k without them and 0.8 s with them.
- **It runs without debug** (`--no-debug`): in debug Symfony keeps every query of the run in memory, and the chaining
  of 90,000 renumbered documents ran out of the console's 256 MB at 100k.
- **It runs the data checks once it is done** (`app:data:check --quietly`) and fails on any finding: generated data
  no use case could make would measure nothing true. Each check's time is printed.
- Not wired to `make up` yet: point `DATABASE_URL` at `twes_scale` to look at it.

### One account per role

Each demo company also gets **one member of each built-in role**, so what a role may not do is something you can
sign in and meet rather than read about. All five share one password, and each holds the same role in both
companies:

| Role | Address | Password | What it may not do |
|---|---|---|---|
| `owner` | `owner@twes.local` | `twes-role-test-2026` | Nothing inside the company — it holds the wildcard `*`. It is never a platform operator. |
| `admin` | `admin@twes.local` | `twes-role-test-2026` | Grant the owner role, or remove an owner or another admin. |
| `member` | `member@twes.local` | `twes-role-test-2026` | Issue an invoice, record a payment, validate a delivery note, or see the members. Read-only on products, stock, vendors and expenses. |
| `clerk` (« Caissier / Vendeur ») | `clerk@twes.local` | `twes-role-test-2026` | Draft or issue a credit note, validate a delivery note, create or edit a customer, see a product's cost, or touch settings, members and roles. It issues invoices, records their payments and drafts delivery notes. |
| `accountant` (« Comptable », read-only) | `accountant@twes.local` | `twes-role-test-2026` | Write anything: no document, payment, expense, customer, setting or closing. It reads the invoices, credit notes, delivery notes, quotes, expenses, customers, vendors and the fiscal setup, and downloads « Export comptable »'s journals. No product, stock, member or role. |

They are **invited and accepted through the product's own use cases**, not written into the database, so each has
the membership, the audit rows and the password checks any real member gets — and the passwords pass the breach
check, which refuses an ordinary word. One browser holds one session, so use a private window or a second profile
to be two of them at once.

### Roles of your own

The three above ship with the product and are the same in every company: the gear's **Équipe → Rôles** screen shows
them, and their boxes are readable but locked. A company adds its own beside them — a `barista`, a `cashier` — by
naming one and ticking what it may do in a matrix grouped by module. The catalogue of permissions is collected from
the modules themselves, so a module added later brings its own heading with it and nothing has to be listed by hand.

Once a role exists it is offered wherever a role is chosen — the **Membres** screen lists it beside the three when
you invite somebody, so a `barista` can actually be one.

Two refusals are deliberate rather than missing. A role somebody still holds **cannot be deleted**: the screen names
who holds it — members, and anyone invited at it who has not joined yet — and asks you to move them first, because
deleting it quietly would change what those people may do and nobody would notice until it bit. And a custom role sits **below `member`** in the order that decides who may grant
and remove whom, so it never grants a role to anyone, whatever it is ticked for; that is fail-closed on purpose and
"a manager who may hire" is its own decision, not yet taken.

To see a refusal rather than read about it: sign in as `member@twes.local`, open any invoice and look for
**Émettre** — it is not there — then sign in as `admin@twes.local` and it is.

These are development accounts on a development dataset; `make fixtures` runs in `dev` and `test` only.

Two things to know about Demo on a stack that already ran tests:

- **`make e2e` leaves rows in Demo** (customers, products, documents with generated names). They are harmless. If
  you want Demo empty again, start clean (§ 6).
- If members are suddenly asked to set up two-step verification, Demo's **"Require two-step verification"** switch is
  on (company settings → Security). The seed never turns it on. Check it with
  `docker compose exec -T postgres psql -U twes -d twes -c "SELECT mfa_required FROM company WHERE name='Demo'"`.

## 5. Create users

Every mail below lands in Mailpit, http://localhost:8092. Open the mail and follow its link. Passwords must be at
least 12 characters and are checked against known data breaches, so an ordinary word like `password1234` is refused.

### 5.1 A company and its owner (the operator invites them)

1. Sign in as the operator (§ 3) and open **Platform** (http://localhost:8090/platform).
2. Under **Companies**, type the company name, choose its country (Tunisia or France), then **Open the company**.
   It is created `pending` (shown as "Waiting").
3. On that company's row, enter the owner's address and choose **Invite an owner**.
4. In Mailpit, open the invitation and follow the link (`/invitations/<token>`). Enter a name and a password.
5. The company becomes `active` once its first owner accepts. The owner signs in at http://localhost:8090.

An address that already has an account is invited the same way. Accepting adds the company to that account.

### 5.2 A member of an existing company (an owner or admin invites them)

1. Sign in as an owner or admin of the company. Demo's owner is the operator.
2. Open the gear (settings) → **Members** (http://localhost:8090/members). The list fills a moment after the
   page opens.
3. Enter the address, choose the role (`owner`, `admin` or `member`), then **Add**. The row shows "Invitation pending".
4. The invited person follows the mail's link in Mailpit, as in § 5.1 step 4, then signs in with that address and
   password. Accepting does not sign anyone in: if you accept in a browser that is already signed in as someone
   else, sign out first (account menu → Sign out) to see the new member's view.

### 5.3 Anyone, through public signup

Signup is **off** by default.

1. As the operator, open **Platform** → **Signup** and switch on **Anyone may sign up**. **A company that signs up
   waits for approval** is on by default. Leave it on to try the approval flow.
2. Signed out, open http://localhost:8090/signup, enter an address, then **Send me a link** (*Recevoir le lien*).
3. In Mailpit, follow the link (`/signup/<token>`, valid 24 hours). Fill in your name, a password, the company
   name, its country and time zone.
4. With approval on, the company waits: its owner can sign in and sees that it is waiting. As the operator, go to
   **Platform** → **Waiting for approval** → **Approve** (the owner is mailed) or **Reject** (the company is suspended).

### 5.4 Try the demo, and switch it

The demo is **on** by default, a shop's own installation included. Signed out, http://localhost:8090/login offers
**Essayer la démo** with one button per country (Tunisia, France): a click signs you in, with no account, as the owner of
a company of your own, written with months of history. Every page says it is a demo; every PDF carries the band
« DÉMO — sans valeur fiscale »; a mail it would send is kept under **Courriers de la démo** (the banner's link), never
sent. The sandbox goes when you sign out, or after two hours away (`app.demo.idle_ttl` in `api/config/services.yaml`),
within the five minutes the worker's tend takes.

The `demo-worker` keeps one sandbox ready per country. On a fresh stack the first is built about five minutes after the
worker starts, or as soon as someone tries: until then a click answers *La démo se prépare : réessayez dans un
instant*. `docker compose logs demo-worker` shows the builds.

As the operator, **Platform** → **Démo** switches it off (the login page stops offering it, and a visitor still inside
keeps their sandbox until they leave) and sets how many sandboxes may exist at once (3 to 100, 10 by default): each is a
whole company, so the count bounds what visitors cost the machine. Operators' company and account lists, and the data
checks, leave sandboxes out.

### 5.5 Another platform operator

Operators are created from the console only, never from the application:

```sh
docker compose exec -T api bin/console app:seed \
  --operator-email=you@example.com --operator-password-stdin
```

The command reads the password from the first line of its standard input, so it stays out of the shell's history and
of `ps`. This creates the operator and makes them an owner of Demo (`--company-name=<name>` for another company, which
is created if missing; `--without-company` for none). Without `--operator-totp-secret`, the new operator enrols their
own authenticator at first sign-in. That is how a real deployment's operator is created: a production kernel refuses
`--operator-password` and `--operator-totp-secret` outright, since a known secret is a known second factor. Both
remain for the development and CI stacks (`make seed`).

### 5.6 Deactivate an account, end sessions

**Platform** → **Accounts**: search an address, then **Deactivate** (or **Reactivate**) or **End sessions**. An
operator cannot deactivate their own account. Removing someone from one company only is done on that company's
**Members** page.

### 5.7 Two-step verification for a company's members

Company settings → **Security** → **Require two-step verification** turns it on for everyone in that company. Each member
then enrols an authenticator app or a passkey at their next sign-in.

### 5.8 A lost authenticator

A person who is not required to keep a second factor signs in with a recovery code, then **Mon compte** → **Sécurité**
removes the app and sets a new one up. A required account (every operator, and every member of a company that requires
it) cannot remove its last factor: with a passkey it signs in with that and replaces the app; with neither a passkey nor
a recovery code left, whoever runs the installation replaces its factors from the server with `bin/console
app:mfa:replace <e-mail>` (docs/RUNBOOK.md « Replace a lost authenticator »). No operator can reset another's factors.

## 6. Start clean

```sh
make reset
```

It asks you to type `yes`, then:

1. `docker compose down --volumes --remove-orphans`, which **deletes the database** (`twes` and `twes_test`) and
   the **stored files** (issued PDFs, attachments): the volumes `postgres-data` and `api-files`, and `api-share`, where
   the rate limiters' counters and the scheduler's last runs outlive a redeploy;
2. deletes the host-side caches that could otherwise serve stale state: `api/var/cache`, `api/var/openapi.json`,
   the generated `web/src/app/api`, and Playwright's saved operator session `web/playwright/.auth`;
3. runs `make up`, which builds, migrates an empty database and seeds it (§ 2, § 4).

`make reset CONFIRM=yes` skips the question, for scripts. It keeps the Docker images (the rebuild is quick) and
`api/vendor` and `web/node_modules`. To also drop the images: `docker compose down --volumes --rmi local`, then
`make up`.

What is **not** reset: anything outside this compose project. The volumes belong to project `twes-in` (or to
`$COMPOSE_PROJECT_NAME`, see § 8).

## 7. Run the checks

These are exactly CI's jobs (`.github/workflows/ci.yml`). They need § 1's host toolchain.

```sh
make gate-licences    # dependency licences, SPDX headers, executable bits, version pins, and each gate's own test
make gate-api         # php-cs-fixer, PHPStan, PHPUnit against the twes_test database (needs postgres running)
make gate-web         # regenerate the API types, lint, format, unit tests, production build
make gate             # the three above
make gate-stamps      # the Decisions Log stamps alone, as the decision-stamps workflow checks a push touching only docs/SPEC.md
make e2e              # Playwright against the running stack (make up first)
```

`make check` is the quick look before them, in seconds rather than the gate's quarter of an hour: each tool is given
only the files changed against HEAD and the untracked ones (`FILES='api/src/… web/src/…'` names others), within the
scope the gate gives it, and a toolchain with nothing to check is not started (`scripts/check/check.sh`).

```sh
make check            # php-cs-fixer, PHPStan (the whole tree, from its cache), and PHPUnit on the test files named;
                      # prettier, eslint on web/src, and ng test on the specs named or beside a changed source
make check FIX=1      # the same, php-cs-fixer, prettier and eslint rewriting what they can
```

- It answers for what it was given, and says the rest aloud: a changed PHP source with no test named runs no PHPUnit,
  a browser scenario is formatted but not run, and a file no tool here checks is listed as `unchecked`. `make gate`
  stays the authority before a commit.
- Never run it beside a gate: both clear and warm `api/var/cache`. It reads its own tools' tallies, so a spec that
  Vitest skipped or a PHPUnit that ran nothing fails it, though the tool exited 0. Like a bare `ng test`, it needs the
  icon font generated once on a fresh clone (below).

`make mutants SPEC=<file>` asks whether a test notices its guarantee broken (`scripts/mutants/mutants.sh`, whose header
says how a spec reads: a name, the file, the test, the text and its replacement). Each mutant is written in, its test
run, and the file put back byte for byte; the summary says which were killed, survived, did not land, or were invalid.

- A mutant is killed only when a test case failed. One that does not parse (`php -l`, `bash -n`), or whose test died
  before its tally (a TypeScript error, a fatal), tested nothing and is INVALID; a PHPUnit error is told apart from a
  failure. Every test runs once unmutated first, and a red one refuses the run.
- The spec lives in the tree (under `var/`, say), the only place both toolchains see. Until a file is back, its copy
  waits in `var/mutants/`: a run killed from outside leaves it there, and the next run prints the line restoring it.
- Never run it beside a gate or `make check`, which read the files it mutates. Under `make up` the live stack serves
  each mutant while it is in place: use neither the app nor `make e2e` during a run. A mutated migration mutates its
  `down()` too: migrate the test database down first, by hand.
- Both start the shared database when a PHP test is in play, and `compose.yaml` names the project: from a linked
  worktree compose would recreate the main tree's `postgres` over the worktree's files, so every start here passes
  `--no-recreate`. A worktree still shares the main tree's test databases unless its `api/.env.test.local` names a
  `TEST_TOKEN` of its own: run them from the main tree.

`make gate-snapshot` gates what is staged without holding the tree (`scripts/gate/snapshot-gate.sh`). The staged tree is
written as a commit object no branch names, checked out in `.claude/worktrees/gate` and gated there with
`make gate`, on test databases of its own (`twes_test_gate`, made the first time) and a temporary directory of its own,
under the lock every heavy run takes (a second gate is refused: two side by side starve each other). Meanwhile work goes
on here, `make check` included, beside the live stack. The result is recorded by the tree's hash in `var/claude/gates/`
with the gate's log.

```sh
git add -A                                 # what is staged is what is gated
make gate-snapshot                         # exits as the gate did; 3 when another gate runs
make commit-gated MSG=<message file>       # commits exactly the last green tree onto HEAD
```

- `commit-gated` refuses a red tree, and a snapshot taken before master moved: gate again on top. What was staged or
  typed after the snapshot stays as it is, staged or not, for the next one.
- It signs the commit as `git commit` would when `commit.gpgsign` is set, and refuses it, keeping the green tree,
  when the signature fails (a locked key): unlock it and run it again.
- The worktree is kept between runs, so `vendor/`, `node_modules/` and the caches are installed once; the first run
  takes a few minutes more. `make e2e` still runs here, alone: the live stack serves this tree.

- **Stage new files before running the gates** (`git add`): the SPDX and executable-bit gates read `git ls-files`.
- After changing an API resource, run `make tools CMD='cd api && bin/console cache:clear && bin/console cache:pool:clear --all'`
  before `make gate-web`, or the OpenAPI export is stale.
- `make e2e` targets http://127.0.0.1:8090. For another port: `BASE_URL=http://127.0.0.1:<port> make e2e`.
- CI splits the e2e suite into three shards, each on its own runner with its own stack and database. To rerun the
  files of a red shard locally: `make e2e E2E_ARGS=--shard=2/3` (the job's name gives the shard). A
  scenario that only passes after another file ran has a hidden dependency the split will expose.
- The web stylesheet needs the icon font cut to the declared icons (`web/scripts/subset-icons.mjs`), which
  `npm test`, `npm start` and `npm run build` generate first. A bare `npx ng test` skips those hooks: on a fresh clone,
  run `make web-tools CMD='npm run icons'` once, or it fails with `Could not resolve "./generated/material-symbols-outlined.woff2"`.
- `make versions` prints every version pin (see `docs/UPDATE.md`).
- `make gallery` screenshots every screen of the running stack, desktop and phone, light and dark, into
  `var/claude/gallery/` with a `manifest.json` (what was captured, and what could not be opened). It checks nothing;
  it is for looking at the screens together. It shows a `make fixtures` company, Carthage Conseil unless
  `GALLERY_COMPANY` names the other, and signs in on its own session with a fresh code, so it waits up to 30 s.

## 8. A second stack next to the first

Everything is keyed on the compose project name and the ports, so a throwaway stack can run beside your own
without touching its data:

```sh
export COMPOSE_PROJECT_NAME=twes-try WEB_PORT=18090 API_PORT=18091 MAILPIT_UI_PORT=18092 \
       MAILPIT_SMTP_PORT=18093 POSTGRES_PORT=15433 GOTENBERG_PORT=18094
make up                                    # http://localhost:18090, Mailpit on http://localhost:18092
docker compose down --volumes --rmi local  # when done, in the same shell
```

Keep those variables exported for every `make` and `docker compose` command aimed at that stack. A shell without
them addresses the default `twes-in` project. `make reset` works there too and wipes only that project's volumes. Its
host-side step (§ 6, step 2) clears the caches of this checkout, which every stack shares, but they regenerate on
the next build or gate.

## 9. When something is off

| Symptom | Cause and fix |
|---|---|
| `make up` fails with "port is already allocated" | Another program holds a port from § 0. Change it in your shell or `.env.local` (§ 0) |
| The code is refused though it looks right | Five attempts in five minutes are spent (§ 3), or the clock of the machine drifted. Wait five minutes |
| A web change does not show | Under `make up-images` the web image is a static build: `docker compose up -d --build web`; under `make up`, `make logs` shows the dev server's compile error (§ 2) |
| `make gate-web` fails on API types that CI accepts | Stale dev cache: `make tools CMD='cd api && bin/console cache:clear && bin/console cache:pool:clear --all'` |
| Every e2e scenario lands on `/two-factor` | Demo's two-step switch is on (§ 4) |
| e2e fails at `browserType.launch: Executable doesn't exist` | Playwright's browser is missing: `make e2e` downloads it into `var/cache/ms-playwright` on its first run |
| The api image fails at `cache:clear` ("Cannot autowire service …") | Work in progress in the working tree went into the build (§ 2). Finish it, or bring up a clean worktree |
| A forgotten-password, signup or invitation mail never arrives | The request only queues it and the `worker` sends it: `docker compose ps worker` must say running, `docker compose exec api bin/console messenger:stats` shows what waits, and `bin/console messenger:failed:show` what failed for good (retry it with `messenger:failed:retry`). Under `make up` the worker's consumer starts again every minute, inside its container, to read the code as it is |
| The api container never starts | A migration failed: `docker compose logs migrate` shows which, and `api` waits until it passes |
| Under `make up` every request answers 500 right after a change adding a migration | `migrate-watch` could not apply it: `docker compose logs migrate-watch` names the failing migration; fix it and save, or run `make up` |
| The production api container stops at once, naming `APP_SECRET`, `APP_MFA_KEY` or a realtime key | Production refuses a missing secret and the development ones committed in `api/.env` (§ 10). Give it its own |
| An operator is told « Contrôle des données en échec » | A data check found what no use case writes: an issued document changed or taken away, sums that no longer add up, a gap in a series' numbers, a row pointing into another company. The operator is told the check, the company and a count only. `make tools CMD='cd api && bin/console app:data:check --quietly'` names each finding, with each check's time and the peak memory; it exits 1 while anything is found, and the worker's log (`docker compose logs worker`) holds the same lines from the night's run |
| An API answer is wrong, slow or refused, and the log does not say why | Open the profiler, development only: <http://localhost:8091/_profiler> lists the last requests, and every answer carries an `X-Debug-Token-Link` header to its own. It shows which voter decided, the listeners in order and their time, every query and their count, and the timeline. Imports are not profiled (§ 7 of `docs/SPEC.md`, 2026-09-19) |

**Measured on this machine** (2026-09-19, a fresh compose project from a clean checkout of the committed tree):
`make reset CONFIRM=yes` to six healthy services and a seeded database took **104 s**, with the base images already
downloaded and the PHP extensions already compiled in Docker's cache. On a machine with neither, add the download
and compile time: the first attempt spent about 2 minutes on those alone.

## 10. The production image

`infra/api/Dockerfile`'s `prod` target is the API as a deployment runs it. Its build:

- sets `APP_ENV=prod` and copies `php.ini-production`, which turns assertions off and never shows an error to a visitor;
- runs FrankenPHP in worker mode, as the development image does (§ 2);
- keeps `infra/api/conf.d/10-app.ini`, the settings for every mode, which include Symfony's recommended OPcache values;
- adds `infra/api/conf.d/20-app.prod.ini`, which stops OPcache checking files for changes and preloads the kernel's
  classes (<https://symfony.com/doc/current/performance.html>);
- installs no dev packages (`composer install --no-dev`) and warms the cache at build.

`compose.prod.yaml` switches the api and both workers to that target; the worker is the same image reading the queue
(`messenger:consume async scheduler_default`: the queue and the schedule), and a deployment runs one, and only one,
beside the API. The `demo-worker` reads the demo sandboxes' builds alone (`messenger:consume demo`), a quarter of a
minute or more each, which a mail would otherwise wait behind. Production refuses to start without secrets of
its own, so give it six, and the mail catcher's address. Each reaches its container as a compose secret, a file under `/run/secrets` (docs/SPEC.md § 7,
Docker secrets), never as its environment, where `docker inspect` would show it: the api image reads them through
`Shared/Infrastructure/Secrets/ContainerSecrets`, loaded with Composer's autoloader, so a `docker compose exec`'d console
reads them too, the database's addresses made from `DATABASE_URL_TEMPLATE` and its migration twin, whose placeholders
take the passwords. None of those variables may be in an api container's environment, not even empty: FrankenPHP's
workers read the environment over what was set at start, and the loader refuses to start rather than let them;
PostgreSQL through `POSTGRES_PASSWORD_FILE`; Centrifugo, pgBackRest and restic through
`infra/postgres/twes-secrets`, which `twes-backup` re-runs itself through when started by `docker compose exec`. A
command of yours exec'd into the backup service starts with it: `docker compose exec backup twes-secrets gosu postgres
pgbackrest … info`. `scripts/gates/secrets-overlay.sh` holds every installation to that in CI:

```sh
export COMPOSE_PROJECT_NAME=twes-prod WEB_PORT=18190 API_PORT=18191 MAILPIT_UI_PORT=18192 \
       MAILPIT_SMTP_PORT=18193 POSTGRES_PORT=15533 GOTENBERG_PORT=18194 \
       COMPOSE_FILE=compose.yaml:compose.prod.yaml \
       APP_SECRET=$(openssl rand -hex 24) REALTIME_TOKEN_KEY=$(openssl rand -hex 24) \
       REALTIME_API_KEY=$(openssl rand -hex 24) APP_MFA_KEY=$(openssl rand -base64 32) \
       POSTGRES_PASSWORD=$(openssl rand -hex 20) POSTGRES_APP_PASSWORD=$(openssl rand -hex 20) \
       MAILER_DSN=smtp://mailpit:1025
docker compose up -d --build --wait                                     # http://localhost:18190
docker compose exec -T api php < scripts/gates/production-image.php     # what the image promises, checked inside it
docker compose down --volumes --rmi local                               # when done, in the same shell
```

This is § 8's second stack on the production image, so your own stack keeps running. `make seed` and `make
operator-code` work there too: seed it before signing in. The demo fixtures do not, because their bundle is a dev
package. CI's `prod-image` job runs the same check on every push.

A secret generated this way lives only in that shell. `APP_MFA_KEY` encrypts every authenticator secret stored, so a
stack restarted with another key cannot read them: keep the six values if the stack is to outlive the shell.
Profiler, dev logs and fixtures belong to `make up`'s development image.

## 11. Which build is running

The line at the foot of every page ends on the builds that answer: « Web 2026.10.07.3 · API 2026.10.07.5 ». Each part
is versioned from git on its own, so a fix to one never moves the other (docs/SPEC.md § 7, 2026-10-07 14:28):
`YYYY.MM.DD.N` is the Paris day of the last commit touching the part's folders (web: `web/`, `infra/web/`; API:
`api/`, `infra/api/`), N counts that part's commits of that day, and « -dirty » says the image was built from
uncommitted changes. Hover or focus the line for each part's commit hash; a click copies the whole line, mode and
deployment included, which is what to paste when reporting a problem.

- `bash scripts/build-version.sh web` (or `api`, or `--env` for all four values) says what an image built now would be.
  The images are built without `.git`, so `make up` and CI run it and pass the result as build arguments; a
  `docker compose up --build` of your own builds an image that says « non versionnée ». CI fetches the whole history
  for it, since a one-commit checkout cannot count the day's changes.
- After a part's version, its build mode shows when it is not production: `dev` for an Angular development build (what
  `make up` serves), the API's `APP_ENV` otherwise. A last `[staging]` or `[dev]` names the deployment, from
  `DEPLOY_ENV` (`dev` in compose.yaml, `prod` in compose.prod.yaml; a deployment sets its own). Nothing of production
  is shown.
- The web serves its build at `/version.json`, never cached. A page reads it when it starts, then every five minutes and
  whenever it comes back into view; when the server holds another build, « Nouvelle version disponible — Recharger »
  appears, and only the button reloads. A new API changes the line quietly.
- `/api/health` answers the API's build and the deployment, as they are: `{"build": {"version", "commit", "mode"},
  "deployment"}`.

## 12. The guide videos

`make videos` films the guides on the running stack: each guide is opened from Ctrl K as a person opens it, its
narration is drawn in the page as a caption and held for as long as it takes to read, and the work under a step is
typed at a person's pace. The films go to `var/claude/videos/<language>/` (gitignored), beside them a captions track
(`<name>.<language>.vtt`), a chapters track (`<name>.chapters.vtt`) and the cues (`<name>.cues.json`). The narration is
written for anyone who opens the app, every feature and its shortcut included; a key is read from the screen when the
film is made, never written in the script, because a person may have changed it.

- It needs `make up` and `make fixtures`: the first film works in « Carthage Conseil » (`RECORD_COMPANY` names another)
  and saves nothing. The company's drafts are counted before and after, and the run fails if they differ.
- It refuses to start while a gate's toolchain container runs or while the load average is above 6 (`MAX_LOAD=…`
  moves it): a loaded machine films stutters. The guard is checked at the start only; start no gate until it ends.
- After the films, each one is opened again in the browser and played; a frame is kept at the end of each chapter, its line still up, as
  `<name>.check-<n>.png`. The run fails when a film is shorter than its narration, a chapter's frame comes late, or two
  chapters show one picture. Look at the frames: no test says a caption is readable.
- It is not part of CI: `web/e2e/tours.spec.ts` proves the guides run, and a film is made for a release.
  `web/e2e-recorder/captions.test.ts` (the reading time, the WebVTT files) runs first in the same command.

## 13. Backups and the restore drill

`compose.backup.yaml` adds backups to the production stack (§ 10). It is a layer of its own, so a deployment that runs
without it has none; the deploy compose includes it. It says what is archived, and a destination layer over it says
where: `compose.backup-bucket.yaml`, a server's S3-compatible bucket, every value required (this section), or
`compose.backup-local.yaml`, a shop's own folder, copied off site by `compose.backup-offsite.yaml` when that is switched
on (§ 16). Without a destination the layer archives nowhere, so every command below names one.

- The database is built from `infra/postgres/Dockerfile`: the same PostgreSQL as `compose.yaml`, with pgBackRest. It
  hands its WAL to pgBackRest as it writes, and pgBackRest sends it, encrypted, to an S3-compatible bucket. A quiet
  database still sends within five minutes (`archive_timeout=300`), so a lost machine loses at most that.
- The `backup` service takes the base backups the WAL replays from (full on Sunday, differential the other nights, at
  01:00 UTC), and at 01:30 the night's `pg_dump` and the files volume (logos, issued PDFs, attachments) through restic,
  encrypted, into the same bucket under its own prefix. Whatever is older than 14 days is removed by each tool. Once a
  week both read their repository back whole. Its schedule is `infra/postgres/backup/crontab`; every step is
  `twes-backup <step>` (`run`, `init`, `full`, `diff`, `copy`, `verify`), which runs by hand the same way:
  `docker compose exec backup twes-backup full`.
- A deployment storing files in a bucket (`FILES_STORAGE=s3`) has an empty files volume: its files rest on that
  bucket's own versioning and replication, which its provider configures.

It refuses to start without the bucket and two passphrases. Keep the passphrases somewhere other than the server: a
backup is unreadable without its passphrase. The passphrases and the bucket's secret key reach pgBackRest and restic as
compose secrets (§ 10), so a step of yours exec'd into the backup service runs through `twes-secrets`, as
`twes-backup` does on its own.

```sh
export BACKUP_S3_ENDPOINT=<the bucket service's host> BACKUP_S3_BUCKET=<bucket> BACKUP_S3_REGION=<region> \
       BACKUP_S3_KEY=<access key> BACKUP_S3_SECRET=<secret key> \
       BACKUP_DATABASE_PASSPHRASE=$(openssl rand -base64 36) BACKUP_FILES_PASSPHRASE=$(openssl rand -base64 36)
# BACKUP_S3_PORT (443) and BACKUP_S3_URI_STYLE (host; path for most services other than AWS) when they differ
docker compose -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.backup-bucket.yaml up -d --build --wait
```

**Restoring** runs the layer's `restore` service, which starts only when named. It writes the database's volume, which
pgBackRest fills only when it is empty, as on a new machine before the first `up`, and the files volume, which restic
overwrites. Every value as above, the database server stopped:

```sh
B="docker compose -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.backup-bucket.yaml"
$B run --rm --no-deps restore gosu postgres pgbackrest --stanza=twes --spool-path=/tmp \
  --type=time "--target=2026-10-12 08:25:00+00" --target-action=promote restore < /dev/null
  # without --type, --target and --target-action: the latest that reached the bucket
$B run --rm --no-deps restore restic restore latest --host twes --include /files --target / < /dev/null
  # each run reads no input: pasted as one block, it would take the lines after it as its own
$B up -d --build --wait
```

On the machine that lost its database, the volume still holds what is left of it, and pgBackRest refuses it: emptying or
replacing it is the runbook's step, not this page's. The nightly dump comes back with `--include
/var/lib/twes-backup/dump`, for `pg_restore`.

**The drill.** `make backup-drill` does all of it on its own: the production images with this layer, a stand-in bucket
(SeaweedFS, behind a certificate authority made for the run), a backup, a row written, then the moment noted from the
database's clock, a second row, the night's differential, both repositories verified; then two restores from the bucket
alone into empty volumes, through the `restore` service and the commands above. To the moment only the first
row may come back, to the latest both. On the second the API must answer healthy and `app:data:check` find nothing, and
restic must give back the files and the dump unchanged. It prints each step's time, runs as the compose project
`twes-drill` beside your own stack, refuses any other, and removes its containers and volumes at the end. CI runs it
weekly, by hand, and on every push that changes the backups (`.github/workflows/restore-drill.yml`).

What it does not prove: the four hours the ruling allows for a real database (the drill restores a small one); a
bucket addressed by host (`BACKUP_S3_URI_STYLE=host`, AWS itself), since the stand-in is addressed by path; that the
schedule fires at its hours (the drill runs each step itself); and a restore by hand on a new machine from the real
bucket, which follows the first deployment.

## 14. Deploying

A deployment is the production stack (§ 10) with its backups (§ 13) behind a Caddy edge, `compose.deploy.yaml`. The
edge is the only service that publishes a port: 80, which answers 308 to HTTPS, and 443 over TCP and UDP. It takes its
certificate from Let's Encrypt on the first request and renews it alone, and it sends
`Strict-Transport-Security: max-age=31536000` for this host only (no subdomains, no preload list). Every address the
API, the passkeys, the phone pairing and the realtime server use is derived from one name, `TWES_HOST`. Mailpit does
not run: mail goes to the relay named in the values.

**The machine.** Any Linux host with Docker and the Compose plugin, `git` and `make`. A DNS
`A` (and `AAAA`, if the host has IPv6) record for the name points at it before the first start, or the certificate
cannot be issued. Close every port but 22, 80 and 443 at the provider's own firewall: a port Docker publishes goes
around `ufw` and `firewalld`, so a host firewall alone does not protect it. The deployment publishes none but the
edge's, which `scripts/gates/deploy-overlay.sh` holds in CI.

**The values, once.** One file outside the checkout holds everything the instance needs. `new-instance.sh` writes it,
readable by its owner alone, with every secret drawn at random, and refuses to write over one that exists, since it
holds the keys the database and backups are encrypted under:

```sh
sudo install -d -m 700 -o "$USER" /etc/twes-in
bash scripts/deploy/new-instance.sh /etc/twes-in/deploy.env
```

It prints which values to keep somewhere other than the server (the MFA key, the two backup passphrases, the
application secret, the database password): without them a restored backup cannot be read. Then fill in the facts it
left blank: `TWES_HOST`, `EDGE_TLS` (the address Let's Encrypt writes to), `MAILER_DSN` and `MAILER_SENDER`, the
bucket (§ 13), and the monitor's two (§ 15): `MONITOR_URL`, its status page's address, and `SENTRY_DSN`, the address its
error tracker gave this instance. It also draws the two tokens the monitor reads the instance with, `MONITOR_TOKEN` and
`MONITOR_HEARTBEAT_TOKEN`, which are copied into the monitor's own values. Every one is required: compose refuses to
start while a setting is empty, and `deploy.sh` while a secret is, since compose would start with nothing mounted. The
secrets among them (the mail relay, the bucket's secret key, the keys, passwords and tokens) reach the containers as
compose secrets, never their environment (§ 10). The name never changes afterwards: passkeys are bound to it.

**The monitor first.** The deployment requires the monitor's two values, so a first deployment comes after its
monitor (§ 15): `new-instance.sh` draws the two tokens; `new-monitor.sh` writes the monitor's values, into which they are
copied; the monitor starts; its operator account and `bootstrap.py` give the DSN; `SENTRY_DSN` and `MONITOR_URL` go into
the instance's values; then the first deployment below.

**Deploying a release.** A release is an annotated tag `vYYYY.MM.DD.N` on master whose message names the three images CI
published for that commit, by digest (`scripts/release/tag-release.sh`; promoting and rolling back are in
`docs/RUNBOOK.md`). On the server:

```sh
git fetch --tags && git checkout v2026.10.12.1
bash scripts/deploy/deploy.sh --release /etc/twes-in/deploy.env
```

`deploy.sh` refuses unless HEAD is exactly at a release tag and the checkout is clean, untracked files included, and
unless the values file is mode 600. `--release` builds nothing: it pulls the tag's images by digest, refuses one that
says it was built from another commit, and starts the four files with `compose.release.yaml` and `--env-file`; it
writes what it started under `releases/` beside the values, which a rollback deploys again. A self-hosted instance
that builds its own images from the tag runs `deploy.sh --build` instead, which builds them as the version git gives
(§ 11). Staging runs `--staging`, from `scripts/deploy/staging-follow.sh` (`docs/RUNBOOK.md`). Always deploy through it: a bare `docker compose` on the server
reads the committed development `.env` for whatever it lacks, its database password included. The one-shot `migrate`
service migrates and grants the application's role before the API starts (§ 2).

**The first operator**, once, after the first deploy. Production makes no first company: an operator reaches a company
only through the support access its owner opens. The password is read from standard input, so it stays out of the
shell's history and the process list:

```sh
read -rs PW && printf '%s\n' "$PW" | docker compose --env-file /etc/twes-in/deploy.env \
  -f compose.yaml -f compose.prod.yaml -f compose.backup.yaml -f compose.backup-bucket.yaml -f compose.deploy.yaml \
  exec -T api bin/console app:seed --no-interaction --without-company --operator-email=<address> --operator-password-stdin; unset PW
```

Sign in at `https://<name>/` and enrol the authenticator: until then an operator reaches nothing but the enrolment. Then fill in the host's facts on
`/platform/legal` (the provider, its country, the backup provider and the monitor's provider, each with its country):
the legal pages print them. On `/platform`, the support card's « Page d’état du service » takes the status page's
address, which the footer of every page then links to.

**The deploy check.** `make deploy-check` boots the same four files from values `new-instance.sh` wrote, given through
`--env-file`, with the edge's own certificate authority (`EDGE_TLS=internal`), the restore drill's layer (its stand-in
bucket, and its authority mounted into the database and backup services) and a layer of its own (the edge publishing
nothing, Mailpit started as the relay), and asks the edge what a browser asks: the HSTS header, the 308 from plain HTTP, `/api/health`, the operator seeded as above signing
in and reading `/api/auth/me` with its cookie, the realtime socket's upgrade, and six password-reset requests each
claiming another `X-Forwarded-For`, whose sixth must still be refused as one client's (429). It runs as the compose
project `twes-deploy-check` beside your own stack, publishes nothing, and removes everything at the end. CI runs it
weekly, by hand, and on every push that changes the path from browser to API (`.github/workflows/deploy-check.yml`).

What it does not prove: `deploy.sh` itself, whose refusals and compose line its own test checks against a stand-in
`docker` (`scripts/tests/deploy.test.sh`); a certificate from Let's Encrypt (the check's authority is Caddy's own); the
mail relay; the DNS record; HTTP/3 from a real browser; how much memory a deployment needs; and a first deployment on a
real machine, which follows.

## 15. The monitor

What watches an instance must not fall with it, so the monitor runs on a small machine of its own, at another provider
if possible: `infra/monitor/compose.yaml`, three services behind a Caddy edge of their own.

- **Gatus** reads the instance every minute, shows the public status page and alerts by mail and Telegram after three
  failed reads in a row, then again once the line is back. Its lines (`infra/monitor/gatus.yaml`): the site answering
  over a certificate with two weeks left at least; `/api/health/deep` read with `MONITOR_TOKEN` (the queue, the files
  volume's free space and the database's archiving, 503 when one fails); the error tracker itself; and the backups'
  heartbeats, which each step of `twes-backup` posts when it ends: `Base` (nightly, 26 hours), `Copie` (nightly, 26
  hours) and `Relecture` (weekly, 8 days). Silence past a heartbeat's interval raises the alert. The page is public, so
  every line hides its address, host, port, conditions and errors, and no alert names a customer.
- **GlitchTip** receives the errors. The API reports through Sentry's SDK, and the web posts its own errors to the
  API (`POST /api/client-errors`), which reports them the same way: no script from another site runs in the browser.
  Addresses, quoted values and long numbers are masked before anything leaves the instance; an error report is kept 30
  days. Nobody signs up: the operator's account is made once, below.
- Its **PostgreSQL**, for GlitchTip alone.

**The machine.** As in § 14: Docker with the Compose plugin, `git`, and only 22, 80 and 443 open at the provider's
firewall. Two DNS names point at it, one for the status page and one for the error tracker (GlitchTip does not run
under a path).

**The values.** `new-monitor.sh` writes them, readable by their owner alone, the monitor's own secrets drawn at random,
and refuses to write over a file that exists:

```sh
sudo install -d -m 700 -o "$USER" /etc/twes-monitor
bash scripts/monitor/new-monitor.sh /etc/twes-monitor/monitor.env
```

Fill in the facts: the instance's `TWES_HOST`, and `MONITOR_TOKEN` and `MONITOR_HEARTBEAT_TOKEN` copied from its values
file; `STATUS_HOST` and `ERRORS_HOST`; `EDGE_TLS`; the alerts' mail relay (`ALERT_SMTP_*`, `ALERT_FROM`, `ALERT_TO`),
an account other than the instance's, so alerts still leave when the instance's relay is the trouble; and the Telegram
bot (`TELEGRAM_TOKEN` from @BotFather, `TELEGRAM_CHAT_ID`). Every one is required.

**Starting it**, from a checkout on the monitor's machine:

```sh
docker compose --env-file /etc/twes-monitor/monitor.env -f infra/monitor/compose.yaml up -d --wait
```

**The operator's account and the instance's DSN**, once. The password is read from standard input and handed to the
container through the environment (`-e` with a name and no value), so it stays out of the shell's history and the
process list:

```sh
M="docker compose --env-file /etc/twes-monitor/monitor.env -f infra/monitor/compose.yaml"
read -rs PW && DJANGO_SUPERUSER_PASSWORD="$PW" $M exec -T -e DJANGO_SUPERUSER_EMAIL=<address> \
  -e DJANGO_SUPERUSER_PASSWORD glitchtip python manage.py createsuperuser --noinput; unset PW
$M exec -T -e OPERATOR_EMAIL=<address> glitchtip python manage.py shell < infra/monitor/bootstrap.py
```

The second line prints `SENTRY_DSN=…`: put it in the instance's values with `MONITOR_URL=https://<status name>`, deploy
the instance (§ 14), and fill in the status page's address and the monitor's provider on its platform pages.
`bin/console app:errors:test` on the instance's api then puts one masked test error in GlitchTip.

**The monitor check.** `make monitor-check` boots a deployment (as the deploy check does) and a monitor from values the
two scripts wrote, joined by one network on which each edge answers under its names, each trusting the other's
certificate authority. Then Gatus must read the deep health green and take a base backup's heartbeat, an API error and
a web error must reach GlitchTip's API with no address, quoted value or long number in them, and a tracker that never
answers must cost a failing command a few seconds at most. It runs as the compose projects `twes-monitor-check` and
`twes-monitor-check-app`, publishes nothing, and removes everything at the end. CI runs it weekly, by hand, and on every
push that changes the monitor or what reports to it (`.github/workflows/monitor-check.yml`).

What it does not prove: the mail and Telegram alerts (the relay and the bot are yours: an alert that fires during the
check goes nowhere); the « Site » line, which an internal certificate of hours always fails; a certificate from Let's
Encrypt; and the monitor itself staying up, which nothing watches.

## 16. A shop's own computer

A shop can run twes-in on its own computer instead of a server: the production images (`compose.prod.yaml`), its
backups in a folder of its own (`compose.backup-local.yaml`, copied off site when switched on by
`compose.backup-offsite.yaml`) and an edge of its own (`compose.onprem.yaml`, `infra/onprem/Caddyfile`). Nothing is
built there: the images are pulled by the digests a release tag names (§ 14), and `scripts/onprem/twes` runs everything.

**Two addresses, no name to buy.** The computer itself opens `https://localhost`, where passkeys work (a browser binds a
passkey to a name, never to an address). A phone or another computer on the shop's network opens
`https://<this computer's address>`, which `twes` reads at each start, since a router may hand out another; a fixed one
goes in the values as `LAN_HOST`. With no network at all, the computer alone opens `https://localhost`. The edge's
certificates come from its own authority: a phone trusts it once, from `http://<address>/root.crt`, then opens the
HTTPS address. An Android phone installs it as a CA certificate from its security settings (the menu's name varies by maker);
an iPhone installs the downloaded profile, then turns on full trust under Settings > General >
About > Certificate Trust Settings, without which Safari still warns. Mailed links name the network address.

**Installing on Windows.** The checkout lives inside WSL's own filesystem (`~`, never `/mnt/c`), where `.gitattributes`
keeps every file as git holds it, and Docker runs one of two ways, chosen at the shop:

| | `-Engine Desktop` (the default) | `-Engine Wsl` |
|---|---|---|
| Docker | Docker Desktop | Docker Engine inside Ubuntu, a systemd service; no Docker Desktop |
| Windows restarted | up once the shop's account signs in (Docker Desktop is per session) | up from Windows' start, nobody signed in: the `\twes-in\keep` task (S4U, no password stored) starts WSL, systemd starts Docker and `twes start` |
| Docker restarted | the containers come back (`restart: unless-stopped`) | the same; systemd restarts Docker itself |
| WSL stopped | Docker Desktop's own | `keep` runs it again within a quarter hour (it also runs at each sign-in and every quarter hour) |
| A container stopped, the health failing | `twes tick` starts the installation again once, then alerts if that did not mend it | the same, `tick` run by systemd's timer |
| Phones | Docker Desktop's port publishing | WSL's mirrored network (`.wslconfig` `networkingMode=mirrored`) and a Hyper-V firewall rule for ports 80 and 443 |
| Needs | Windows 10 or 11 | Windows 11 22H2 or later (build 22621), WSL from the Microsoft Store |

From the shop's own Windows account, the one signed in every morning, in PowerShell opened as administrator:

```powershell
powershell -ExecutionPolicy Bypass -File install.ps1               # Docker Desktop
powershell -ExecutionPolicy Bypass -File install.ps1 -Engine Wsl   # Docker inside Ubuntu
```

`-Engine Wsl` adds `[general] instanceIdleTimeout=-1` and `[wsl2] networkingMode=mirrored` to `%UserProfile%\.wslconfig`
(a key the shop set to another value stops it, never overwritten; [Microsoft's settings](https://learn.microsoft.com/windows/wsl/wsl-config)),
then runs the checkout's `scripts/onprem/wsl-engine.sh` as root once the tag is verified: systemd switched on in
`/etc/wsl.conf`, WSL started again, Docker Engine installed from Docker's own apt repository (its key's fingerprint
checked first, as [Docker's page](https://docs.docker.com/engine/install/ubuntu/) installs it), `docker` and
`containerd` enabled and the account added to the `docker` group. `twes install` then writes systemd's units, as on
Linux (`twes schedule` writes them under WSL whenever Docker is not Docker Desktop), and the installer registers `keep`
and the firewall rule instead of the `start` and `tick` tasks; switching engines removes the other engine's tasks. It
refuses while Docker Desktop is installed, since Docker Desktop's own `docker` would answer inside Ubuntu instead.
Untried on Windows: WSL starting under an S4U task before anyone signs in, and the phones reaching Docker through the
mirrored network, are what TODO step 6B checks; Docker Desktop stays the way back.

**Windows reserving a port.** Hyper-V may reserve ranges of ports, and the edge cannot publish 80 or 443 inside one:
`netsh interface ipv4 show excludedportrange protocol=tcp` lists them, and the installer refuses to go on while one holds
80 or 443. A reserved range moves at each restart; one that keeps coming back is reserved by hand, in PowerShell as
administrator, before Hyper-V starts: `net stop winnat`, `netsh int ipv4 add excludedportrange protocol=tcp startport=80
numberofports=1` (and 443), `net start winnat`: a port excluded by hand is never handed out again, and stays free for
the edge. That is the fix Docker's and WSL's users give; it is untried here.

`scripts/onprem/install.ps1` does what is still missing and stops at each step that needs a person, to be run again
after it: WSL and Ubuntu (a restart, then Ubuntu opened once to make its user), Docker Desktop (started once, its terms
accepted, « Start Docker Desktop when you sign in » ticked, WSL integration on for Ubuntu), git, gpg, curl and flock in
Ubuntu, the checkout at the newest release tag, which it verifies against the release key's fingerprint it carries
itself (`-ReleaseKey`, the developer's by default) before anything from the checkout runs, then `twes install` in
Ubuntu. Bring `install.ps1` from the developer (a USB key, a mail from them), not from the repository: it is what
vouches for the checkout. On Windows' side it then trusts the
edge's authority (Edge and Chrome read Windows' store; Firefox only once « Allow Firefox to automatically trust
third-party root certificates you install » is ticked), schedules `twes start` at each sign-in and `twes tick` every
quarter hour as tasks under `\twes-in\`, and puts a twes-in shortcut on the desktop. The tasks run `wsl.exe`, which
shows a console window for the moment it runs; `-HideWindows` runs them through `conhost.exe --headless`, which shows
none but which Microsoft does not document, so it is kept only once tried on that computer. The file stays ASCII:
Windows PowerShell 5.1 reads a script without a byte-order mark in the computer's ANSI code page
(`scripts/tests/twes.test.sh` checks it).

**Installing on Linux.** Docker Engine, the account in the `docker` group, git, gpg, curl and flock, then:

```sh
git clone https://github.com/<owner>/twes-in.git ~/twes-in && cd ~/twes-in
git checkout --detach "$(git tag --list 'v[0-9][0-9][0-9][0-9].[0-9][0-9].[0-9][0-9].*' --sort=-v:refname | head -n 1)"
g=$(mktemp -d) && gpg --homedir "$g" --batch --quiet --import infra/onprem/release-key.asc
GNUPGHOME=$g git verify-tag --raw "$(git describe --exact-match --tags HEAD)" 2>&1 | awk '$2 == "VALIDSIG" { print $NF }'
bash scripts/onprem/twes install
```

The fingerprint `verify-tag` prints must be the developer's, below, before `twes install` runs: what the checkout runs
is only as trustworthy as that comparison.

`twes install` asks for the shop's facts and writes the values (`~/.twes-in/install.env`, mode 600, through
`new-instance.sh --onprem`): the shop's Gmail address and an app password (the mail goes through `smtp.gmail.com:587`),
`ALERT_EMAIL`, who is mailed when something goes wrong, and the backups' folder, on a second disk or a USB drive, or
blank for a volume on this disk. It shows the release key's fingerprint and pins it only once `yes` is typed, installs
the newest release, makes the platform's operator (the password read twice, hidden, handed to `app:seed` on its input),
and on Linux writes `twes schedule`'s units. Run again, it asks only what is still missing. Keep a copy of the keys the
values list (§ 14) away from this computer, in the password manager with the shop's facts, which are never written
anywhere else. The secrets among them reach the containers as compose secrets, never their environment (§ 10). On macOS nothing is scheduled yet: `twes start` after each start of the
computer and `twes tick` every quarter hour are run by hand, and `twes install` says so.

**Signed releases.** `twes update` takes a release only from a tag signed by the key pinned at install
(`~/.twes-in/release-keys/`): the checkout's own `infra/onprem/release-key.asc` is what `twes install` offers, and is
pinned only once the fingerprint the developer gave is confirmed, because whoever could forge a tag could change that
file too. A newer tag nobody signed, or signed by another key, is refused before anything is backed up or checked out,
written down in `state/refused-releases`, and mailed and told to the operators in the application: once from `tick`,
under `notify` as under `auto`, and again each time `twes update` asks for it by hand. The developer's key: ed25519, fingerprint
`0201 4686 9960 09C7 41A9 2ADF 0C53 7690 706E B7F5`. `twes pin-key <file>` pins another (a secret key is refused).

**The edge's authority.** Trusting it lets this computer and the shop's phones open the HTTPS addresses without a
warning; it also means that whoever reads its key, in the edge's Docker volume, could impersonate any site to them, as
with any local authority (mkcert says the same of its own). Whoever can read a Docker volume here already runs as
root on this computer; keep the computer's accounts to the shop's own, and the authority is never copied elsewhere.

**What `twes` does.**

| Command | What it does |
|---|---|
| `twes start` | waits for Docker (five minutes), reads this computer's address, starts the release deployed here again; nothing is fetched, so a morning without the internet still opens |
| `twes update` | the newest release tag, once its signature verifies: the database and the files backed up first, the release deployed by digest, its deep health read through the edge; unhealthy, the release before it is started again on the new schema and the developer told; cut short (the computer switched off, a task's time limit), what it was doing is written down first (`state/update-in-progress`), and the next `start` or `tick` takes the shop back the same way |
| `twes policy auto 13:00-14:00` | updates applied by `tick` inside that daily window, in the shop's time, never just after a start, a release that failed here never retried by itself |
| `twes policy notify` / `off` | a release out is told to each company's owners in the application, or nothing |
| `twes offsite on` / `off` | the hourly off-site copy of the backups, once the bucket's values are given |
| `twes tick` | a new address, alerts held back, the deep health (failing, the installation started again once, the developer told only if that did not mend it, never within ten minutes of a start), the backups' outcomes and schedule, then the policy |
| `twes status` | the release, the address, the policy, where the backups are and whether a copy leaves the computer, the health, how often `tick` started it again, and the backups |
| `twes restore-commands` | what takes the installation back to the backup made before the last update (docs/RUNBOOK.md, « Restore a shop ») |
| `twes install` | asks for the shop's facts, and whether the computer stays on at night, and proposes the daily update window from that (02:00-05:00, otherwise the hour after closing, never across midnight) for `twes policy auto`; pins the release key once its fingerprint is confirmed, installs the newest release and makes the operator; on Linux and under `-Engine Wsl`, schedules as well |
| `twes pin-key <file>` | pins a public release key: every release tag must verify against it |
| `twes schedule` | Linux, and Docker Engine inside WSL (`-Engine Wsl`): system units running `twes start` at boot and `twes tick` every quarter hour as this account, once Docker runs (`sudo` asks for the password) |

**Backups.** The computer is off at night, so its backups run when due: every half hour the schedule runs each step whose
last success is older than its period (a full backup weekly, a differential daily, the dump and the files daily, a
read-back weekly, the off-site copy hourly), the outcomes kept in a volume of their own. Both repositories are
encrypted under the values' passphrases. An update always backs up first, locally, and keeps that backup's label.

**What a shop can lose.** The database archives its changes into the backups' folder within five minutes
(`archive_timeout=300`); the files (logos, issued PDFs, attachments) reach it with the day's copy. What a loss costs
depends on where that folder and its copy are; `twes install` never refuses, says which case the shop is in, and
`twes status` says it again:

| Where the backups are | A dead system disk loses | A stolen, burnt or encrypted computer loses |
|---|---|---|
| blank folder, off site off: a Docker volume on this disk | everything, the backups with it | everything |
| a second disk or a USB drive (`BACKUP_DIRECTORY`), off site off | up to five minutes of the database, the files since the day's copy | everything, unless the drive is kept elsewhere |
| with `twes offsite on` (an S3-compatible bucket: a free Backblaze B2 or Cloudflare R2 account will do) | as above with a second disk; without one, up to an hour | up to an hour of the database, the files since the day's copy: the folder is copied there every hour the computer is on (`BACKUP_OFFSITE_EVERY`, 3,000 s by default, as the schedule runs every half hour) |

The folder on a second disk is named by `twes-in-backups.id`, written in it and as `BACKUP_FOLDER_ID` in the values the
first time it is there: a drive unplugged at start leaves an empty folder in its place, which the backup service
refuses rather than backing up onto this computer's own disk (the database keeps its changes until the drive is back);
`twes start` and `tick` mail the developer once a day, and `twes update` refuses, until it is plugged back in. A USB
stick left plugged into the shop's computer is a second disk, not an off-site copy. While no copy leaves the
computer, `tick` mails the developer once a day saying so and naming what to do.

**Alerts.** A failed or rolled-back update, a failing deep health that starting the installation again did not mend, a failed backup step and a backup schedule gone quiet
are mailed through the shop's own account to `ALERT_EMAIL`, so a mail leaves while the application is down, and told
to the platform's operators in the application (`app:installation:tell`), held under `~/.twes-in/state/` until it
answers. A mail that does not leave (no internet that minute, a password revoked) is kept under `state/pending-mails`
and sent by the next `tick`; `twes status` counts what waits. A release out is told to the owners only.

**The schema only grows.** Since an unhealthy update starts the release before it on the new schema, a migration added
after a release tag never drops, renames or narrows what that release reads (`scripts/gates/additive-migrations.sh`);
the contraction comes in a later release, its line saying why that is safe.

**The on-premises check.** `make onprem-check` publishes the working tree to a local registry as a release, installs it
with `twes update` from a clone of the release tags, asks both addresses for health and the operator's sign-in, then
publishes a second release with one migration more and an api that fails its deep health: `twes update` must back up,
deploy it, read it unhealthy and start the first again on the new schema, the operator told and still signing in; then
`twes restore-commands`, run as written, must take the database back to the backup made before it. Both releases are
signed by `tag-release.sh` with a key made for the check, which the shop pins; a newer tag nobody signed must then be
refused with the release unchanged and the operator told. It runs as the compose project `twes-onprem-check`, needs
ports 80 and 443 free, and takes about ten minutes.

What it does not prove: Windows and macOS (it runs on Linux; `make gate-powershell` parses `install.ps1`, runs
PSScriptAnalyzer over it and its `.wslconfig` editing, and `scripts/tests/wsl-engine.test.sh` runs `wsl-engine.sh` against
stand-ins, but neither starts WSL), the
scheduled tasks and units firing (`systemd-analyze verify` reads the units), the mail to `ALERT_EMAIL` (the relay is the
shop's), a phone trusting the authority, an off-site copy, and a real release's own rollback, which depends on that
release keeping its schema additive.

**The first Windows install, tried before a shop's.** On a Windows computer with nothing installed: run `install.ps1` to
the end, restart, sign in, and check that Docker Desktop starts, `\twes-in\start` ran (Task Scheduler, its last result
0), `https://localhost` opens without a warning in Edge, a phone opens `http://<address>/root.crt` then the HTTPS
address and scans, `\twes-in\tick` runs every quarter hour, and `wsl -d Ubuntu -- bash twes-in/scripts/onprem/twes
status` reads healthy with its backups due. Then once with `-HideWindows`: no window shows when a task runs.
