// SPDX-License-Identifier: AGPL-3.0-or-later
import en from '../../public/i18n/en.json';
import fr from '../../public/i18n/fr.json';

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

const files: Record<string, unknown> = { fr, en };
// The sign-in page's sample invoice wears the same badge as a real one, so it reads the same.
const paidStatus = [
  fr.invoices.statuses.paid,
  en.invoices.statuses.paid,
  fr.auth.scene.paid,
  en.auth.scene.paid,
];

function load(lang: string): unknown {
  return files[lang];
}

describe('translation files', () => {
  const fr = keysOf(load('fr')).sort();
  const en = keysOf(load('en')).sort();

  it('fr and en declare exactly the same keys', () => {
    expect(en).toEqual(fr);
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
  it('an invoice with nothing left due reads settled, not paid', () => {
    expect(paidStatus).toEqual(['Soldée', 'Settled', 'Soldée', 'Settled']);
  });
});
