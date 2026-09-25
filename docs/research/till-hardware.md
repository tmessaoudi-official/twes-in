# Till hardware from the twes-in web app — research, 2026-09-25

Question: can the browser app drive a physical till (screen, scanner, cash drawer, receipt printer, customer
display, card terminal) the way French and Tunisian till software does? Grades: `[V: n]` = Verified by reading
source n (list at the end); `[I]` = Inferred, with the reason; `[U]` = Unverified, with the reason.

## Summary

**Yes for the printer, drawer, customer display and scanner, on Chrome or Edge, with no local agent and no vendor SDK.**
Card payment: only through a card provider's *cloud* API (France), and not at all in Tunisia.

| Need | Smallest route that works | Browser | What we'd ship | Licence verdict |
|---|---|---|---|---|
| Print a receipt | Network Epson (ePOS-Print XML, an HTTP POST to the printer) **or** USB printer through WebUSB/Web Serial with ESC/POS bytes | Chrome/Edge (≥142 for the LAN POST from an HTTPS page) | our own XML/ESC-POS code, or `@point-of-sale/*` | MIT — OK [V: 14,15] |
| Open the cash drawer | RJ11/RJ12 drawer plugged into the printer; `<pulse>` (ePOS XML) or `ESC p` (ESC/POS) | same | same | same |
| Customer display | the existing second browser window (BroadcastChannel); a pole display only through the printer or Web Serial | all browsers for the window | nothing new | n/a |
| Scanner | keyboard wedge (done) + camera (done, zxing-wasm) | all | nothing new | already ruled |
| Card terminal, France | Stripe Terminal (server-driven), SumUp Cloud API (Solo), Adyen cloud Terminal API — the **server** calls the provider, the terminal shows the amount | any | server-side PHP calls, no browser SDK needed | Stripe/Adyen libs MIT, SumUp Apache-2.0 [V: 14] |
| Card terminal, France, bank TPE (Ingenico/Verifone via Caisse-AP/Concert) | **not from a browser**: raw TCP/serial socket; needs a local agent | none | — | — |
| Card terminal, Tunisia | **no integration surface found**: stand-alone bank TPE, cashier keys the amount, twes-in records "card"; Konnect/Flouci QR for wallet/e-DINAR/card payments | any | server-side calls | n/a |
| Local agent (QZ Tray) | avoid | — | — | **LGPL-2.1-only → refused**; free build shows "Untrusted website" on every job [V: 5,6] |
| Star webPRNT / CloudPRNT SDK / StarXpand | avoid the SDKs; the protocols may be implemented from docs | — | — | **proprietary EULA, no redistribution, no derivatives → refused** [V: 9,10] |
| Epson ePOS SDK for JavaScript (`epos-2.x.js`) | not needed: XML over HTTP is documented | — | — | EULA not read [U: EULA.en.txt only in the SDK zip] |
| Sunmi / iMin JS SDKs | only on their Android devices | — | — | no licence text found → needs a ruling [U] |

**Impossible from a browser:** raw TCP/serial to a bank TPE (Caisse-AP), classic-Bluetooth (SPP) printers, silent
printing through the OS driver without a Chrome launch flag, anything on Firefox/Safari except the plain network
and window routes, WebUSB on Windows to a printer whose vendor driver is installed.

## 1. Opening a cash drawer

**How drawers open.** A cash drawer has no brains: a solenoid fired by a pulse on the printer's RJ11/RJ12
"drawer kick-out" port. Epson's ESC/POS command is `ESC p m t1 t2` (`1B 70 m t1 t2`): *"Outputs pulse to Drawer
kick-out port. m = 0: connector pin 2, m = 1: connector pin 5; t1: on time (×2 ms), t2: off time (×2 ms)"*; the
real-time variant is `DLE DC4 1 m t`, and pin 3 reports drawer open/closed through `DLE EOT` and ASB status [V: 2]
(Epson's own reference page answered 403; the quoted text is Epson's TM-T20 quick-reference PDF). In ePOS-Print XML
the same thing is `<pulse drawer="drawer_1" time="pulse_100"/>`, drawer_1 = pin 2, drawer_2 = pin 5, 100–500 ms [V: 3].
Drawers are sold at 12 V or 24 V; buy the voltage the printer's port supplies [U: listings seen only as search snippets
sell both ("Tiroir Caisse RJ11 12V", RETIF "12V ou 24V"); the TM-T20III port voltage was not read].

