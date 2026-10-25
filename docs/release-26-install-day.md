# Release 26 — tag, install and import, step by step

The brief of TODO.md §§ 3 to 7 for the first client (install Monday 2026-10-26). TODO.md keeps the checkboxes and the
details; this page is the order to follow.

## The day's order (ruled 2026-10-25 19:02, this release only)

1. Wipe the J-1 test install (« Before § 3 » below).
2. **The training install**: §§ 3, 4 and 5, the backups' folder left blank at § 3 step 3 and the five values not
   saved. The owner practises everything on their own company and files, invoices included. Nothing printed leaves the
   shop: those documents are wiped with the rest.
3. **Wipe again**: « Before § 3 » steps 3 and 4, before the first real sale.
4. **The final install**: §§ 3, 4 and 5 again, this time with the USB backups' folder and the five values saved, then
   §§ 6 and 7. From here on the shop's data is never wiped (§ 7).

Redone at the final install: Ubuntu's user, the fingerprint, Gmail (the same app password serves), your second factor
(remove the phone's old « twes-in » entry), the authority on Windows and the phones, the company, the owner's
invitation, the profile, « Suivre le stock » and the five imports. About an hour more, mostly downloads.

## 1. Tag and release (on the developer's machine)

```sh
cd /stack/projects/twes-in && git fetch origin
make release-tag TAG=v2026.10.25.1 COMMIT=172acb1adf590c4803d0b06066d363ecad07375f
git verify-tag v2026.10.25.1        # « Good signature » from key 0C537690706EB7F5
git push origin v2026.10.25.1
git push origin master              # only after the tag: the commits made after 172acb1a
```

172acb1a is the release: CI green on every job, `publish` included (run 40195134559), and `make onprem-check` passed on
it. Never tag 2ccf0bb7, whose CI was red. A pushed tag is never moved.

## 2. Before leaving for the shop

- `cp scripts/onprem/install.ps1 /media/$USER/<usb>/install.ps1`, and `var/claude/client-excel/out/` (the five files)
  onto the same key.
- The fingerprint to the shop by message or call, never through the repository alone:
  `0201 4686 9960 09C7 41A9 2ADF 0C53 7690 706E B7F5`.
- Bring a phone with an authenticator app, the shop's Gmail address and its app password, and a USB disk for the local
  backups. Ask whether the shop's computer stays on at night.

## Before § 3: the test installation already on the shop's computer

That computer runs the J-1 test, `v2026.10.22.1`, Docker inside Ubuntu (`-Engine Wsl`, 1db0d485), with the test's
data and secrets. It is wiped and installed again from `v2026.10.25.1`: never updated in place, never left to update
by itself. Kept, `v2026.10.22.1` stays the release a failed update rolls back to, and its own `twes` has no
`twes hold` and no repair of git's lock (TODO.md § 3). Waiting decides nothing either: an update happens only inside
the window, half an hour after a start, once a day; a computer off in its window never updates and nobody is told,
and one failed pull parks the release until someone runs `twes update` at the computer. This is the one wipe: it
holds no client data. Once the shop's data is in, no new version ever wipes it (§ 7).

1. **Only after § 1's `git push origin v2026.10.25.1`.** The installer checks out the newest tag; before the push it
   installs `v2026.10.22.1` again. The `install.ps1` on the key is this tree's: the one inside the tag `v2026.10.22.1`
   stops at its release step.
2. **Optional, about ten minutes: rehearse the update there first.** The update path has never run against GHCR on a
   shop's computer (docs/RUNBOOK.md « Release to the shops »), and this box is about to be wiped anyway:
   `wsl -d Ubuntu --cd ~ -- bash twes-in/scripts/onprem/twes update` must end « updated to v2026.10.25.1 », then
   `twes status` reads health ok. Whatever it says, note it and go on.
3. **Read what is there.** `wsl -d Ubuntu --cd ~ -- bash twes-in/scripts/onprem/twes status`: note its « backups to: »
   line. `wsl -l -v`: a `docker-desktop` line means Docker Desktop was tried too.
4. **Wipe**, in PowerShell as administrator:
   ```powershell
   Unregister-ScheduledTask -TaskPath '\twes-in\' -TaskName keep -Confirm:$false
   wsl --unregister Ubuntu
   Get-ChildItem Cert:\LocalMachine\Root | Where-Object Subject -like '*twes-in (cette installation)*' | Remove-Item
   ```
   - `keep` goes first: it starts Ubuntu as root every quarter hour, which would start the new Ubuntu before its user
     is made. `install.ps1` registers it again.
   - `wsl --unregister Ubuntu` erases the checkout, the values, Docker Engine and every volume inside it: the database,
     the files, the authority and the local backups. It cannot be undone.
   - If `docker-desktop` was listed: before `wsl --unregister Ubuntu`, also remove Docker Desktop's two tasks, which
     start Ubuntu every quarter hour as `keep` does:
     `Unregister-ScheduledTask -TaskPath '\twes-in\' -TaskName start -Confirm:$false` and the same for `tick`; then
     uninstall Docker Desktop (Settings > Apps; the installer refuses it beside `-Engine Wsl`), and `wsl --unregister`
     any `docker-desktop` line still listed.
   - The certificate line reads the name Caddy gives its root, worked out rather than read on that computer: look first
     with `Get-ChildItem Cert:\LocalMachine\Root | Where-Object Subject -like '*twes-in*'`; a name that does not match
     leaves a stale root behind, nothing worse.
   - If « backups to: » named a folder: it keeps the old installation's backups, encrypted under its old passphrases,
     and its `twes-in-backups.id`, which a new install adopts and then cannot back up into. Give the new install
     another, empty folder, or empty that one.
   - A phone that trusted the old `root.crt` drops it (Android: Settings, Security, Credentials; iPhone: Settings,
     General, VPN & Device Management) and fetches the new one; the authenticator's old « twes-in » entry goes once
     the new one is scanned; the five old secret values are void.
5. **Then § 3 from its step 2.** `install.ps1 -Engine Wsl` installs Ubuntu again (open it once, make its user and
   password), then Docker Engine, the checkout at `v2026.10.25.1`, `twes install` and `keep`; `.wslconfig` and the
   firewall rule stay as they are. `twes status` reads `release: v2026.10.25.1`; the footer's build line reads
   `2026.10.25.4`, `abfb8ca1` for the screens and `bd2b7ecd` for the API.

No screen updates the installation: « Mettre à jour maintenant » is ruled (SPEC § 7, 2026-10-20 01:01 and 01:34) and
waits as plan row 18, first after this release. Until then, at the computer: `twes status` says what runs, and
`twes update` takes the newest signed release at once, backed up first, outside the window too.

## 3. Install on the shop's Windows computer

1. PowerShell: the build must be 22621 or more for route B, and no range listed may hold 80 or 443:
   ```powershell
   [Environment]::OSVersion.Version.Build
   netsh interface ipv4 show excludedportrange protocol=tcp
   ```
2. PowerShell as administrator, `D:` being the USB key, run again after each restart it asks for:
   - route A, Docker Desktop: `powershell -ExecutionPolicy Bypass -File D:\install.ps1`
   - route B, Docker inside Ubuntu, starting with nobody signed in: `powershell -ExecutionPolicy Bypass -File D:\install.ps1 -Engine Wsl`
3. `twes install` asks, in this order: the shop's Gmail address and its app password; who is alerted (you); the
   backups' folder; whether the computer stays on at night (`no`: the shop's closing time), then the daily update
   window it proposes, Enter to take it; `yes` only if the fingerprint matches; the operator's e-mail and password,
   twice; under route B, Ubuntu's password once, for systemd's units. The backups' folder is a path inside Ubuntu, made
   before the question, or the install refuses it. For a USB disk lettered `E:`, first `wsl -d Ubuntu -- findmnt
   /mnt/e`: it must print a line (`drvfs` or `9p`). If it prints nothing, Ubuntu does not see the disk, and a folder
   made there would sit on the computer's own disk while you believe it on the USB: plug the disk in, `wsl --shutdown`,
   then `findmnt` again. Only once it prints: `wsl -d Ubuntu -- mkdir -p /mnt/e/twes-in-backups`, and answer
   `/mnt/e/twes-in-backups`. Still nothing: answer blank (this disk) and tell me; mounting the disk is not built yet.
   After the wipe above, a folder holding the test's backups is never given here.
