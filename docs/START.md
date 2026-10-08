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
| one API suite | `make test-api` (starts postgres first), or `make tools CMD='cd api && vendor/bin/phpunit tests/Unit'` (start postgres first: `docker compose up -d --wait postgres`) | `tools` |
| the web unit tests | `make test-web` | `web-tools` |
| the browser tests | `make e2e` (`E2E_ARGS=--shard=1/3` passes options), `make gallery` | `web-tools`, on the host's network so `127.0.0.1:8090` is the stack's published port |
| the OpenAPI document, the types, the notices, the pins | `make api-openapi`, `make api-types`, `make notices`, `make versions` | `tools`, `web-tools` |
| a PHP syntax check | `make php-lint FILE=<path>` | `tools` |

The first run of each builds its image (`make tools-image`, `make web-tools-image`; both are no-ops when nothing
changed) and installs the dependencies into the working tree's own `api/vendor` and `web/node_modules`, which are
gitignored; Chromium is downloaded once into `var/cache/ms-playwright`. `make` hands the services your uid, the
checkout's path, the Docker socket's group and a temporary directory outside the tree (`/tmp/twes-in-tools-<uid>`:
a gate that builds a fixture in a directory git ignores would see nothing in it). A bare `docker compose --profile tools run …` falls back to
uid 1000 and the current directory but not to a temporary directory the Makefile made: use `make`.

The Docker-driving gates (`compose-log-rotation`, `forwarded-proto`, `live-proxy`) start containers through the host's
socket, which is why the tree is mounted at its own path: a path the gate gives the daemon is one the daemon sees.

## 2. Bring it up

```sh
make up
```

This does three things, in order:

1. **Builds the images and starts every service** (`docker compose up -d --build --wait`), live (below). It waits
   until each one is healthy: the first time, the web tier installs its dependencies before it serves. The first build downloads and compiles everything (see § 9 for a measured time). Later builds
   reuse Docker's cache.
2. **Migrates the database.** The `api` container applies pending migrations every time it starts, before
   serving (`infra/api/docker-entrypoint.sh`). A failed migration stops the container instead of serving a
   half-migrated schema. You never run migrations by hand. `make migrate` exists for a running container. Right
   after, it tells the companies that asked « Me prévenir » for a module this release brings
   (`app:modules:announce-arrivals`); each is told once, so a restart tells nobody again.
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
- The operator is also the **owner of the Demo company**, so after signing in you land in Demo's home page — unless
  you have run `make fixtures`, which makes the operator a member of Demo, Carthage Conseil and Atelier Mercier.
  A sign-in picks a working company only when there is exactly one, so with the fixtures loaded you land on a
  company choice instead. Pick **Demo**; that is the state this file describes. Its
  **Manage the platform** link (*Gérer la plateforme*) opens **Platform** (http://localhost:8090/platform), where the
  operator runs the platform.

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

### 5.4 Another platform operator

Operators are created from the console only, never from the application:

```sh
docker compose exec -T api bin/console app:seed \
  --operator-email=you@example.com --operator-password='a long passphrase of yours'
```

This creates the operator and makes them an owner of Demo (`--company-name=<name>` for another company, which is
created if missing). Without `--operator-totp-secret`, the new operator enrols their own authenticator at first
sign-in. That is how a real deployment's operator is created. **Never pass `--operator-totp-secret` outside
development:** a known secret is a known second factor.

### 5.5 Deactivate an account, end sessions

**Platform** → **Accounts**: search an address, then **Deactivate** (or **Reactivate**) or **End sessions**. An
operator cannot deactivate their own account. Removing someone from one company only is done on that company's
**Members** page.

### 5.6 Two-step verification for a company's members

Company settings → **Security** → **Require two-step verification** turns it on for everyone in that company. Each member
then enrols an authenticator app or a passkey at their next sign-in.

## 6. Start clean

```sh
make reset
```

It asks you to type `yes`, then:

1. `docker compose down --volumes --remove-orphans`, which **deletes the database** (`twes` and `twes_test`) and
   the **stored files** (issued PDFs, attachments): the two volumes `postgres-data` and `api-files`;
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
| The api container restarts in a loop | A migration failed: `docker compose logs api` shows which, and the container refuses to serve until it passes |
| The production api container stops at once, naming `APP_SECRET`, `APP_MFA_KEY` or a realtime key | Production refuses a missing secret and the development ones committed in `api/.env` (§ 10). Give it its own |
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

`compose.prod.yaml` switches the api and worker services to that target; the worker is the same image reading the queue
(`messenger:consume async scheduler_default`: the queue and the schedule), and a deployment runs one, and only one,
beside the API. Production refuses to start without secrets of
its own, so give it four:

```sh
export COMPOSE_PROJECT_NAME=twes-prod WEB_PORT=18190 API_PORT=18191 MAILPIT_UI_PORT=18192 \
       MAILPIT_SMTP_PORT=18193 POSTGRES_PORT=15533 GOTENBERG_PORT=18194 \
       COMPOSE_FILE=compose.yaml:compose.prod.yaml \
       APP_SECRET=$(openssl rand -hex 24) REALTIME_TOKEN_KEY=$(openssl rand -hex 24) \
       REALTIME_API_KEY=$(openssl rand -hex 24) APP_MFA_KEY=$(openssl rand -base64 32)
docker compose up -d --build --wait                                     # http://localhost:18190
docker compose exec -T api php < scripts/gates/production-image.php     # what the image promises, checked inside it
docker compose down --volumes --rmi local                               # when done, in the same shell
```

This is § 8's second stack on the production image, so your own stack keeps running. `make seed` and `make
operator-code` work there too: seed it before signing in. The demo fixtures do not, because their bundle is a dev
package. CI's `prod-image` job runs the same check on every push.

A secret generated this way lives only in that shell. `APP_MFA_KEY` encrypts every authenticator secret stored, so a
stack restarted with another key cannot read them: keep the four values if the stack is to outlive the shell.
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
