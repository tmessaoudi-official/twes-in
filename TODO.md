# TODO — the developer's own steps

What only the developer can do for the first client's release (`docs/plans/first-client.plan.md`, § Blocked). Tick a
box (`- [x]`) once a step is done; the steps run in this order, each needing the one before it.

## 1. Image publishing on — done by Claude

- [x] `PUBLISH_IMAGES=true` set as a repository variable. Check: `gh variable list --repo tmessaoudi-official/twes-in`.
- [x] CI's `publish` job green on the next push to master (it runs only on a push once the variable exists). Check:
      `gh run list --branch master --limit 1`, then the run's `publish` job. Green on fb8b63fc.

## 2. The three packages public — yours, cannot be undone

After step 1's `publish` job is green, open each link, then Danger Zone, Change visibility, Public, and type the
package's name:

- [x] https://github.com/users/tmessaoudi-official/packages/container/twes-in-api/settings
- [x] https://github.com/users/tmessaoudi-official/packages/container/twes-in-web/settings
- [x] https://github.com/users/tmessaoudi-official/packages/container/twes-in-database/settings

Public: each answered an anonymous pull of `:master` on 2026-10-24 17:36. Check, logged out (prints `public`):

```sh
docker logout ghcr.io; docker manifest inspect ghcr.io/tmessaoudi-official/twes-in-api:master >/dev/null && echo public
```

## 3. The first signed release — yours, your key signs it

After step 2, once CI is green on the release-26 batch (plan row 141), its `publish` job included: the tool tags the
commit you name, nothing is checked out (docs/RUNBOOK.md « Promote to production »). The date is the day you tag, `.1`
that day's first release: tagged on the 25th it is `v2026.10.25.1`, in both lines. The release is master's head as CI
published it; tag it before anything else is pushed. The tool pulls the three images, refuses what was not published
from that commit, signs and verifies:

```sh
cd /stack/projects/twes-in && git fetch origin && git log -1 --format='%H %s' origin/master
make release-tag TAG=v2026.10.25.1 COMMIT=$(git rev-parse origin/master)
git push origin v2026.10.25.1
```

To put staging's `:master` on it too (logged in to GHCR with write:packages; shops never read it):
`make point-master COMMIT=$(git rev-parse origin/master)`.

- Tag the release freeze or a later commit: it carries `twes hold` (671dd623). No shop, the J-1 machine included, is
  installed from `v2026.10.22.1` or any tag before it: a way back to such a release runs its own script, which knows no
  hold, so its restore runs unheld.
- [ ] Tagged: `git verify-tag` says « Good signature » from key `0C537690706EB7F5`.
- [ ] Pushed. A pushed tag is never moved.

## 4. Release tags protected — done by Claude

- [x] Tag ruleset « release tags » (id 25316818): `v*` cannot be created, updated or deleted except by a repository
      admin (you). Check: https://github.com/tmessaoudi-official/twes-in/settings/rules

## 5. The installer and the fingerprint to the shop — yours, out of band

```sh
cp /stack/projects/twes-in/scripts/onprem/install.ps1 /media/$USER/<your-usb>/install.ps1
```

- [ ] `install.ps1` on the USB key.
- [ ] The fingerprint given to the shop by message or call, never through the repository alone:
      `0201 4686 9960 09C7 41A9 2ADF 0C53 7690 706E B7F5`

## 6. The Windows test — yours, Sunday 25, from the tag of § 3

The shop's computer already holds the J-1 install of `v2026.10.22.1`: it is wiped first, never updated in place
(docs/release-26-install-day.md « Before § 3 »), then installed as below. Install day installs twice: a training
install, wiped the same way, then the final one (docs/release-26-install-day.md « The day's order »).

If it finds something Claude cannot fix the same day, install day stays Monday and it is fixed on site (ruled
2026-10-24 17:45): bring what failed, the command and its output. On install day, tell the owner that « À surveiller »
reads 4,013 after the import (measured on a company holding the five files, 2026-10-25): 3,974 products, every one
imported switched on (4,100 less the 126 without a price), still to have its unit confirmed and its stock counted, and
the 39 vendors with their « À vérifier » mark. It goes down as each product's unit is confirmed and its stock
counted (« Compter le stock »), not by an update: the update that left barcodes and categories out of it is already in
this release.

Two ways to run Docker on Windows; pick one at the shop (docs/START.md § 16 compares them):

- **6A, Docker Desktop**: the simpler install. Nothing runs before someone signs in to Windows; after a restart the shop
  opens once the account is signed in.
- **6B, Docker inside Ubuntu (no Docker Desktop)**: runs from Windows' start with nobody signed in, and comes back by
  itself after a restart, a Docker restart or a WSL stop. Needs Windows 11 22H2 or later. Its start before anyone signs
  in is untried: 6B's first check proves it or not.

First, on the Windows computer, in PowerShell (any window): the build must be 22621 or more for 6B, and no range listed
may hold 80 or 443 (both routes; the installer refuses it too):

```powershell
[Environment]::OSVersion.Version.Build
netsh interface ipv4 show excludedportrange protocol=tcp
```

Each route: after step 3, from the shop's own account, PowerShell opened as administrator (`D:\` is the USB key's letter).
Run it again after each stop it asks for. At `twes install`: the Gmail address and its app password, who is alerted, the
backups' folder (blank for this disk), `yes` only if the fingerprint is step 5's, then the operator's address and
password.

