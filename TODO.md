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

- [ ] https://github.com/users/tmessaoudi-official/packages/container/twes-in-api/settings
- [ ] https://github.com/users/tmessaoudi-official/packages/container/twes-in-web/settings
- [ ] https://github.com/users/tmessaoudi-official/packages/container/twes-in-database/settings

Check, logged out (prints `public`):

```sh
docker logout ghcr.io; docker manifest inspect ghcr.io/tmessaoudi-official/twes-in-api:master >/dev/null && echo public
```

## 3. The first signed release — yours, your key signs it

After step 2, on the commit CI published (the date is the day you tag; `.1` is that day's first release):

```sh
cd /stack/projects/twes-in
git fetch origin && git switch master && git pull --ff-only
bash scripts/release/tag-release.sh ghcr.io/tmessaoudi-official v2026.10.19.1
git verify-tag v2026.10.19.1
git push origin v2026.10.19.1
```

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

## 6. The Windows test — yours, Monday or Tuesday

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
- [ ] **Restart Windows and do NOT sign in.** Wait three minutes, then the phone opens the HTTPS address. If it does not,
      sign in: it should open within three minutes then, and tell me which happened.
- [ ] Docker restarted: `wsl -d Ubuntu -u root -- systemctl restart docker`, then `twes status` healthy within two
      minutes, nothing done.
- [ ] WSL stopped: `wsl --shutdown`; the phone opens again within a quarter hour, by itself.
- [ ] A container stopped: `wsl -d Ubuntu -- bash -c 'docker stop $(docker ps -q --filter name=worker)'`; within a
      quarter hour `twes status` is healthy again and
      `wsl -d Ubuntu --cd ~ -- grep 'starting the installation again' .twes-in/state/twes.log` shows the line.

## Also waiting on you

- [ ] Once installed, whichever route: copy these five values off the shop's computer, into your password manager and a
  sealed copy offline. `twes install` printed them as it began, among much else. If the computer's disk dies, no backup
  can be read without the two passphrases, and every authenticator enrolled is lost without the MFA key. Print them in
  PowerShell, copy them, then close the window:

  ```powershell
  wsl -d Ubuntu --cd ~ -- grep -E '^(APP_MFA_KEY|BACKUP_DATABASE_PASSPHRASE|BACKUP_FILES_PASSPHRASE|APP_SECRET|POSTGRES_PASSWORD)=' .twes-in/install.env
  ```

- [ ] The restore drill with the person you named to restore the shop if you cannot (their name stays out of this
  repository): give them the sealed copy of the two backup passphrases, then run the drill together on this machine,
  never on the shop's data, `make backup-drill DRILL=local < /dev/null` (a shop's backup folder and off-site copy restored
  into empty volumes, its own compose project, removed at the end), reading `docs/RUNBOOK.md` « Restore a shop » aloud as
  it goes, so they know each step before they ever need it.
- [ ] The client's Excel file, under `var/claude/client-excel/`, to map its columns into the importer (plan step 4).
- [ ] Read `var/claude/client-excel/out/report.md` (on this machine only, never committed): how each sheet of the
  workbook was read and every doubt left, then answer the questions Claude asks about it. The five files beside it are
  what the shop will import; nothing there is imported until you say so.
- [ ] Answer « does a configuration link make a record used? » (`docs/plans/first-client.plan.md`, § Needs input): it
  decides whether an imported vendor can be deleted or only merged.
- [ ] Read the Arabic screen texts on a running screen, or have a native reader do it: every Arabic word was written by
  Claude from `docs/i18n/ar-glossary.md` and none has been read by an Arabic speaker yet.
- [ ] Ask the shop: its opening hours, and whether its computer stays on at night. `twes install` asks them to place the
  nightly update window (02:00 to 05:00 when it stays on, otherwise an hour after closing).
- [ ] The shop's Gmail account with two-step verification on, and an app password made for twes-in
  (Google Account › Security › App passwords): `twes install` asks for both; mail, documents to customers and the alerts
  sent to you leave from that address.
- [ ] A USB disk or second disk left plugged into the shop's computer for its local backups, and, if you want the off-site
  copy, a bucket with its key (`docs/START.md` § 16).
- [ ] Your monitor at home: a VM or device of its own, on a network segment that cannot reach the machine holding your
  release-signing key and GitHub credentials; a DDNS name pointing at it, ports 80 and 443 forwarded to it; then
  `scripts/monitor/new-monitor.sh` and `docs/START.md` § 15. Until it runs, problems reach you only by the shop's mail.
- [ ] On the shop's installation, signed in as the shop's owner, « Paramètres » (the gear), « Valeurs par défaut »,
  section « Produits »: tick « Suivre le stock », then « Enregistrer ». A new company keeps no stock until it is ticked:
  an order received and an invoice issued move nothing (the trade chosen at sign-up, which would tick it for a shop, is
  not built yet).
- [ ] On the shop's installation, signed in as its operator, `/platform`, card « Contact du support »: your phone, WhatsApp
  and e-mail. They are what « Aide » shows under « Nous contacter » on every screen; left empty, the shop sees no way to
  reach you.
