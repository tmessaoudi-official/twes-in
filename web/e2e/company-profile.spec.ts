// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, type Page, test } from '@playwright/test';
import { inACompany, OPERATOR_PASSWORD, signIn } from './session';
import { proveIfAsked, sendProving } from './step-up';
import { toast } from './toast';
import { axeAtRest } from './axe';

// G3b through the real stack: the seeded Tunisian company's owner fills its profile, which asks for the matricule
// fiscal its preset requires, and the profile survives a reload. One database is shared by the whole suite, so a
// profile found filled at the start is put back at the end; the seed's empty profile cannot be, because the preset
// requires the matricule fiscal, and nothing else in the suite reads the profile.
const CSRF = '0123456789abcdef0123456789abcdef';

const WRITABLE = [
  'legalName',
  'legalForm',
  'identifiers',
  'addressLine1',
  'addressLine2',
  'postalCode',
  'city',
  'email',
  'phone',
  'website',
  'iban',
  'bic',
  'vatRegime',
  'vatOnDebits',
  'invoiceFooterText',
  'latePenaltyText',
];

/** The profile as the API holds it, reduced to what a PUT accepts. */
async function currentProfile(page: Page): Promise<Record<string, unknown>> {
  return page.evaluate(async (writable) => {
    const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
    const profile = (await (
      await fetch(`/api/companies/${me.company.id}/profile`)
    ).json()) as Record<string, unknown>;
    return Object.fromEntries(writable.map((key) => [key, profile[key] ?? null]));
  }, WRITABLE);
}

async function putProfile(page: Page, body: Record<string, unknown>): Promise<void> {
  const [companyId, version] = await page.evaluate(async () => {
    const id = ((await (await fetch('/api/auth/me')).json()) as { company: { id: string } }).company
      .id;
    const held = (await (await fetch(`/api/companies/${id}/profile`)).json()) as {
      version: number;
    };
    return [id, held.version] as const;
  });
  // Put back over whatever the scenario saved since the profile was first read, so on the version held now.
  // Putting the bank details back waits for a recent proof (docs/SPEC.md § 7), given only when asked.
  const url = `/api/companies/${companyId}/profile`;
  expect(
    (await sendProving(page, OPERATOR_PASSWORD, 'PUT', url, { ...body, version })).status,
  ).toBe(200);
}

test("the owner fills the company's profile with the identifier its preset requires", async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const original = await currentProfile(page);
  try {
    await page.goto('/company/profile');
    const legalName = page.getByTestId('field-legalName');
    await expect(legalName).toBeVisible();

    const axe = await (
      await axeAtRest(page)
    )
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(axe.violations.map((violation) => violation.id)).toEqual([]);

    // The débits option is France's (CGI ann. II art. 242 nonies A I 11° bis): a Tunisian profile does not offer it.
    await expect(page.getByTestId('field-vatOnDebits')).toHaveCount(0);

    const name = `Demo SARL ${Date.now()}`;
    await legalName.fill(name);
    await page.getByTestId('field-identifier__matricule_fiscal').fill('1234567A/B/M/000');
    await page.getByTestId('field-iban').fill('TN59 1000 6035 1835 9847 8831');
    await page.getByTestId('record-save').click();
    // The bank details are on the step-up list (docs/SPEC.md § 7).
    await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
    await expect(toast(page)).toContainText('Le profil a été enregistré.');

    await page.reload();
    await expect(legalName).toHaveValue(name);
    await expect(page.getByTestId('field-iban')).toHaveValue('TN5910006035183598478831');
  } finally {
    if (
      original['identifiers'] !== null &&
      Object.keys(original['identifiers'] as object).length > 0
    ) {
      await putProfile(page, original);
    }
  }
});

