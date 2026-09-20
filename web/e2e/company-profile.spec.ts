// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { inACompany, signIn } from './session';
import { toast } from './toast';

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
  const status = await page.evaluate(
    async ([csrf, profile]) => {
      const me = (await (await fetch('/api/auth/me')).json()) as { company: { id: string } };
      const response = await fetch(`/api/companies/${me.company.id}/profile`, {
        method: 'PUT',
        headers: { 'content-type': 'application/json', 'csrf-token': csrf },
        body: JSON.stringify(profile),
      });
      return response.status;
    },
    [CSRF, body] as const,
  );
  expect(status).toBe(200);
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

    const axe = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(axe.violations.map((violation) => violation.id)).toEqual([]);

    const name = `Demo SARL ${Date.now()}`;
    await legalName.fill(name);
    await page.getByTestId('field-identifier__matricule_fiscal').fill('1234567A/B/M/000');
    await page.getByTestId('field-iban').fill('TN59 1000 6035 1835 9847 8831');
    await page.getByTestId('record-save').click();
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
