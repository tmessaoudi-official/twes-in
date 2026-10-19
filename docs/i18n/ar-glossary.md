# Arabic glossary — the words the screens, mails and printed documents use

Every Arabic text of twes-in follows this list, so one thing has one name everywhere. The register is the Tunisian
administration's Modern Standard Arabic: « حريف » for a customer and « مزوّد » for a supplier, as Tunisian forms write them, never
the Eastern « عميل » or « مورّد ». The developer, a native speaker, reviews the wording in the running app and in the source
before Arabic is shown to a shop (docs/plans/first-client.plan.md, 2026-10-17 16:41 and 16:43).

Each term says where it comes from: `[Source: …]` a document read; `[Ruled: …]` a product ruling the word must carry;
`[Unverified]` common Tunisian usage not checked against a primary Arabic text (the Arabic version of the TVA code and of
the IRPP/IS code, published in the Journal officiel, was not read). A term marked unverified is the first to check.

## Writing rules

- Digits are the Latin ones (0–9), as Tunisian documents print them; amounts, dates and numbers come formatted from the
  number and date settings, never written out in the text.
- Arabic punctuation: « ، » « ؛ » « ؟ ». Quotation marks « … » as in French. No full stop on a button, a label or a title.
- A parameter `{{name}}` is kept exactly as it is, and so is the ICU plural syntax; a plural writes all six Arabic forms
  (`zero`, `one`, `two`, `few`, `many`, `other`). « one » and « two » say the count in the word itself (« فاتورة واحدة »,
  « فاتورتان ») and leave `{{count}}` out; `few` (3–10) takes the plural, `many` (11–99) the accusative singular, `other`
  (100 and beyond, 0 when `zero` is absent) the singular, each with `{{count}}` before the noun.
- A button or a menu entry is a verbal noun (« حفظ », « حذف »), never an imperative. A sentence addresses the person in the
  second person singular.
- Codes, file formats and product names stay in Latin letters: PDF, CSV, XLSX, Factur-X, UBL, IBAN, RIB, SIRET, TEIF,
  twes-in, Peppol, Excel, QR.

## Product rulings the words carry

| French | Arabic | Rule |
|---|---|---|
| Annuler (the toast's undo only) | تراجع | `[Ruled: docs/SPEC.md § 7 2026-10-11 08:26]` « تراجع » only takes back what was just done; closing without acting is « إغلاق » |
| Rétablir (a form's changes undone) | استرجاع | same ruling |
| Fermer | إغلاق | same ruling |
| Soldée (nothing left due) | مُسوّاة | `[Ruled: docs/SPEC.md § 7 2026-09-19]` never « مدفوعة »: a credited invoice was not paid |
| Classé sans suite (a quote) | دون متابعة | `[Ruled: docs/SPEC.md § 7 2026-10-11 08:26]` a quote is never « ملغى » |
| Abandonner (an invoice draft) | التخلّي عن المسودة | same ruling: an invoice is never « ملغاة » |
| TVA collectée | الأداء على القيمة المضافة المُحصَّل | `[Ruled: docs/SPEC.md § 7 2026-09-24 11:40]` never VAT « للتصريح » nor « التصريح بالأداء على القيمة المضافة »; the withholding is declared (TEJ), and says so |
| Bientôt | قريبًا | `[Ruled: docs/SPEC.md § 7 2026-09-25]` an unbuilt screen says so |

## Fiscal and document terms

| French | Arabic | Source |
|---|---|---|
| retenue à la source | الخصم من المورد | `[Source: AJECT, commentaires sur le projet de LF 2018, leaders.com.tn]` |
| TVA | الأداء على القيمة المضافة; in a narrow column « أ.ق.م » | `[Unverified]` |
| FODEC | معلوم صندوق تنمية القدرة التنافسية (FODEC) | `[Unverified]` |
| timbre fiscal | معلوم الطابع الجبائي | `[Unverified]` |
| matricule fiscal | المعرّف الجبائي | `[Unverified]` |
| HT / TTC | دون اعتبار الأداءات / باعتبار الأداءات | `[Unverified]` |
| facture | فاتورة | |
| facture d'avoir, avoir | فاتورة تعديلية | `[Unverified]` alternative « إشعار دائن » |
| devis | عرض سعر | `[Unverified]` |
| bon de livraison | وصل تسليم | `[Unverified]` |
| commande fournisseur | طلبية لدى مزوّد; the list « طلبيات المزوّدين » | `[Unverified]` |
| réception (of goods) | استلام | |
| franchise en base | الإعفاء الأساسي | `[Unverified]` French regime, rarely seen in Arabic |
| autoliquidation | التصفية الذاتية للأداء | `[Unverified]` |
| échéance | أجل الاستحقاق | |
| paiement | دفع | |
| remise | تخفيض | |
| prix de revient | سعر الكلفة | |
| marge | الهامش | |
| liste de prix | قائمة أسعار | |
| numérotation, série | الترقيم، سلسلة الترقيم | |
| émettre (an invoice) | إصدار | |
| brouillon | مسودة | |
| arrêtée la présente facture à la somme de | أُوقفت هذه الفاتورة على مبلغ … قدره: | `[Unverified]` printed, the amount in the nominative after « قدره » (`AmountInWords`: مائة، not مئة), for the developer's review |

## People, places and things

| French | Arabic |
|---|---|
| client, clients | حريف، حرفاء |
| fournisseur | مزوّد، مزوّدون |
| société, entreprise | شركة |
| établissement | فرع |
| membre, rôle, permission | عضو، دور، صلاحية |
| propriétaire (of a company) | المالك |
| opérateur (of the platform) | مشغّل المنصّة |
| produit, référence | منتج، مرجع |
| stock, emplacement, dépôt | مخزون، موقع، مخزن |
| inventaire | جرد |
| lot, numéro de série | دفعة، رقم تسلسلي |
| code-barres | رمز شريطي |
| unité | وحدة قيس |
| dépense, catégorie | مصروف، صنف |
| rapport | تقرير |
| module | وحدة (module) — « الوحدات » in the menu |
| Accueil | الرئيسية |
| Paramètres | الإعدادات |
| Mon compte | حسابي |
| À surveiller | للمتابعة |
| Premiers pas | الخطوات الأولى |
| Se connecter, Se déconnecter | تسجيل الدخول، تسجيل الخروج |
| mot de passe, clé d'accès (passkey) | كلمة المرور، مفتاح المرور |
| double authentification | التحقق بخطوتين |
| Enregistrer, Supprimer, Modifier | حفظ، حذف، تعديل |
| Importer, Exporter | استيراد، تصدير |
| démo | العرض التجريبي |
| Accès du support | وصول الدعم |
| notification | إشعار |
