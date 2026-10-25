# Release 26 — install day (Monday 2026-10-26)

`twes X` below means, in PowerShell: `wsl -d Ubuntu --cd ~ -- bash twes-in/scripts/onprem/twes X`.

## The day's order (this release only)

1. Wipe the test install (« Before § 3 »).
2. **Training install**: §§ 3–5, backups folder left blank, the five values not saved. The owner practises on their own
   company and files. Nothing printed leaves the shop; it mails through the real Gmail, so customers typed are made up
   or carry the shop's own address. Its 2FA recovery codes die with it.
3. **Wipe again** (« Before § 3 » steps 3–4) before the first real sale.
4. **Final install**: §§ 3–5 with the USB backups folder and the five values saved, then §§ 6–7. Never wiped again.
   About an hour more (Ubuntu user, Gmail, your 2FA, the certificate on the PC and phones, company, owner, imports).

## 1. Tag (on the developer's machine)

Wait for CI run 40219836615 on 73fd903 green on every job, `publish` included (`gh run view 40219836615`). Its code is
172acb1a's (only docs and TODO.md differ), whose CI and `make onprem-check` passed. Never tag 2ccf0bb7.

```sh
cd /stack/projects/twes-in && git fetch origin
make release-tag TAG=v2026.10.25.1 COMMIT=73fd9039fcadd265c0889c7c5d4dca1c5720ed43
git verify-tag v2026.10.25.1        # « Good signature » from key 0C537690706EB7F5
git push origin v2026.10.25.1       # a pushed tag never moves
git push origin master              # after the tag: the commits made after 73fd903
```

## 2. Before leaving

USB key (`<usb>` = the key under `/media/$USER/`), filled after § 1:

| On the key | Copied with |
|---|---|
| `install.ps1` | `git show v2026.10.25.1:scripts/onprem/install.ps1 > /media/$USER/<usb>/install.ps1` |
| this page | `cp docs/release-26-install-day.md /media/$USER/<usb>/` (Notepad opens it) |
| `client/`, five CSVs | `mkdir -p /media/$USER/<usb>/client && cp var/claude/client-excel/out/*.csv /media/$USER/<usb>/client/` |

- `ls -l /media/$USER/<usb> /media/$USER/<usb>/client`: three entries, five files. Then `sync`.
- Nothing else: not `report.md`. The key holds the shop's prices: keep it on you, empty it after the final install.
- Send the fingerprint to the shop by message or call: `0201 4686 9960 09C7 41A9 2ADF 0C53 7690 706E B7F5`.
- Bring: a phone with an authenticator app, the shop's Gmail and its app password, a USB disk for backups.
- Ask whether the shop's computer stays on at night.

## Before § 3: the test install on the shop's computer

It runs `v2026.10.22.1` (`-Engine Wsl`). It is wiped and installed again, never updated in place.

1. **Only after the tag push** (the installer takes the newest tag), with the key's `install.ps1`.
2. Optional, ~10 min: `twes update` must end « updated to v2026.10.25.1 ». Note the result and go on.
3. `twes status`: note « backups to: ». `wsl -l -v`: a `docker-desktop` line means Docker Desktop was tried too.
4. **Wipe**, PowerShell as administrator (cannot be undone: database, files, values, local backups):
   ```powershell
   Unregister-ScheduledTask -TaskPath '\twes-in\' -TaskName keep -Confirm:$false
   wsl --unregister Ubuntu
   Get-ChildItem Cert:\LocalMachine\Root | Where-Object Subject -like '*twes-in (cette installation)*' | Remove-Item
   ```
   - `docker-desktop` listed: before `wsl --unregister Ubuntu`, also run
     `Unregister-ScheduledTask -TaskPath '\twes-in\' -TaskName start -Confirm:$false` and the same with `tick`;
     uninstall Docker Desktop (Settings > Apps); `wsl --unregister` each `docker-desktop` line left.
   - Certificate: look first with `Get-ChildItem Cert:\LocalMachine\Root | Where-Object Subject -like '*twes-in*'`.
   - The old « backups to: » folder is never given to the new install: use a new, empty one.
   - Phones: remove the old certificate (Android: Settings › Security › Credentials; iPhone: Settings › General ›
     VPN & Device Management) and the old « twes-in » authenticator entry. The five old secret values are void.
5. Then § 3 from step 2. Expect `twes status` « release: v2026.10.25.1 », footer `2026.10.25.4 abfb8ca1` (screens) and
   `2026.10.25.4 bd2b7ecd` (API).

No screen updates the installation yet (plan row 18, first update): `twes status` and `twes update` at the computer.