**"Kick when it is time to be paid or give change"** is therefore "send 5 bytes / one XML element to the printer";
the drawer opens whatever route reaches the printer. Pin 3 lets the till know the drawer is still open [V: 2].

**What the browser can reach** (MDN browser-compat-data, read 2026-09-25 [V: 1]; Mozilla positions [V: 4]):

| API | Chrome/Edge desktop | Chrome Android | Firefox | Safari | Notes |
|---|---|---|---|---|---|
| WebUSB | 61+ | yes | no — Mozilla "negative" | no | HTTPS only, user picks the device once |
| Web Serial | 89+ | 148+ | **151+, add-on gated** (Mozilla "neutral") | no | reaches USB-CDC and driver-made virtual COM ports |
| WebHID | 89+ | no | no — "negative" | no | scanners, not printers |
| Web Bluetooth | 70+ (Linux off by default) | 56+ | no — "negative" | no | BLE GATT only, not classic SPP printers |
| Window Management (`getScreenDetails`) | 100+ | — | no | no | place the customer window on screen 2 |
| BroadcastChannel | 54+ | yes | 38+ | 15.4+ | what the customer display already uses |

All four device APIs require a secure context and an explicit user choice of the device [V: 1 (MDN pages)]. **The
till must run Chrome or Edge**; Firefox and Safari will not catch up [V: 4].

**Windows trap.** WebUSB cannot talk to a USB printer whose vendor driver is installed, because the driver claims it
exclusively; the fallback is the driver's virtual COM port through Web Serial — which does not work with Star's
virtual port [V: 15 README "Unfortunately this does not work on Windows"].

**Network printers from the browser.** An Epson TM printer with Ethernet/Wi-Fi accepts ePOS-Print XML as a SOAP
POST to `http://<printer>/cgi-bin/epos/service.cgi?devid=<id>&timeout=<ms>` [V: 3]. **TM-T20III and TM-m30III
(Wi-Fi/Ethernet models) are listed as ePOS-Print supported, with HTTPS communication** [V: 3, rev AF]. Two browser
rules decide whether an HTTPS twes-in page may POST to it:
- Chrome 142 ships a *Local Network Access* permission prompt for any public-site → LAN/loopback request, and
  exempts such requests from mixed-content blocking when the host is a private IP literal, a `.local` name, or the
  `fetch` carries `targetAddressSpace: "local"` [V: 7]. Before 142 an HTTPS page could not POST to `http://192.168…`
  — which is why local agents exist.
- The printer must answer CORS for a foreign origin [I: Epson's JS SDK runs from arbitrary web pages against these
  printers; not checked on hardware — prove it on a real TM-T20III before relying on it].
Star's equivalent (webPRNT) is the same idea, XML over HTTP to the printer [V: 9 readme]; its JS SDK is refused
(below), the protocol could be spoken from its manual [I].