### 6A. Docker Desktop

```powershell
powershell -ExecutionPolicy Bypass -File D:\install.ps1
```

Its stops: a restart, Ubuntu opened once, Docker Desktop started with « Start Docker Desktop when you sign in » ticked.
Then restart, sign in, and check:

```powershell
Get-ScheduledTaskInfo -TaskPath '\twes-in\' -TaskName start | Select-Object LastRunTime, LastTaskResult
Get-ScheduledTaskInfo -TaskPath '\twes-in\' -TaskName tick  | Select-Object LastRunTime, LastTaskResult
wsl -d Ubuntu --cd ~ -- bash twes-in/scripts/onprem/twes status
```

- [ ] Installed to the end.
- [ ] Both tasks' `LastTaskResult` is `0`.
- [ ] `twes status` reads healthy, its backups due.
- [ ] `https://localhost` opens in Edge without a warning.
- [ ] A phone on the shop's network opens `http://<this computer's address>/root.crt` once, then the HTTPS address
      `twes status` names, and scans.
- [ ] Docker Desktop restarted (its tray icon, « Restart »): `twes status` healthy again within two minutes, nothing done.
- [ ] Once with `-HideWindows` (`powershell -ExecutionPolicy Bypass -File D:\install.ps1 -HideWindows`): no window shows
      when a task runs at the next quarter hour. Keep it only if so.

### 6B. Docker inside Ubuntu

Docker Desktop must not be installed (the installer refuses it; Settings > Apps uninstalls it).

```powershell
powershell -ExecutionPolicy Bypass -File D:\install.ps1 -Engine Wsl
```

Its stops: a restart, Ubuntu opened once. It stops WSL once or twice by itself (`.wslconfig`, then systemd), and
`twes install` asks Ubuntu's password once, for systemd's units. Then check:

```powershell
Get-ScheduledTaskInfo -TaskPath '\twes-in\' -TaskName keep | Select-Object LastRunTime, LastTaskResult
wsl -d Ubuntu -- systemctl is-active docker.service twes-in-tick.timer
wsl -d Ubuntu -- systemctl is-enabled twes-in-start.service
wsl -d Ubuntu --cd ~ -- bash twes-in/scripts/onprem/twes status
```

- [ ] Installed to the end.
- [ ] `keep`'s `LastTaskResult` is `267009` (it runs as long as Windows does).
- [ ] `active` twice, then `enabled`.
- [ ] `twes status` reads healthy, its backups due.
- [ ] `https://localhost` opens in Edge without a warning; a phone opens `http://<this computer's address>/root.crt`
      once, then the HTTPS address `twes status` names, and scans.
- [ ] Installed as an application: signed in, in Edge on `https://localhost`, the account menu offers « Installer
      l’application » and opens Edge's own dialog; twes-in then opens from the Start menu in a window of its own. On the
      phone, over the HTTPS address: Android's Chrome shows the home's card (« Installer »), an iPhone's Safari its two
      steps; it then opens from the home screen. Tell me what each did: no test here runs a real Edge or iPhone.
- [ ] **Restart Windows and do NOT sign in.** Wait three minutes, then the phone opens the HTTPS address. If it does not,
      sign in: it should open within three minutes then, and tell me which happened.
- [ ] Docker restarted: `wsl -d Ubuntu -u root -- systemctl restart docker`, then `twes status` healthy within two
      minutes, nothing done.