## 3. Install on Windows

1. PowerShell: build 22621 or more for route B, and no range listed may hold 80 or 443:
   ```powershell
   [Environment]::OSVersion.Version.Build
   netsh interface ipv4 show excludedportrange protocol=tcp
   ```
2. PowerShell as administrator, `D:` = the key, again after each restart it asks for (when told, open Ubuntu once
   from the Start menu and make its user):
   - route A, Docker Desktop: `powershell -ExecutionPolicy Bypass -File D:\install.ps1`
   - route B, Docker inside Ubuntu, runs with nobody signed in: `powershell -ExecutionPolicy Bypass -File D:\install.ps1 -Engine Wsl`
3. `twes install` asks, in order: Gmail and app password · who is alerted (you) · backups folder · computer on at night
   (`no` → closing time), then the update window (Enter) · fingerprint (`yes` only if it matches) · operator e-mail and
   password, twice · route B: Ubuntu's password.

   Backups on a USB disk `E:` (final install only):
   ```powershell
   wsl -d Ubuntu -- findmnt /mnt/e                      # must print a line (drvfs or 9p)
   wsl -d Ubuntu -- mkdir -p /mnt/e/twes-in-backups     # then answer /mnt/e/twes-in-backups
   ```
   `findmnt` prints nothing: replug, `wsl --shutdown`, retry. Still nothing: answer blank and tell the developer.
4. Check: `twes status` healthy; `https://localhost` opens in Edge with no warning; a phone opens
   `http://<computer's address>/root.crt` once, then the HTTPS address `twes status` names.
5. Save the five secret values (password manager and a sealed offline copy):
   ```powershell
   wsl -d Ubuntu --cd ~ -- grep -E '^(APP_MFA_KEY|BACKUP_DATABASE_PASSPHRASE|BACKUP_FILES_PASSPHRASE|APP_SECRET|POSTGRES_PASSWORD)=' .twes-in/install.env
   ```

## 4. Set-up, in Edge on `https://localhost`

1. **Operator**: scan the 2FA code with your phone, keep the ten recovery codes. `/platform`: « Démo », untick « Démo
   ouverte aux visiteurs », save; « Contact du support », your phone, WhatsApp, e-mail, save.
2. **Company**: « Entreprises », the shop's name, Tunisie, « Ouvrir l’entreprise »; on its row, the owner's e-mail,
   « Inviter un propriétaire ». The owner opens the invitation in Edge on this computer, name and password (12+
   characters). Sign the operator out.
3. **Owner, before any import**: « Paramètres › Profil de la société »: matricule fiscal, address, « Régime de TVA »
   « Régime normal », « Enregistrer ». « Valeurs par défaut › Produits »: tick « Suivre le stock », « Enregistrer ».

## 5. Imports, as the owner, in this order

Each file from the key (`D:\client\…`): « Prévisualiser », then « Importer » only at 0 refused. Keep every column paired.

| # | File | Screen | Switches | Expected |
|---|---|---|---|---|
| 1 | `1-vendors.csv` | Fournisseurs › Importer | none | 39 |
| 2 | `2-categories.csv` | Produits › Importer › Catégories | none | 2 |
| 3 | `3-products.csv` | Produits › Importer › Produits | tick both (default taxes; unpriced switched off) | 4,100, ~70 s per step |
| 4 | `4-product-vendors.csv` | Produits › Importer › Fournisseurs des produits | none | 4,138, ~50 s per step |
| 5 | `5-price-history.csv` | Produits › Importer › Historique des achats | none | 4,582, ~15 s per step; 67 « date inconnue » |

Reload the page after an import to see cost histories and a vendor's « Achats ».

## 6. Tell the owner

- « À surveiller » reads 4,013; it falls as units are confirmed and stock counted (« Compter le stock »).
- 126 products have no price: « Produits », filter « Inactif », enter the price, switch on.
- 1,655 prices were worked out at 19 % (« TVA à vérifier »): for another rate, fix the tax, retype the price with taxes.
- « À vérifier » marks a doubt from the conversion: clear it once checked.

## 7. Before leaving the shop

- The USB backups disk stays plugged in.
- Off-site bucket (Backblaze B2 or Cloudflare R2): its `BACKUP_S3_*` values, then `twes offsite on`.
- `twes status`, line « updates »: a computer off at night never updates and nobody is told. Leave it on
  (`twes policy auto 02:00-05:00`) or run `twes update` on each visit.
- From now on the shop's data is never wiped: every release comes through `twes update` (backup, migrate, rollback).