**Printer-polls-server (no browser involved).** Epson *Server Direct Print*: the printer periodically requests a
URL and prints the ePOS-Print XML it receives; interval set in seconds in WebConfig, HTTPS supported [V: 11 — its sample-program
section names TM-i/TM-DT/TM-T88VI; the feature's model list and TM-T20III/m30III support were not read, U]. Star
*CloudPRNT*: printer POSTs its status, server answers `jobReady`, printer GETs the job then DELETEs it; an MQTT
(push) variant exists [V: 10, example server code]. Both let the twes-in **API** print and kick the drawer with no
browser permission at all; HTTP polling adds seconds of latency to a drawer kick [I: polling interval], MQTT does not
[I]. CloudPRNT supports mC-Print3, TSP100IV, mC-Label3 [V: 10].

**Local agent.** A program on the till PC that the page reaches over `ws://localhost` or `https://localhost`.
- **QZ Tray**: source and `qz-tray.js` are **LGPL-2.1-only** (LICENSE.txt, file header `@license LGPL-2.1-only`, npm
  `LGPL-2.1`) [V: 5]. Not in the permitted list → refused as shipped code. Without the paid certificate
  (Premium Support $749/yr, Company Branded $3,499/yr) every request shows an "Untrusted website" dialog [V: 6].
- **Our own agent** (Go/Rust, MIT deps) is always possible and is the only way to Caisse-AP TPEs or classic-BT
  printers — but it is a second product to install, sign and update on every till [I].
- Chrome *Direct Sockets* (raw TCP/UDP) is "planned to be available only in Isolated Web Apps" — not the open web
  [V: 8].

## 2. Receipt printers

- **ESC/POS** (Epson and most clones: Xprinter, Rongta, Bixolon…) vs **StarPRNT/Star Line** (Star). A single MIT
  encoder, `@point-of-sale/receipt-printer-encoder` 4.0.1, emits ESC/POS, StarLine and StarPRNT: text with code
  pages (`auto` from a printer model), tables, barcodes (EAN-13, Code128, GS1…), `qrcode()`, `pdf417()`, `image()`
  with dithering, `pulse(device, on, off)`, cut [V: 14]. Transport siblings, all MIT: webusb-, webserial-,
  webbluetooth-, network-receipt-printer, receipt-printer-status [V: 14 npm].
- **58 vs 80 mm**: the encoder takes `columns` (e.g. 32 vs 48 in font A) and `printableWidth` for images [V: 14].
  TM-T20III accepts 58 or 80 mm paper [V: 16].
- **QR codes**: native ESC/POS `GS ( k` 2D-symbol functions (store 80 / print 81) [V: 2]; or as a raster image.
- **Logos**: raster image through `image()` [V: 14]; Epson also stores logos in NV memory [I].
- **Arabic (Tunisia)**: printer fonts do not shape Arabic; render the receipt (or the Arabic lines) to a canvas and
  send it as an image [I: no Arabic/cp864 code page appears in the encoder's documentation; not tested].
- **`window.print()`** to the OS thermal driver works in every browser but shows the print dialog; Chrome's
  `--kiosk-printing` launch flag prints silently to the default printer [U: secondary sources only, 19]. Through the
  driver we lose the cut and the drawer kick, unless the driver itself is set to open the drawer [U: Epson APD
  option seen only in a search-result title]. Fallback, not the design.

## 3. Customer display

- **Second screen**: the app's `/customer-display` window over BroadcastChannel already works in every modern
  browser [V: 1]; Chrome/Edge can place it on the second monitor with `getScreenDetails()` after a permission [V: 1].
  A cheap 10–15" USB/HDMI monitor is the whole hardware.
- **Pole / VFD displays** (2×20 characters): serial or USB-CDC devices taking ESC/POS-like commands → Web Serial on
  Chrome/Edge [I: not verified per model]. Epson's DM-D30/DM-D70 hang off a TM-m30III/m50 and are driven with
  ePOS XML through the printer [V: 3 rev AF "XML for Controlling Customer Display"]. Not worth it next to a screen.

## 4. Card terminals (TPE)

**France.**
- **Caisse-AP (ex-Concert)**, published by the Association du Paiement: v3.10 adds TCP/IP to serial; v3.20 adds
  receipt retrieval [V: 12]; IP terminals listen on e.g. port 8888 [U: integrator pages only, 13]. Almost every bank
  TPE (Ingenico, Verifone, PAX with Nepting) speaks it. It is a **raw socket** → unreachable from a web page [V: 8];
  requires a local agent (ours) or a terminal whose bank also offers a cloud API. Spec access terms unknown [U:
  "Accès membres" portal, no public download found].
- **Nepting**: a multi-brand payment platform "en mode Cloud" with "connexion possible avec la caisse via API" [U:
  marketing/integrator pages, no API docs read].
- **Cloud terminal APIs (browser-agnostic — our server calls them):**
  - Stripe Terminal *server-driven*: API → WisePOS E, Stripe S700/S710, Verifone readers; webhooks for the result;
    France supported, Tunisia not [V: 20, 21, 22]. Libraries MIT (`@stripe/terminal-js` only for the JS-SDK route) [V: 14 npm].
  - SumUp Cloud API: POS "running on any platform… capable of sending HTTPS requests" starts a checkout on a Solo
    reader, `POST /v0.1/merchants/{code}/readers/{id}/checkout` [V: 23]. `@sumup/sdk` Apache-2.0 [V: 14 npm].
  - Adyen Terminal API cloud (sync or async) or local on port 8443 with Adyen's certificate [V: 24]. MIT lib.
  - myPOS, Worldline, Ingenico direct: not researched [U].
  Pattern for twes-in: the sale posts "charge 12,40 € on reader X" to our API → provider → terminal; the result comes
  back by webhook and the realtime channel refreshes the till. No hardware code in the browser.

**Tunisia.** SMT operates the national switch; each TPE has a TID/MID [U: tpe.tn, a vendor page]. Banque de Tunisie
offers fixed, GPRS and contactless TPEs with installation and maintenance, and **no word on cash-register
integration** [V: 25]. SMT's own site failed TLS verification [U]. No Stripe [V: 22]. Online: Konnect
`POST /payments/init-payment` (wallet, bank_card, e-DINAR; amounts in millimes) returns `payUrl` [V: 26]; Flouci
`POST https://developers.flouci.com/api/v2/generate_payment` returns a checkout `link` [V: 27]. In store that
becomes a QR code on the customer display (the app already draws QR codes) paid from the customer's phone [I].
**Card-present in Tunisia = a stand-alone bank TPE; the cashier types the amount and records "paid by card".**

## 5. All-in-one Android POS (Sunmi, iMin, PAX)

- **Sunmi** (T2s, D3…): three ways to the built-in printer — AIDL service, virtual Bluetooth "InnerPrinter", and a
  JS bridge for H5 pages; drawer and customer display on desktop models [U: Sunmi docs page 404'd; from their PDF
  titles/search snippets, 17]. Their H5 demo's `bundle.umd.js` connects to `ws://localhost:7070/ws` [V: read the
  bundle] — so a local service on the device, reachable from Chrome there subject to Local Network Access [I]. No
  licence text in the bundle [V]. InnerPrinter is classic Bluetooth (SPP) → Web Bluetooth cannot reach it [I: BLE-only API].
- **iMin**: JS Printer SDK with `openCashBox()`, connection types USB/SPI/Bluetooth, demo zip, "© iMin Technology",
  no licence terms [V: 28].
- **PAX**: not researched [U].
- Either way the page stays the same twes-in PWA; a device adapter would sit behind the same "print receipt / kick
  drawer" port. Needs a licence ruling before any of their JS is shipped; speaking `ws://localhost:7070` ourselves
  from their docs would avoid shipping it [I].

## 6. Scanners

- **Keyboard wedge (HID keyboard)**: every browser, no permission; the app's `ScanWedge` already separates a
  scanner burst from typing (repo, `web/src/app/shared/scan/scan-wedge.ts`).
- **Camera**: already done with zxing-wasm (`zxing-barcode-reader.ts`). Native `BarcodeDetector` is ChromeOS/macOS
  and Android Chrome only, Safari behind a flag, no Firefox [V: 1] — zxing stays the right choice.
- WebHID / Web Serial scanner modes exist (MIT `@point-of-sale/webhid-barcode-scanner`, `…webserial…`) [V: 14 npm]
  but add a permission step for nothing the wedge does not do.

## 7. Reliability notes

- Network printer beats USB: no per-OS driver fight, reachable from any till on the LAN, drawer kick via the
  printer, status (paper/cover/drawer) in the XML response [V: 3]. Needs a fixed IP (DHCP reservation) [I].
- WebUSB/Web Serial need the user to pick the device once per origin and are Chrome/Edge only [V: 1]; Windows +
  vendor driver breaks WebUSB [V: 15].
- LAN POST from HTTPS depends on Chrome ≥142 and a one-time Local Network Access grant [V: 7]; self-hosted
  on-premise twes-in served over the LAN avoids mixed content entirely [I].
- Server Direct Print / CloudPRNT survive any browser and work for kitchen tickets, but add polling latency (HTTP) [I].
- Printers named here are covered by vendor docs; clones (Xprinter etc.) are ESC/POS over USB only in most cases [U].

## 8. Recommendation — smallest architecture

1. **One port in the web app, `ReceiptPrinter`** (print receipt, kick drawer, read status), with two adapters:
   - `EposNetworkPrinter`: ePOS-Print XML POSTed to the printer's private IP from Chrome/Edge ≥142 (our own XML,
     no Epson SDK). **Default.**
   - `EscPosUsbPrinter`: `@point-of-sale/receipt-printer-encoder` + webusb/webserial transport (MIT) for a USB-only
     printer on Linux/macOS/ChromeOS/Android, Web Serial on Windows.
   Later, a server-side `ServerDirectPrint`/CloudPRNT adapter for kitchen printers, written from the protocol docs.
