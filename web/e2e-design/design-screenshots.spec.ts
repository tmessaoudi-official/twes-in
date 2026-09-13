// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

// Screenshots for the G2b design checkpoint (see playwright.design.config.ts). The signed-in state, the company,
// its members and the notification centre are fixtures served in place of the API; the scheme is preset through
// the same browser-storage key the settings adapter reads, which exercises that path too.
const OUT = process.env['DESIGN_SHOTS_DIR'] ?? 'test-results/design-shots';
const USER_ID = '0199a000-0000-7000-8000-000000000001';
const COMPANY_ID = '0199a000-0000-7000-8000-0000000000c1';

const me = {
  user: {
    id: USER_ID,
    email: 'operator@twes.local',
    displayName: 'Operator',
    locale: 'fr',
    isPlatformOperator: true,
  },
  company: {
    id: COMPANY_ID,
    name: 'Carthage Conseil',
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
    status: 'active',
    role: 'owner',
  },
  permissions: ['*'],
  mfa: { enrolled: true, required: false },
};

const companies = [
  { companyId: COMPANY_ID, name: 'Carthage Conseil', status: 'active', role: 'owner' },
  {
    companyId: '0199a000-0000-7000-8000-0000000000c2',
    name: 'Sfax Textiles',
    status: 'active',
    role: 'admin',
  },
];

const members = [
  ['Operator', 'operator@twes.local', 'owner'],
  ['Nour Ben Salah', 'nour@carthage-conseil.tn', 'admin'],
  ['Karim Trabelsi', 'karim@carthage-conseil.tn', 'member'],
  ['Claire Martin', 'claire@carthage-conseil.fr', 'member'],
].map(([displayName, email, role], index) => ({
  userId: `0199a000-0000-7000-8000-00000000010${index}`,
  displayName,
  email,
  role,
  joinedAt: '2026-09-01T09:00:00+00:00',
  status: 'joined',
}));

const notifications = {
  items: [
    {
      id: '0199a000-0000-7000-8000-000000000201',
      type: 'membership.added',
      payload: {
        company_id: COMPANY_ID,
        company: 'Carthage Conseil',
        role: 'member',
        name: 'Claire Martin',
      },
      companyId: COMPANY_ID,
      createdAt: '2026-09-13T08:30:00+00:00',
      readAt: null,
    },
  ],
  unread: 1,
};

async function serveFixtures(page: Page, scheme: 'light' | 'dark'): Promise<void> {
  await page.addInitScript(
    ([userId, chosen]) =>
      localStorage.setItem(`twes.settings.${userId}.presentation.scheme`, JSON.stringify(chosen)),
    [USER_ID, scheme] as const,
  );
  await page.route('**/api/**', (route) => {
    const json = (body: unknown, status = 200) =>
      route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
    switch (new URL(route.request().url()).pathname) {
      case '/api/auth/me':
        return json(me);
      case '/api/me/companies':
        return json(companies);
      case `/api/companies/${COMPANY_ID}/members`:
        return json(members);
      case '/api/me/notifications':
        return json(notifications);
      default:
        // The realtime token included: the bell then simply stays unconnected in these screenshots.
        return json({ error: 'not_found' }, 404);
    }
  });
}

async function capture(page: Page, name: string): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: `${OUT}/${name}.png`, fullPage: true });

  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  expect
    .soft(
      results.violations.map(
        (violation) =>
          `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
      ),
      `axe: ${name}`,
    )
    .toEqual([]);
  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect.soft(overflow, `sideways page scroll: ${name}`).toBeLessThanOrEqual(0);
}

const SCREENS = [
  { key: 'members', path: '/members', ready: 'members-table' },
  { key: 'customers', path: '/design/customers', ready: 'design-customers-table' },
  { key: 'customer-form', path: '/design/customers/new', ready: 'design-customer-form' },
  { key: 'invoice', path: '/design/invoice', ready: 'design-invoice-lines' },
] as const;

const VIEWPORTS = [
  { key: 'desktop', width: 1440, height: 900 },
  { key: 'phone', width: 390, height: 844 },
] as const;

for (const scheme of ['light', 'dark'] as const) {
  for (const viewport of VIEWPORTS) {
    for (const screen of SCREENS) {
      test(`${screen.key}, ${viewport.key}, ${scheme}`, async ({ page }) => {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await serveFixtures(page, scheme);
        await page.goto(screen.path);
        await expect(page.getByTestId(screen.ready)).toBeVisible();
        if (scheme === 'dark') {
          await expect(page.locator('html')).toHaveClass(/theme-dark/);
        } else {
          await expect(page.locator('html')).not.toHaveClass(/theme-dark/);
        }
        await capture(page, `${screen.key}-${viewport.key}-${scheme}`);
      });
    }
  }
}

test('customers with the column chooser open, desktop, light', async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await serveFixtures(page, 'light');
  await page.goto('/design/customers');
  await page.getByTestId('list-columns').click();
  await expect(page.getByTestId('list-column-toggle-vat')).toBeVisible();
  await capture(page, 'customers-chooser-desktop-light');
});

test('customer form refusing an empty submit, phone, light', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await serveFixtures(page, 'light');
  await page.goto('/design/customers/new');
  await page.getByTestId('design-customer-save').click();
  await expect(page.getByTestId('field-error-name')).toBeVisible();
  await capture(page, 'customer-form-errors-phone-light');
});
