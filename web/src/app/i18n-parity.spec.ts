// SPDX-License-Identifier: AGPL-3.0-or-later
import enAccount from '../../public/i18n/account/en.json';
import frAccount from '../../public/i18n/account/fr.json';
import enDemoMails from '../../public/i18n/demo-mails/en.json';
import frDemoMails from '../../public/i18n/demo-mails/fr.json';
import enReports from '../../public/i18n/reports/en.json';
import frReports from '../../public/i18n/reports/fr.json';
import en from '../../public/i18n/en.json';
import enExpense from '../../public/i18n/expense/en.json';
import frExpense from '../../public/i18n/expense/fr.json';
import enImport from '../../public/i18n/import/en.json';
import frImport from '../../public/i18n/import/fr.json';
import fr from '../../public/i18n/fr.json';
import enSchedule from '../../public/i18n/schedule/en.json';
import frSchedule from '../../public/i18n/schedule/fr.json';
import enTwoFactor from '../../public/i18n/two-factor/en.json';
import frTwoFactor from '../../public/i18n/two-factor/fr.json';
import { PluralCompiler } from './shared/i18n/plural-compiler';
import { SETTING_HINTS } from './shared/settings/setting-forms';

/**
 * docs/SPEC.md § 5, item 5: fr and en carry the same keys. A key present in one file only would render as its
 * raw path in the other language, and nothing else checks it.
 */
function keysOf(value: unknown, prefix = ''): string[] {
  if (typeof value !== 'object' || value === null) {
    return [prefix];
  }
  return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) =>
    keysOf(child, prefix ? `${prefix}.${key}` : key),
  );
}

/** The texts a page loads with itself (`textsPart`), beside the language's own file: checked as one with it. */
const parts: Record<string, Record<string, unknown>> = {
  account: { fr: frAccount, en: enAccount },
  'demo-mails': { fr: frDemoMails, en: enDemoMails },
  expense: { fr: frExpense, en: enExpense },
  import: { fr: frImport, en: enImport },
  reports: { fr: frReports, en: enReports },
  schedule: { fr: frSchedule, en: enSchedule },
  'two-factor': { fr: frTwoFactor, en: enTwoFactor },
};

/** Two files' texts as one, refusing a key both hold: the part would silently win where the page reads it. */
function merged(main: unknown, part: unknown, where: string): unknown {
  if (typeof main !== 'object' || main === null || typeof part !== 'object' || part === null) {
    throw new Error(`${where} is in the language's file and in a part`);
  }
  const into: Record<string, unknown> = { ...(main as Record<string, unknown>) };
  for (const [key, value] of Object.entries(part as Record<string, unknown>)) {
    into[key] = key in into ? merged(into[key], value, `${where}.${key}`) : value;
  }
  return into;
}

const files: Record<string, unknown> = Object.fromEntries(
  Object.entries({ fr, en } as Record<string, unknown>).map(([lang, main]) => [
    lang,
    Object.entries(parts).reduce(
      (all, [name, part]) => merged(all, part[lang], `${name}/${lang}`),
      main,
    ),
  ]),
);
// The sign-in page's sample invoice wears the same badge as a real one, so it reads the same.
const paidStatus = [
  fr.invoices.statuses.paid,
  en.invoices.statuses.paid,
  fr.auth.scene.paid,
  en.auth.scene.paid,
];

// A quote voided, in both languages: the word its status says.
const quoteVoided = { fr: fr.quotes.statuses.cancelled, en: en.quotes.statuses.cancelled };

// The home's VAT tile, in both languages.
const homeVat = [fr.invoices.home.vat, en.invoices.home.vat];

function load(lang: string): unknown {
  return files[lang];
}

/** Every text with the dotted key it sits at. */
function entriesOf(value: unknown, prefix = ''): [string, string][] {
  if (typeof value === 'string') return [[prefix, value]];
  if (typeof value !== 'object' || value === null) return [];
  return Object.entries(value as Record<string, unknown>).flatMap(([key, child]) =>
    entriesOf(child, prefix ? `${prefix}.${key}` : key),
  );
}

function textsOf(value: unknown): string[] {
  if (typeof value === 'string') return [value];
  if (typeof value !== 'object' || value === null) return [];
  return Object.values(value as Record<string, unknown>).flatMap(textsOf);
}

/**
 * French holds « : » to its word with a no-break space and « ; ? ! » with a narrow one, and « » hold what they quote
 * the same way, so no line ever starts with a mark (audit 2026-10-06, N-b). A plain space, or the wrong one, is named.
 */
const LOOSE_FRENCH = /(?<=\S)[ \u00a0][;?!]|(?<=\S)[ \u202f]:(?=\s|$)|« | »/;