test('an e-mail with no dot in its domain is said under the profile field before saving, in the words a file is refused with', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/company/profile');
  await page.getByTestId('field-identifier__matricule_fiscal').fill('1234567A/B/M/000');
  // An address with no dot in its domain: the one e-mail rule refuses it on the screen as the API would, so nothing is
  // sent, and nothing is written to the shared company's profile.
  await page.getByTestId('field-email').fill('compta@carthage');
  await page.getByTestId('record-save').click();

  await expect(page.getByTestId('field-error-email')).toHaveText(
    'Une adresse e-mail s’écrit nom@domaine.extension (exemple : compta@exemple.tn). Vérifiez l’arobase et le point.',
  );
  await expect(page.getByTestId('field-email')).toBeFocused();

  await page.getByTestId('field-email').fill('compta@carthage.tn');
  await expect(page.getByTestId('field-error-email')).toHaveCount(0);
});

// One pixel, PNG: the smallest picture the logo upload takes.
const PIXEL = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);

test('a new logo shows at once at the head of the rail, without reloading the page', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  // The shared company's logo is put back as it was: its bytes are kept to upload again, or it is removed.
  const before = await page.evaluate(async () => {
    const me = (await (await fetch('/api/auth/me')).json()) as {
      company: { id: string; logoVersion: string | null };
    };
    const { id, logoVersion } = me.company;
    if (logoVersion === null) return { id, bytes: null };
    const picture = await fetch(`/api/companies/${id}/logo?v=${logoVersion}`);
    return {
      id,
      bytes: Array.from(new Uint8Array(await picture.arrayBuffer())),
      type: picture.headers.get('content-type') ?? 'image/png',
    };
  });
  await page.goto('/company/profile');
  try {
    await page
      .getByTestId('profile-logo-input')
      .setInputFiles({ name: 'logo.png', mimeType: 'image/png', buffer: PIXEL });
    await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
    await expect(page.getByTestId('profile-logo-image')).toBeVisible();
    const shown = await page.getByTestId('profile-logo-image').getAttribute('src');

    await expect(page.getByTestId('rail-company').getByTestId('company-logo')).toHaveAttribute(
      'src',
      shown ?? 'no logo shown on the profile',
    );
  } finally {
    if (before.bytes === null) {
      const url = `/api/companies/${before.id}/logo`;
      expect((await sendProving(page, OPERATOR_PASSWORD, 'DELETE', url)).status).toBeLessThan(300);
    } else {
      await page
        .getByTestId('profile-logo-input')
        .setInputFiles({ name: 'logo', mimeType: before.type, buffer: Buffer.from(before.bytes) });
      await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
    }
  }
});

// One phone field everywhere (row 252): the company's country is picked beside the number, another is found by its name,
// the number is saved in international form, and it reads back into its country and its local part after a reload.
test('a phone is typed beside its country, found by name, and reads back into the two', async ({
  page,
}) => {
  await signIn(page);
  await inACompany(page, CSRF);
  const original = await currentProfile(page);
  try {
    await page.goto('/company/profile');
    await page.getByTestId('field-identifier__matricule_fiscal').fill('1234567A/B/M/000');
    const country = page.getByTestId('field-phone-country');
    // The seeded company is Tunisian: a number with no « + » is read as Tunisia's.
    await expect(country).toContainText('+216');

    await country.click();
    await page.getByTestId('field-phone-country-search').fill('France');
    await page.getByTestId('field-phone-country-FR').click();
    await expect(country).toContainText('+33');
    await page.getByTestId('field-phone').fill('06 12 34');
    await page.getByTestId('field-phone').press('Tab');
    await expect(page.getByTestId('field-error-phone')).toContainText('+33 ');
    await page.getByTestId('field-phone').fill('06 12 34 56 78');
    await expect(page.getByTestId('field-error-phone')).toHaveCount(0);
    await page.getByTestId('record-save').click();
    await proveIfAsked(page, OPERATOR_PASSWORD, toast(page));
    await expect(toast(page)).toContainText('Le profil a été enregistré.');
    expect((await currentProfile(page))['phone']).toBe('+33612345678');

    await page.reload();
    await expect(page.getByTestId('field-phone-country')).toContainText('+33');
    await expect(page.getByTestId('field-phone')).toHaveValue('06 12 34 56 78');
    const axe = await (
      await axeAtRest(page)
    )
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(axe.violations.map((violation) => violation.id)).toEqual([]);
  } finally {
    if (
      original['identifiers'] !== null &&
      Object.keys(original['identifiers'] as object).length > 0
    ) {
      await putProfile(page, original);
    }
  }
});
