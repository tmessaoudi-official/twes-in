# Release 28 — update day (Wednesday 2026-10-28)

`twes X` below means, in PowerShell: `wsl -d Ubuntu --cd ~ -- bash twes-in/scripts/onprem/twes X`.

The shop runs `v2026.10.25.1`. This release is taken by `twes update`, which backs up first and starts the previous
release again by itself if the new one does not answer. Its migrations only add columns and tables, so the previous
release still runs on the new schema (`git diff --stat v2026.10.25.1 <the tag> -- api/migrations`: added files only).

## 1. Tag (on the developer's machine)

CI run 40441554218 on 8d01e6dc0f3d122d8351d3b50e9f22acceaa96c8 is green on every job, `publish` included
(`gh run view 40441554218`): the tag names the three images that job published. A later commit on master changes
nothing for the shop unless it says so here.

```sh
cd /stack/projects/twes-in && git fetch origin
make release-tag TAG=v2026.10.DD.1 COMMIT=8d01e6dc0f3d122d8351d3b50e9f22acceaa96c8   # DD: the day you tag
git verify-tag v2026.10.DD.1                     # « Good signature » from key 0C537690706EB7F5
git push origin v2026.10.DD.1                    # a pushed tag never moves
```

If the shop's policy is `auto`, it takes the release in its night window after the push; otherwise § 3 takes it.

## 2. Before leaving

- This page on the phone or the USB key (`cp docs/release-28-update-day.md /media/$USER/<usb>/`).
- A phone on the shop's Wi-Fi for § 6 (an Android if the shop's staff use Android).
- Nothing else: no installer, no fingerprint (already pinned at install).

## 3. At the shop: the update (about 10 minutes)

1. `twes status`: note the version it runs and « backups to: ». Already on the new tag (the night window took it): go to § 4.
2. `twes update`. It says each step and its expected time, and must end « updated to v2026.10.DD.1 ».
3. It ends « … is unhealthy (…): starting v2026.10.25.1 again » instead: nothing is lost, the shop works on the
   previous release. Copy the whole output (and § 5's log) and send it; do not retry by hand.
4. It refuses with « Mise à jour annulée : la sauvegarde d'avant n'a pas été faite »: the backup folder is missing (the
   USB disk unplugged?). Plug it in, `twes status`, then `twes update` again.

## 4. Checks after the update (5 minutes)

- The footer of any page shows the new web and API builds.
- Sign in on the shop's computer and on a phone; open a delivery note, an invoice, « À surveiller ».
- `twes status` reads healthy, backups due.

## 5. The backup alert (« Les sauvegardes ne tournent plus »)

Its fix is ready but held until its cause is read from the shop's own output (docs/SPEC.md § 7, 2026-10-26 18:46). Send
both files below; if they arrive before the tag, the fix is diagnosed and lands in this release, otherwise in the next.

```powershell
wsl -d Ubuntu --cd ~ -- bash -c 'bash twes-in/scripts/onprem/twes status > ~/twes-status.txt 2>&1'
wsl -d Ubuntu --cd ~ -- bash -c 'LAN_HOST=$(cat ~/.twes-in/state/lan-host) bash twes-in/scripts/deploy/deploy.sh --compose ~/.twes-in/install.env logs --since 168h --timestamps backup > ~/backup-log.txt 2>&1'
```

Both files are in Windows' Explorer under `\\wsl$\Ubuntu\home\<the Ubuntu user>\`. Also say whether the computer
sleeps or is switched off at night.

## 6. Field test: a name for the shop's installation (about 30 minutes)

Ruled 2026-10-27 00:52–01:03: one permanent name on the shop's own network, no domain bought, built only if this test
says it works here. Nothing is installed or changed; every step is a page opened. Note each answer in the table.

**Before**: the computer's network address and name.

- Address: `wsl -d Ubuntu --cd ~ -- cat .twes-in/state/lan-host` (e.g. `192.168.1.20`); written with dashes below
  (`192-168-1-20`).
- Name: `hostname` in PowerShell (e.g. `DESKTOP-ABC123`).
- The phone on the shop's Wi-Fi (not 4G), and on Android: Settings > Network > « DNS privé » noted (off / automatic /
  a name).