4. Checks: `wsl -d Ubuntu --cd ~ -- bash twes-in/scripts/onprem/twes status` reads healthy; `https://localhost` opens in
   Edge without a warning; a phone opens `http://<the computer's address>/root.crt` once, then the HTTPS address
   `twes status` names.
5. The five secret values off the machine, into a password manager and a sealed copy offline:
   ```powershell
   wsl -d Ubuntu --cd ~ -- grep -E '^(APP_MFA_KEY|BACKUP_DATABASE_PASSPHRASE|BACKUP_FILES_PASSPHRASE|APP_SECRET|POSTGRES_PASSWORD)=' .twes-in/install.env
   ```

## 4. The shop's set-up, in Edge on `https://localhost`

1. **The operator.** The first sign-in sends you to « Vérification en deux étapes »: scan its code with your phone and
   keep the ten recovery codes in your password manager. Then `/platform`: card « Démo », untick « Démo ouverte aux
   visiteurs », save; card « Contact du support », your phone, WhatsApp and e-mail, save.
2. **The company and its owner.** Still the operator, « Entreprises »: the shop's name, Tunisie, « Ouvrir
   l’entreprise »; on its row, the owner's e-mail, « Inviter un propriétaire ». The owner opens the invitation in Edge
   on this computer (its link names the computer's network address), gives a name and a password of 12 characters or
   more. Sign the operator out.
3. **The owner, before any import.** « Paramètres », « Profil de la société »: matricule fiscal, address, « Régime de
   TVA »: « Régime normal », « Enregistrer ». Then « Valeurs par défaut », section « Produits »: tick « Suivre le
   stock », « Enregistrer ».

## 5. The client's data, as the owner, in this order

Each file: « Prévisualiser » first, then « Importer » only when the preview says 0 refused. Keep every column the screen
pairs, « À vérifier » included.

| # | File | Screen | Switches | Expected |
|---|---|---|---|---|
| 1 | `1-vendors.csv` | Fournisseurs › Importer | none | 39 created |
| 2 | `2-categories.csv` | Produits › Importer › Catégories | none | 2 created |
| 3 | `3-products.csv` | Produits › Importer › Produits | tick both: the default taxes, and unpriced products imported switched off | 4,100 created, about 70 s each step |
| 4 | `4-product-vendors.csv` | Produits › Importer › Fournisseurs des produits | none | 4,138 created, about 50 s each step |
| 5 | `5-price-history.csv` | Produits › Importer › Historique des achats | none | 4,582 created, about 15 s each step; 67 lines « date inconnue » |

Reload the page after an import: a product's cost history and a vendor's « Achats » do not refresh by themselves.

## 6. What to tell the owner the same day

- « À surveiller » reads 4,013; it goes down as each product's unit is confirmed and its stock counted (« Compter le
  stock »).
- 126 products came without a price: « Produits », filter « Inactif », enter each price, then switch it on.
- 1,655 prices before taxes were worked out at 19 % (each marked « TVA à vérifier »): for one sold at another rate,
  correct its tax, then type its price with taxes again.
- « À vérifier » is the conversion's doubt on a record: clear it once checked.

## 7. Before leaving the shop

- A USB disk stays plugged in for the local backups.
- An off-site bucket (Backblaze B2 or Cloudflare R2): its `BACKUP_S3_*` values in the shop's values, then
  `twes offsite on`.
- The update window `twes install` set: `twes status`, line « updates ». A computer switched off at closing never
  reaches an hour after closing, and then no release is ever applied, with nobody told: ask the owner to leave it on at
  night (`twes policy auto 02:00-05:00`), or take each release by hand on a visit (`twes update`).
- From now on the shop's data is never wiped to take a new version (plan, AGREED 2026-10-25 17:59): every release
  comes through `twes update`, which backs up first, migrates in place and rolls back an unhealthy release.