- [ ] WSL stopped: `wsl --shutdown`; the phone opens again within a quarter hour, by itself.
- [ ] A container stopped: `wsl -d Ubuntu -- bash -c 'docker stop $(docker ps -q --filter name=worker)'`; within a
      quarter hour `twes status` is healthy again and
      `wsl -d Ubuntu --cd ~ -- grep 'starting the installation again' .twes-in/state/twes.log` shows the line.

## 7. Install day — the shop's own data, in this order

Once § 6 is installed and healthy, at the shop, in Edge on `https://localhost`. The five files are this machine's
`var/claude/client-excel/out/` (never committed): copy the folder to the USB key with the installer. Each file is
previewed first; import it only when the preview says 0 refused.

1. **The demo off, and how to reach you.** Bring your phone with an authenticator app: an operator must carry a second
   factor, and `twes install` made yours with a password alone, so the first sign-in sends you to « Vérification en
   deux étapes » to scan its code and keep the ten recovery codes (in your password manager, never on the shop's computer).
   Then, signed in as the operator: `/platform`, card « Démo », untick « Démo
   ouverte aux visiteurs (« Essayer la démo ») », save. A shop's own computer offers no demo to a visitor (ruled
   2026-10-20 01:34). Then the card « Contact du support »: your phone, WhatsApp and e-mail, save.
   - [ ] Done; signed out, the sign-in page offers no « Essayer la démo ».
2. **The shop's company and its owner.** A shop's installation makes no company (docs/START.md § 5.1). Still the
   operator, `/platform`, « Entreprises »: the shop's name, country Tunisie, « Ouvrir l’entreprise » (it shows « En
   attente »); on its row, « Adresse du propriétaire »: the owner's e-mail, « Inviter un propriétaire ». The invitation
   leaves from the shop's Gmail; its link names this computer's network address, so the owner opens it in Edge on this
   computer (a phone only once it has fetched `http://<address>/root.crt`, § 6), follows the link, gives a name and a password
   of 12 characters or more. The company turns active once the owner accepts. Sign the operator out.
   - [ ] The invitation mail arrived (if not: `twes status`, then docs/START.md § 9, the row « … invitation mail never arrives »).
   - [ ] The owner signed in at `https://localhost` and sees the shop's company.
3. **The company's facts, the VAT regime and the stock, before any product.** Signed in as the owner: « Paramètres »,
   « Profil de la société »: matricule fiscal, address; « Régime de TVA »: « Régime normal » (ruled 2026-10-25 11:29:
   the shop is régime réel), then « Enregistrer ». The products file writes prices with taxes; the regime decides what
   is kept as the price before taxes, so it is set first. Then « Valeurs par défaut », section « Produits »: tick
   « Suivre le stock », « Enregistrer » (ruled 2026-10-19 12:01, D10; it is read for every product whenever it is
   asked, so ticking it later still reaches every product, but the counts below assume it ticked).
   - [ ] Facts and « Régime normal » saved. · [ ] « Suivre le stock » ticked.
4. **The five files, in order**, each from « Produits », « Importer » (the vendors' from « Fournisseurs »):

   | # | File | Screen | Switches | Expected |
   |---|---|---|---|---|
   | 1 | `1-vendors.csv` | Fournisseurs › Importer | none | 39 created |
   | 2 | `2-categories.csv` | Produits › Importer › Catégories | none | 2 created |
   | 3 | `3-products.csv` | Produits › Importer › Produits | tick both: « Donner les taxes par défaut aux nouveaux produits qui n’en nomment pas » and « Importer désactivés les nouveaux produits sans prix de vente » | 4,100 created, about 70 s to preview and as much to import |
   | 4 | `4-product-vendors.csv` | Produits › Importer › Fournisseurs des produits | none | 4,138 created, about 50 s to preview and as much to import |
   | 5 | `5-price-history.csv` | Produits › Importer › Historique des achats | none | 4,582 created, about 15 s to preview and as much to import; 67 lines say « date inconnue » for a day written 0202-09-30 |

   Keep every column the screen pairs, « À vérifier » (`to_check`) included: setting it aside drops the 1,873 marks
   the conversion left on what it could not read for sure. The history moves no cost, price or stock: it fills each
   product's « Historique des coûts » and each vendor's « Achats ». Its lines of no known day (the workbook gave none)
   are kept as « date inconnue », listed last.
   - [ ] 1 · [ ] 2 · [ ] 3 · [ ] 4 · [ ] 5, each 0 refused.
5. **What to tell the owner the same day.**
   - 126 products came without a sale price: they are imported switched off at price 0, so the counter does not offer
     them and a scan says « retiré de la vente ». « Produits », filter « Actif » set to « Inactif »: enter each price,
     then switch it on.
   - 1,655 products' prices before taxes were worked out at 19 % because the workbook gave no rate for them (each carries
     « TVA à vérifier »): for one sold at another rate, correct its tax, then type its price with taxes again, as the
     price kept before taxes is not worked out again by itself.
   - « À vérifier » on a product or a vendor is the conversion's doubt, said on the record: clear it once checked.

