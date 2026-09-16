// SPDX-License-Identifier: AGPL-3.0-or-later
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { forgetPresentationChoices } from './presentation';
import { signIn as signInAsOperator } from './session';

// The design system's quality bar (docs/SPEC.md § 7, 2026-09-13): every screen passes axe's WCAG 2.1 A and AA
// rules in both colour schemes, the shell works at phone width, and the Content Security Policy is never
// violated while it is used. Screens are added here as they are built.

async function signIn(page: Page): Promise<void> {
  await signInAsOperator(page);
  // The scheme a scenario checks must be the default, not a choice a previous scenario left in the database.
  await forgetPresentationChoices(page);
}

async function expectAccessible(page: Page, screen: string): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze();
  const violations = results.violations.map(
    (violation) =>
      `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
  );
  expect(violations, screen).toEqual([]);
}

test('the login page is accessible', async ({ page }) => {
  await page.goto('/login');
  await expect(page.getByTestId('email')).toBeVisible();
  await expectAccessible(page, 'login');
});

test('the shell, the home page and the members page are accessible in light and dark', async ({
  page,
}) => {
  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();
  await expectAccessible(page, 'home, light');

  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await expect(page.getByTestId('members-title')).toBeVisible();
  await expectAccessible(page, 'members, light');

  await page.getByTestId('user-menu').click();
  await page.getByTestId('theme-toggle').click();
  await expect(page.locator('html')).toHaveClass(/theme-dark/);
  // axe must read the page, not the account menu's closing animation over it.
  await expect(page.locator('.mat-mdc-menu-panel')).toHaveCount(0);
  await expectAccessible(page, 'members, dark');
});

test('the shell keeps its content in landmarks, each named once', async ({ page }) => {
  const expectLandmarks = async (screen: string) => {
    const results = await new AxeBuilder({ page })
      .withRules(['region', 'landmark-unique', 'duplicate-id-aria'])
      .analyze();
    expect(
      results.violations.map(
        (violation) =>
          `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
      ),
      screen,
    ).toEqual([]);
  };

  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();
  await expectLandmarks('home');

  // The settings area adds a second navigation beside the sidebar's.
  await page.getByTestId('settings-gear').click();
  await expect(page.getByTestId('settings-nav')).toBeVisible();
  await expectLandmarks('settings');
});

test("at phone width the full navigation is a drawer behind the bottom bar's Plus button", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await signIn(page);
  await page.goto('/members');
  await expect(page.getByTestId('members-title')).toBeVisible();
  // The settings navigation is one row above the page, not a column that pushes it off the screen.
  expect((await page.getByTestId('members-title').boundingBox())?.y ?? Infinity).toBeLessThan(300);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);

  await expect(page.getByTestId('nav-home')).toBeHidden();
  await page.getByTestId('menu-toggle').click();
  await page.getByTestId('nav-home').click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByTestId('nav-home')).toBeHidden();
});

test('the language switch translates the shell and the page', async ({ page }) => {
  await signIn(page);
  await expect(page.getByTestId('nav-home')).toContainText('Accueil');

  await page.getByTestId('user-menu').click();
  await page.getByTestId('language-en').click();

  await expect(page.getByTestId('nav-home')).toContainText('Home');
  await expect(page.getByTestId('greeting')).toContainText('Hello');
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});

// docs/SPEC.md § 8 row 23 (review C8): the screens several goals added were never checked against WCAG. Each is
// reached by its own feature's scenario, but AFTER that scenario's last accessibility assertion — or, for the
// fiscal and settings screens, by a scenario that makes none at all. One walk covers them here, in both colour
// schemes, so that a screen a goal adds is a row in this table rather than an assertion nobody remembers to write.
const WALK: readonly (readonly [string, RegExp])[] = [
  ['/invoices', /\/invoices$/],
  ['/invoices/new', /\/invoices\/new$/],
  ['/company', /\/company$/],
  ['/customers', /\/customers$/],
  ['/customers/groups', /\/customers\/groups$/],
  ['/products', /\/products$/],
  ['/delivery-notes', /\/delivery-notes$/],
  ['/expenses', /\/expenses$/],
  ['/expenses/categories', /\/expenses\/categories$/],
  ['/fiscal/taxes', /\/fiscal\/taxes$/],
  ['/fiscal/units', /\/fiscal\/units$/],
  ['/settings', /\/settings$/],
];

async function walk(page: Page, scheme: string): Promise<void> {
  for (const [route, url] of WALK) {
    await page.goto(route);
    // Without this, a guard that redirected would have axe read the home page nine times over and pass.
    await expect(page, `${route} (${scheme})`).toHaveURL(url);
    // ThemeFacade's effect writes the colour tokens and THEN toggles the class, in that order and in one
    // pass, so the class being on is proof the tokens are too. What can still undo it is ApiSettings.load(),
    // which clears known values to an empty map whenever the scope is re-evaluated: until the chain answers
    // again, the declared default is in force and the page falls back to light. Assert the scheme on every
    // route rather than once, or a late route is read half-reverted and reports a contrast violation that
    // belongs to the transition, not to the screen.
    await expect(page.locator('html'), `${route} is still ${scheme}`).toHaveClass(
      scheme === 'dark' ? /theme-dark/ : /^((?!theme-dark).)*$/,
    );
    await expect(page.getByRole('heading').first(), `${route} (${scheme})`).toBeVisible();
    // Again once the page has drawn: the URL above can match before a module guard sends the person home, and the
    // home page's heading would then be the one found (§ 8 row 10: an invoices module left off passed this way).
    await expect(page, `${route} (${scheme}), still there once drawn`).toHaveURL(url);
    // Nothing is excluded in dark any more: a tab label read 1.08:1, 3.2:1 or 14.42:1 because it was still fading from
    // the light scheme's colour when axe read it, which ThemeFacade now prevents (§ 8 row 28).
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    // Soft, deliberately: a hard assertion stops the walk at the first bad screen and hides every screen
    // after it, so one violation would read as one screen's problem when it may be eight. The run reports
    // them all and still fails.
    expect
      .soft(
        results.violations.map(
          (violation) =>
            `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(' | ')}`,
        ),
        `${route}, ${scheme}`,
      )
      .toEqual([]);
  }
}