2. **Drawer on the printer's RJ11**; kick on "encaisser espèces" and on "rendre la monnaie"; read pin 3 to show
   "tiroir ouvert".
3. **Customer display** = the existing window on a second monitor.
4. **Cards**: France — one cloud provider behind a `CardTerminal` port in the API (SumUp Solo or Stripe WisePOS E /
   S700); Caisse-AP bank TPEs only if a local agent is ever ruled. Tunisia — manual "carte" tender + Konnect/Flouci QR.
5. **No QZ Tray, no Star/Epson/Sunmi/iMin SDK in the tree.**

**Shopping list, small shop:**

| Item | France | Tunisia |
|---|---|---|
| Epson TM-T20III Ethernet (80/58 mm, drawer port, ePOS-Print) | 165 € HT [V: 16] (149 € HT elsewhere [U: search snippet]) | 459 DT [V: 29] |
| Epson TM-m30III (Ethernet/USB-C; + DM-D30 display option) | ≈ 467 € HT Wi-Fi/BT model [U: search snippet] | [U] |
| RJ11 cash drawer, 5 notes / 8 coins | [U: prices not extracted] | 132–145 DT [V: 18] |
| Second monitor for the customer | any | any |
| Card reader, France | SumUp Solo / Stripe WisePOS E [U: prices not read] | bank TPE (rented) [U] |
| Scanner | any USB HID wedge scanner | same |
| All-in-one alternative | Sunmi T2s / iMin D-series (Android, printer + drawer port) [U: prices] | same |