| # | On | Open | Works when | What it tells |
|---|---|---|---|---|
| 1 | the phone | `http://192-168-1-20.sslip.io/root.crt` (your dashes) | a certificate file downloads or is offered | the router passes a public name's private answer: a free dynamic-DNS name can work here |
| 2 | the shop's computer, Edge | the same address | the same | the same, through the computer's own DNS |
| 3 | the phone | `http://DESKTOP-ABC123.local/root.crt` (its name) | the file downloads | the phone finds the computer by its `.local` name, and Windows answers to it |
| 4 | the shop's computer, PowerShell | `ping -4 DESKTOP-ABC123.local` | it answers from the address above | Windows answers to its own `.local` name |
| 5 | another phone, if any (iPhone or another Android) | test 3 again | the same | whether `.local` holds across the staff's phones |

**Also note**: the router's brand and model (the label under it), whether its admin page is reachable
(`http://192.168.1.1` or the address on the label) and offers a « réservation DHCP » / « bail statique », and the
owner's answer to « how often does the internet drop, and for how long ». (The installation does not log outages yet:
that comes later through `twes update`.)

**Reading it**: 1 and 2 work → a free dynamic-DNS name (no certificate step on phones, passkeys on phones). 1 fails, 3
works → `twes-<shop>.local`. Both fail → today's address stays, with a QR code to connect a phone. A « can't be
reached » on 1 with « DNS privé » on a name: repeat with it set to automatic before concluding.

## 7. Nouveautés — à montrer à la boutique

**« Reçu par » : qui a reçu la marchandise**

- Sur un **bon de livraison**, à « Livrer » ou « Valider et livrer » : choisir un contact du client, ou taper un nom
  (cocher « Ajouter à ses contacts » pour le retrouver la prochaine fois). Nommé avant la validation, le nom s'imprime
  sur le bon ; nommé à « Livrer », il s'imprime sur la « Copie — livrée ».
- Sur une **facture** qui remet elle-même la marchandise (sans bon de livraison) : le même champ, sur le brouillon.
- Sur un **devis** : à « Marquer accepté » ou « Marquer refusé », qui a répondu pour le client.
- Sur une **réception de commande fournisseur** : qui, dans la boutique, a reçu la marchandise (un membre ou un nom).
- Un nom faux se corrige : « ⋮ » puis « Corriger qui a reçu… » (sur une réception, le bouton « Corriger qui a reçu… »
  sous la réception). Le message qui suit propose « Annuler ». Le bon ou la facture déjà émis gardent le nom imprimé
  d'origine ; la page et la copie à jour disent le nom corrigé. Un devis n'imprime pas qui a répondu.
- Dans les listes, « Reçu par » est une colonne : celle des factures s'affiche à la demande (« Colonnes »), comme le
  total ; sur un petit écran, celles des devis, bons et commandes laissent la place aux autres.
- **Paramètres** : pour chaque document, « Jamais », « Si on le souhaite » (par défaut) ou « Toujours ». Le même réglage
  se fait par groupe de clients et par client, onglet « Valeurs par défaut ».

**Les rôles**

- Une page qu'un rôle ne peut pas ouvrir le dit (« Votre rôle dans … ne permet pas d'ouvrir … ») et propose le retour
  à l'accueil, au lieu d'échouer ; de même pour un module désactivé, et pour l'abonnement (seul le propriétaire voit
  « Voir l'abonnement », les autres sont invités à le prévenir).
- La page « Membres » ne propose plus à un administrateur ce qu'il ne peut pas faire : rien sur la ligne d'un
  propriétaire, et seulement les rôles qu'il peut donner.
- « À surveiller », son compteur et les rapports suivent tout de suite un changement de rôle, sans recharger la page.
- Un bon de livraison qu'un employé ne peut pas valider le dit : « Un responsable valide ce bon : il le retrouve dans
  « À surveiller ». » Le responsable le voit sous « Bons à valider » deux heures après le dernier enregistrement du
  brouillon (pas tout de suite).

**Abonnement et compte**

- Une entreprise fermée en attendant le règlement : son propriétaire ouvre encore « Mon compte » (mot de passe,
  appareils, suppression du compte) et peut fermer l'entreprise depuis la page d'attente ; les moyens de joindre la
  plateforme y sont affichés.

**Divers**

- Un bon facturé avant le départ de la marchandise dit « Livraison non enregistrée ».
- Le code d'appairage d'un téléphone (scanner) dit quand il a expiré.
- Un contact ajouté par un collègue apparaît tout de suite, sur la fiche client comme dans un brouillon ouvert.
- « Ajouter à ses contacts » n'est plus proposé pour un nom qui y est déjà.