## 8. Release 28 — Wednesday 2026-10-28, the shop's first update

Every step is on `docs/release-28-update-day.md`, with its exact commands.

- [ ] Tagged once CI's run on the landed commit is green on every job, `publish` included (§ 1), and the tag pushed.
- [ ] At the shop: `twes update` ended « updated to v2026.10.DD.1 », and the checks of § 4 passed.
- [ ] The backup alert's two files sent (§ 5): its fix waits for them, ready on branch `bkp`.
- [ ] The field test's table filled and sent (§ 6): it decides whether the shop's installation gets a name.
- [ ] The « Nouveautés » shown to the staff (§ 7).

## Also waiting on you

- [ ] Once the final install is done (not the training one), whichever route: copy these five values off the shop's computer, into your password manager and a
  sealed copy offline. `twes install` printed them as it began, among much else. If the computer's disk dies, no backup
  can be read without the two passphrases, and every authenticator enrolled is lost without the MFA key. Print them in
  PowerShell, copy them, then close the window:

  ```powershell
  wsl -d Ubuntu --cd ~ -- grep -E '^(APP_MFA_KEY|BACKUP_DATABASE_PASSPHRASE|BACKUP_FILES_PASSPHRASE|APP_SECRET|POSTGRES_PASSWORD)=' .twes-in/install.env
  ```

- [ ] The restore drill with the person you named to restore the shop if you cannot (their name stays out of this
  repository): give them the sealed copy of the two backup passphrases, then run the drill together on this machine,
  never on the shop's data, `make backup-drill DRILL=local < /dev/null` (a shop's backup folder and off-site copy restored
  into empty volumes, its own compose project, removed at the end), reading `docs/RUNBOOK.md` « Restore a shop » and
  « Restore a lost shop machine » aloud as it goes, so they know each step before they ever need it.
- [ ] The client's Excel file, under `var/claude/client-excel/`, to map its columns into the importer (plan step 4).
- [ ] Read `var/claude/client-excel/out/report.md` (on this machine only, never committed): how each sheet of the
  workbook was read and every doubt left, then answer the questions Claude asks about it. The five files beside it are
  what the shop will import; nothing there is imported until you say so.
- [ ] Answer « does a configuration link make a record used? » (`docs/plans/first-client.plan.md`, § Needs input): it
  decides whether an imported vendor can be deleted or only merged.
- [ ] Read the Arabic screen texts on a running screen, or have a native reader do it: every Arabic word was written by
  Claude from `docs/i18n/ar-glossary.md` and none has been read by an Arabic speaker yet.
- [ ] Ask the shop: its opening hours, and whether its computer stays on at night. `twes install` asks them to place the
  daily update window (02:00 to 05:00 when it stays on, otherwise the hour after closing), which you may change there
  or later with `twes policy auto HH:MM-HH:MM`; from then on releases are applied by themselves inside it.
- [ ] The shop's Gmail account with two-step verification on, and an app password made for twes-in
  (Google Account › Security › App passwords): `twes install` asks for both; mail, documents to customers and the alerts
  sent to you leave from that address.
- [ ] A USB disk or second disk left plugged into the shop's computer for its local backups (without one, a dead disk
  loses everything), and a bucket with its key for the hourly off-site copy (a free Backblaze B2 or Cloudflare R2
  account will do; without one, a stolen or burnt computer loses everything, and `tick` mails you so every day): its
  values `BACKUP_S3_*` in the shop's values, then `twes offsite on` (`docs/START.md` § 16).
- [ ] Your monitor at home: a VM or device of its own, on a network segment that cannot reach the machine holding your
  release-signing key and GitHub credentials; a DDNS name pointing at it, ports 80 and 443 forwarded to it; then
  `scripts/monitor/new-monitor.sh` and `docs/START.md` § 15. Until it runs, problems reach you only by the shop's mail.