**Open questions for a ruling:** (a) Chrome/Edge as the supported till browser; (b) whether a signed local agent
of our own is ever worth building (only reason: Caisse-AP bank TPEs, classic-BT printers); (c) Sunmi/iMin JS
licences; (d) the French certification of cash-register software is covered separately in `till-certification.md`.

## Sources (read 2026-09-25)

1. MDN browser-compat-data raw JSON: `api/USB.json`, `Serial.json`, `HID.json`, `Bluetooth.json`, `Window.json`,
   `BroadcastChannel.json`, `BarcodeDetector.json` — https://github.com/mdn/browser-compat-data ; MDN pages
   https://developer.mozilla.org/en-US/docs/Web/API/WebUSB_API (and Web_Serial_API, WebHID_API, Web_Bluetooth_API)
2. Epson TM-T20 ESC/POS Quick Reference (Epson document) — http://www.novopos.ch/client/EPSON/TM-T20/TM-T20_eng_qr.pdf
3. Epson ePOS-Print XML User's Manual rev K, rev S, rev AF — https://files.support.epson.com/pdf/pos/bulk/epos-print_xml_um_en_revk.pdf , …_revs.pdf , …_rev_af.pdf
4. Mozilla standards positions — https://github.com/mozilla/standards-positions/blob/main/activities.yml
5. QZ Tray LICENSE.txt and js/qz-tray.js — https://github.com/qzind/tray ; npm `qz-tray`; https://qz.io/licensing/
6. QZ Tray plans — https://qz.io/
7. Chrome, Local Network Access — https://developer.chrome.com/blog/local-network-access
8. WICG Direct Sockets explainer — https://github.com/WICG/direct-sockets/blob/main/docs/explainer.md
9. Star webPRNT SDK Readme and SoftwareLicenseAgreement.pdf — https://github.com/star-micronics/starwebprnt-sdk
10. Star CloudPRNT SDK README, LICENSE, `ExampleServers/php_queue/cloudprnt.php` — https://github.com/star-micronics/cloudprnt-sdk ; https://star-m.jp/products/s_print/CloudPRNTSDK/Documentation/en/articles/getting-started/getting-started.html
11. Epson Server Direct Print User's Manual rev K — https://files.support.epson.com/pdf/pos/bulk/server_direct_print_um_en_revk.pdf
12. Association du Paiement, Caisse protocol versions — https://associationdupaiement.fr/faq/quelles-sont-les-fonctionnalites-apportees-par-chaque-version-du-protocole-caisse/
13. Integrator notes on Concert/Caisse-AP (search only) — https://www.planet-monetic.fr/le-protocole-concert-v3-x/
14. npm registry metadata for `@point-of-sale/*`, `esc-pos-encoder`, `star-prnt-encoder`, `@stripe/terminal-js`, `@sumup/sdk`, `@adyen/api-library`; ReceiptPrinterEncoder LICENSE + `documentation/commands.md`, `barcodes.md` — https://github.com/at-point-of-sale/ReceiptPrinterEncoder
15. WebUSBReceiptPrinter README — https://github.com/at-point-of-sale/WebUSBReceiptPrinter
16. procaisse.com, TM-T20III Ethernet — https://procaisse.com/imprimantes-tickets-thermiques/1680-epson-tm-t20iii-ethernet-8715946669656.html
17. Sunmi developer docs (404) https://docs.sunmi.com/en/general-function-modules/printing-service/ ; H5 demo https://h5.sunmi.com/printer-sdk/demo.html and its `dist/bundle.umd.js`
18. Spacenet, RJ11 drawer — https://spacenet.tn/tiroir-caisse-tunisie/60191-tiroir-caisse-rj11-5-pieces-4-billets-noir.html
19. `--kiosk-printing` (secondary) — https://gist.github.com/sajinct/cf4863f7b5da061b2c65c104c55a6da6
20. Stripe Terminal server-driven integration — https://docs.stripe.com/terminal/payments/setup-integration.md?terminal-sdk-platform=server-driven
21. Stripe Terminal regional list — https://docs.stripe.com/terminal/payments/regional.md
22. Stripe global availability — https://stripe.com/global
23. SumUp Cloud API — https://developer.sumup.com/terminal-payments/cloud-api
24. Adyen, choose your architecture — https://docs.adyen.com/point-of-sale/design-your-integration/choose-your-architecture/
25. Banque de Tunisie, TPE — https://www.bt.com.tn/les-terminaux-de-paiement-electronique-entreprises
26. Konnect init-payment — https://docs.konnect.network/docs/fr/api-integration/endpoints/initiate-payment
27. Flouci generate_payment — https://docs.flouci.com/api-reference/generate-transaction
28. iMin JSPrinterSDK — https://oss-sg.imin.sg/docs/en/JSPrinterSDK.html
29. Spacenet, TM-T20III Ethernet — https://spacenet.tn/impression/41119-imprimante-de-ticket-thermique-epson-tm-t20iii-ethernet-noir-c31ch51012.html
