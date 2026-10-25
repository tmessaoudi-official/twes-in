# Release 26 — tag, install and import, step by step

The brief of TODO.md §§ 3 to 7 for the first client (install Monday 2026-10-26). TODO.md keeps the checkboxes and the
details; this page is the order to follow.

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

## 3. Install on the shop's Windows computer

1. PowerShell: the build must be 22621 or more for route B, and no range listed may hold 80 or 443:
   ```powershell
   [Environment]::OSVersion.Version.Build
   netsh interface ipv4 show excludedportrange protocol=tcp
   ```
2. PowerShell as administrator, `D:` being the USB key, run again after each restart it asks for:
   - route A, Docker Desktop: `powershell -ExecutionPolicy Bypass -File D:\install.ps1`
   - route B, Docker inside Ubuntu, starting with nobody signed in: `powershell -ExecutionPolicy Bypass -File D:\install.ps1 -Engine Wsl`
3. `twes install` asks the Gmail address and its app password, who is alerted, the backups' folder, `yes` only if the
   fingerprint matches, then the operator's e-mail and password.
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
| 3 | `3-products.csv` | Produits › Importer › Produits | tick both: the default taxes, and unpriced products imported switched off | 4,100 created, about 75 s each step |
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
- The update window: `twes policy auto HH:MM-HH:MM`, 02:00–05:00 if the computer stays on at night, otherwise the hour
  after closing.