describe('translation files', () => {
  const fr = keysOf(load('fr')).sort();
  const en = keysOf(load('en')).sort();

  it('fr and en declare exactly the same keys', () => {
    expect(en).toEqual(fr);
  });

  it('says every setting hint in both languages', () => {
    // A hint is named by key in code (shared/settings/setting-forms.ts), where no label gate looks.
    const keys = new Set(fr);
    expect(Object.values(SETTING_HINTS).filter((key) => !keys.has(key))).toEqual([]);
  });

  it('French punctuation is held to its word by the right no-break space', () => {
    expect(textsOf(load('fr')).filter((text) => LOOSE_FRENCH.test(text))).toEqual([]);
  });

  it('French elides with the typographic apostrophe, never a straight one beside it', () => {
    // « l'instant » beside « qu’un » read as two hands (audit 2026-10-06, V-16).
    expect(textsOf(load('fr')).filter((text) => /\p{L}'\p{L}/u.test(text))).toEqual([]);
  });

  it('no key has an empty value', () => {
    for (const lang of ['fr', 'en']) {
      const json = JSON.stringify(load(lang));
      expect(json, `${lang}.json`).not.toMatch(/:""/);
    }
  });

  it('every auth error code the API can answer has a message', () => {
    const codes = [
      'invalid_credentials',
      'account_locked',
      'account_disabled',
      'too_many_attempts',
      'authentication_required',
      'csrf_token_missing',
      'csrf_token_invalid',
      'network',
    ];
    for (const code of codes) {
      expect(fr).toContain(`auth.errors.${code}`);
    }
  });

  // docs/SPEC.md § 7, 2026-09-19: the stored status is `paid` whether payments, credit notes or both brought the amount
  // due to zero, so it reads as settled: a fully credited invoice reading "Payée" said the customer paid what they did not.
  // docs/SPEC.md § 7, 2026-09-19 21:55: a decimal field takes a comma or a point, so no message may ask for a point.
  it('says a count in its own form, never with a lazy « (s) »', () => {
    for (const lang of ['fr', 'en']) {
      expect(textsOf(load(lang)).filter((text) => /\p{L}\((?:s|es|x)\)/u.test(text))).toEqual([]);
    }
  });

  it('every plural choice is read whole, whatever the count', () => {
    const compiler = new PluralCompiler();
    for (const lang of ['fr', 'en']) {
      const choices = textsOf(load(lang)).filter((text) => /\{\w+,\s*plural,/.test(text));
      for (const text of choices) {
        const said = compiler.compile(text, lang);
        expect(typeof said, text).toBe('function');
        for (const count of [0, 1, 2]) {
          const params = Object.fromEntries(
            [...text.matchAll(/\{\{?(\w+)/g)].map((match) => [match[1], count]),
          );
          expect((said as (params: object) => string)(params), text).not.toMatch(/[{}]|plural/);
        }
      }
    }
  });

  it('no message asks for a decimal point', () => {
    expect(JSON.stringify(load('fr'))).not.toMatch(/décimales et un point/);
    expect(JSON.stringify(load('en'))).not.toMatch(/decimals and a point/);
  });

  // docs/SPEC.md § 7, 2026-10-11 08:26: « Annuler » is only the toast's undo. Closing without acting is « Fermer »
  // or a named keep word, a form's changes are undone by « Rétablir », an invoice draft is abandoned and a quote
  // classé sans suite. Only the delivery note may keep « Annuler le bon », and only if the law names it so.
  it('says « Annuler » only to take back what was just done', () => {
    for (const lang of ['fr', 'en']) {
      const word = lang === 'fr' ? 'Annuler' : 'Cancel';
      const elsewhere = entriesOf(load(lang)).filter(
        ([key, text]) => text === word && !key.endsWith('undo'),
      );
      expect(elsewhere.map(([key]) => key)).toEqual([]);
    }
  });

  it('abandons an invoice draft and classes a quote sans suite, never cancels either', () => {
    const voided = entriesOf(load('fr')).filter(
      ([key, text]) =>
        /^(invoices|quotes|recurring|activity\.actions\.(invoice|quote))\./.test(key) &&
        !key.endsWith('undo') &&
        /[Aa]nnul/.test(text),
    );
    expect(voided.map(([key]) => key)).toEqual([]);
    expect([quoteVoided.fr, quoteVoided.en]).toEqual(['Classé sans suite', 'Not pursued']);
  });

  it('an invoice with nothing left due reads settled, not paid', () => {
    expect(paidStatus).toEqual(['Soldée', 'Settled', 'Soldée', 'Settled']);
  });

  // docs/SPEC.md § 7, 2026-09-24 11:40: the home's VAT is what the month's invoices charged, never a declaration.
  it('the VAT on the home reads collected, and no message calls VAT due for declaring', () => {
    expect(homeVat).toEqual(['TVA collectée · {{month}}', 'VAT collected · {{month}}']);
    expect(JSON.stringify(load('fr'))).not.toMatch(/TVA à déclarer|déclaration de TVA/i);
    expect(JSON.stringify(load('en'))).not.toMatch(/VAT to declare|VAT return/i);
  });
});
