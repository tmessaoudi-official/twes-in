# TODO — the developer's own steps

What only the developer can do for the first client's release (`docs/plans/first-client.plan.md`, § Blocked). Tick a
box (`- [x]`) once a step is done; the steps run in this order, each needing the one before it.

## 1. Image publishing on — done by Claude

- [x] `PUBLISH_IMAGES=true` set as a repository variable. Check: `gh variable list --repo tmessaoudi-official/twes-in`.
- [ ] CI's `publish` job green on the next push to master (it runs only on a push once the variable exists). Check:
      `gh run list --branch master --limit 1`, then the run's `publish` job.

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

After step 3, on the Windows computer, from the shop's own account, PowerShell opened as administrator (`D:\` is the USB
key's letter):

```powershell
powershell -ExecutionPolicy Bypass -File D:\install.ps1
```

Run it again after each stop it asks for (a restart, Ubuntu opened once, Docker Desktop started with « Start Docker
Desktop when you sign in » ticked). At `twes install`: the Gmail address and its app password, who is alerted, the
backups' folder (blank for this disk), `yes` only if the fingerprint is step 5's, then the operator's address and
password. Then restart, sign in, and check:

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
- [ ] Once with `-HideWindows` (`powershell -ExecutionPolicy Bypass -File D:\install.ps1 -HideWindows`): no window shows
      when a task runs at the next quarter hour. Keep it only if so.

## Also waiting on you

- [ ] The client's Excel file, under `var/claude/client-excel/`, to map its columns into the importer (plan step 4).