test('every screen the goals added is accessible, in both colour schemes', async ({ page }) => {
  // Eighteen axe analyses (nine screens in two schemes) do not fit the 30s default, and the failure is
  // deceptive rather than loud: the test is torn down mid-analysis, axe reports "Test ended", and whatever
  // it had collected so far surfaces as a violation on whichever screen the clock happened to reach. That
  // wandered across four different screens before it was diagnosed — each time looking like a real contrast
  // defect on a real page. Measured: the run needs a little over half a minute.
  test.setTimeout(180_000);

  // A table that silently lost its rows would pass while checking nothing.
  expect(WALK.length).toBeGreaterThanOrEqual(9);

  await signIn(page);
  await walk(page, 'light');

  await page.goto('/');
  // The scheme is a stored setting written optimistically: ApiSettings.set() keeps the choice in state and
  // fires the PUT without awaiting it. Its load() — which runs whenever the scope is re-evaluated — starts
  // by clearing known values to an empty map before the chain answers, so a navigation early enough falls
  // back to the declared default and the page reverts to light mid-walk. That reads as an intermittent
  // contrast violation on whichever screen happened to paint during the gap. Await the write first.
  const stored = page.waitForResponse(
    (response) =>
      /\/settings\/presentation\.scheme$/.test(response.url()) &&
      response.request().method() !== 'GET',
  );
  await page.getByTestId('user-menu').click();
  await page.getByTestId('theme-toggle').click();
  expect((await stored).status()).toBe(200);
  await expect(page.locator('html')).toHaveClass(/theme-dark/);
  // axe must read the page, not the account menu's closing animation over it.
  await expect(page.locator('.mat-mdc-menu-panel')).toHaveCount(0);
  await walk(page, 'dark');
  // And it must still be dark at the end, or the screens above were not all read in dark.
  await expect(page.locator('html'), 'the scheme held for the whole dark walk').toHaveClass(
    /theme-dark/,
  );

  // Put the scheme back. This walk is the test that MUST end where it started, because it is the only one
  // asserting the scheme on every route — but it is not the only one that changes it: the members walk and
  // the CSP test below both toggle to dark and leave it there, so after a full run of this file the stored
  // scheme is dark. What actually protects each scenario is signIn's own forgetPresentationChoices, not
  // this line.
  // Inline, after every assertion, rather than in a file-wide `afterEach` — an afterEach would also run after
  // the signed-out tests, where `/api/auth/me` answers `authentication_required` with no `company` key at
  // all, and `forgetPresentationChoices` guards `me.company === null`, which `undefined` passes straight
  // through into `me.company.id`.
  await forgetPresentationChoices(page);
});

// The company switcher is deliberately NOT here: `company-switcher.html` renders it only when
// `companies().length > 1`, and the seeded operator belongs to one company, so the control does not exist on
// this fixture. Asserting it would be asserting a screen the suite cannot reach. It is reached in
// company.spec.ts, which opens a second company; its accessibility belongs there if anywhere.
test('the notification centre is accessible', async ({ page }) => {
  await signIn(page);
  // The shell must have painted before the bell is there to click: signIn lands on the home page, whose
  // greeting is the last thing it renders.
  await expect(page.getByTestId('greeting')).toBeVisible();

  await page.getByTestId('notification-bell').click();
  await expect(page.locator('.notification-panel')).toBeVisible();
  await expectAccessible(page, 'notification centre');
});

test('using the shell raises no Content Security Policy violation', async ({ page }) => {
  const violations: string[] = [];
  page.on('console', (message) => {
    if (/content security policy/i.test(message.text())) {
      violations.push(message.text());
    }
  });

  await signIn(page);
  await page.getByTestId('settings-gear').click();
  await page.getByTestId('nav-members').click();
  await page.getByTestId('user-menu').click();
  await page.getByTestId('theme-toggle').click();
  await page.reload();
  await expect(page.getByTestId('members-title')).toBeVisible();

  expect(violations).toEqual([]);
});

test('Ctrl K opens the command palette, which is accessible and takes the person where they typed', async ({
  page,
}) => {
  await signIn(page);
  await expect(page.getByTestId('greeting')).toBeVisible();

  await page.keyboard.press('Control+k');
  const input = page.getByTestId('command-input');
  await expect(input).toBeFocused();
  await expect(page.getByTestId('command-goto-home')).toBeVisible();
  await expectAccessible(page, 'command palette');

  await input.fill('nouveau client');
  await expect(page.getByRole('option')).toHaveCount(1);
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/customers\/new$/);
  await expect(page.getByTestId('command-palette')).toHaveCount(0);

  // The button in the top bar opens it too, and Escape closes it where it was.
  await page.getByTestId('command-open').click();
  await expect(input).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('command-palette')).toHaveCount(0);
  await expect(page).toHaveURL(/\/customers\/new$/);
});
