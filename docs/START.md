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
| `centrifugo` | not published (reached through `web`)       | realtime updates between tabs                                  |

Ports come from `.env`. To change one, set it in your shell (`WEB_PORT=9090 make up`) or in a `.env.local` next to
`.env`. Only `web` and `api` listen on every interface. The rest listen on `127.0.0.1` only.

**Browse `http://localhost:8090`, not `127.0.0.1`**: passkeys are bound to `localhost` (`APP_WEBAUTHN_RP_ID`), and
the links in the mails point to `localhost`.

## 1. What to install

**To run the application only:** Docker with Compose v2, and `make`. Nothing else: PHP, Node and every dependency
are inside the images.

**To develop and run the checks** (`make gate`, `make e2e`): also PHP 8.5 with the extensions `pdo_pgsql intl zip
opcache bcmath`, Composer, and Node at the major in `web/.nvmrc`. Then, once and after every lock file change:

```sh
(cd api && composer install)
(cd web && npm ci)
(cd web && npx playwright install chromium)
```

**On this machine (`/stack`)** neither is on a fresh shell's PATH, and the first `php` found is phpbrew's
`php-master` (8.6-dev), which php-cs-fixer refuses. Put these first:

```sh
export PATH="$(echo /stack/tools/phpbrew/php/php-8.5.*/bin):$(echo /stack/tools/nvm/versions/node/v26.*/bin):$PATH"
```

The globs pick the one PHP 8.5 and Node 26 installed there (`ls /stack/tools/phpbrew/php/` shows them). After a major
or minor bump (`docs/UPDATE.md`), change the two numbers in the globs.

**If `npx playwright install chromium` hangs** (the IPv6 route to Google's storage is dead on this machine), install the
browser by hand:

1. Read the revision and version: `jq '.browsers[] | select(.name=="chromium")' web/node_modules/playwright-core/browsers.json`
2. Download both zips over IPv4:
   `curl -4 -fLO https://cdn.playwright.dev/builds/cft/<browserVersion>/linux64/chrome-linux64.zip` and the same for
   `chrome-headless-shell-linux64.zip`.
3. Unzip them into `~/.cache/ms-playwright/chromium-<revision>/` and `~/.cache/ms-playwright/chromium_headless_shell-<revision>/`,
   then `touch INSTALLATION_COMPLETE` in each of the two directories.

## 2. Bring it up

```sh
make up
```

This does three things, in order:

1. **Builds the images and starts every service** (`docker compose up -d --build --wait`). It waits until each
   one is healthy. The first build downloads and compiles everything (see § 9 for a measured time). Later builds
   reuse Docker's cache.
2. **Migrates the database.** The `api` container applies pending migrations every time it starts, before
   serving (`infra/api/docker-entrypoint.sh`). A failed migration stops the container instead of serving a
   half-migrated schema. You never run migrations by hand. `make migrate` exists for a running container.
3. **Seeds** (`make seed`, see § 4). This step is idempotent: running it again creates nothing new.

`make down` stops everything and **keeps** the data. `make up` again brings it back as it was. `make logs` follows
every service's output. `docker compose logs -f api` follows one.

**The images are built from your working tree, not from the last commit.** Uncommitted and untracked files under
`api/` and `web/` go into the build (`COPY api/ ./`). Half-finished code that does not compile yet fails `make up`,
`make reset` and a rebuild, usually at the api image's `cache:clear` step with the error of the file you are working
on. To bring up the committed code while work is in progress, use a separate checkout:
`git worktree add ../twes-in-clean HEAD`, then run `make up` there (with § 8's variables if your own stack is up).

**The web container serves a static build.** A change under `web/src/` is not visible until the image is rebuilt:
`docker compose up -d --build web`. An API change needs `docker compose up -d --build api`.

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

### One account per role

Each demo company also gets **one member of each built-in role**, so what a role may not do is something you can
sign in and meet rather than read about. All three share one password, and each holds the same role in both
companies:

| Role | Address | Password | What it may not do |
|---|---|---|---|
| `owner` | `owner@twes.local` | `twes-role-test-2026` | Nothing inside the company — it holds the wildcard `*`. It is never a platform operator. |
| `admin` | `admin@twes.local` | `twes-role-test-2026` | Grant the owner role, or remove an owner or another admin. |
| `member` | `member@twes.local` | `twes-role-test-2026` | Issue an invoice, record a payment, validate a delivery note, or see the members. Read-only on products, stock, vendors and expenses. |

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
make e2e              # Playwright against the running stack (make up first)
```

- **Stage new files before running the gates** (`git add`): the SPDX and executable-bit gates read `git ls-files`.
- After changing an API resource, run `cd api && bin/console cache:clear && bin/console cache:pool:clear --all`
  before `make gate-web`, or the OpenAPI export is stale.
- `make e2e` targets http://127.0.0.1:8090. For another port: `BASE_URL=http://127.0.0.1:<port> make e2e`.
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
| A web change does not show | The web image is a static build: `docker compose up -d --build web` (§ 2) |
| `make gate-web` fails on API types that CI accepts | Stale dev cache: `cd api && bin/console cache:clear && bin/console cache:pool:clear --all` |
| Every e2e scenario lands on `/two-factor` | Demo's two-step switch is on (§ 4) |
| e2e fails at `browserType.launch: Executable doesn't exist` | Playwright's browser is missing: § 1 |
| The api image fails at `cache:clear` ("Cannot autowire service …") | Work in progress in the working tree went into the build (§ 2). Finish it, or bring up a clean worktree |
| The api container restarts in a loop | A migration failed: `docker compose logs api` shows which, and the container refuses to serve until it passes |
| An API answer is wrong, slow or refused, and the log does not say why | Open the profiler, development only: <http://localhost:8091/_profiler> lists the last requests, and every answer carries an `X-Debug-Token-Link` header to its own. It shows which voter decided, the listeners in order and their time, every query and their count, and the timeline. Imports are not profiled (§ 7 of `docs/SPEC.md`, 2026-09-19) |

**Measured on this machine** (2026-09-19, a fresh compose project from a clean checkout of the committed tree):
`make reset CONFIRM=yes` to six healthy services and a seeded database took **104 s**, with the base images already
downloaded and the PHP extensions already compiled in Docker's cache. On a machine with neither, add the download
and compile time: the first attempt spent about 2 minutes on those alone.
