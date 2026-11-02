// SPDX-License-Identifier: AGPL-3.0-or-later

import { expect, test, type Page } from '@playwright/test';
import { inACompany, signIn } from './session';

const CSRF = '0123456789abcdef0123456789abcdef';

/**
 * A new identity is a change of the design tokens, never of the screens (docs/SPEC.md § 7, 2026-11-02 00:32): every
 * family of screens draws its type, weights, corners and elevations from the variables tailwind.css and styles.scss
 * declare on the root. Each family's page is read, every one of those variables is then given a sentinel value, and
 * the page is read again: a family whose drawing does not move draws from somewhere else, and a family font left on
 * an element names that element. The sentinels are values no screen uses, so a leftover is a literal.
 */

const SENTINEL_FONT = 'TwesSentinelFont';

/** The root variables a screen's look is made of, overridden with values nothing else uses. */
async function swapTokens(page: Page): Promise<number> {
  return page.evaluate((font) => {
    const names = new Set<string>();
    const visit = (rules: CSSRuleList) => {
      for (const rule of Array.from(rules)) {
        if (
          rule instanceof CSSStyleRule &&
          /(^|,)\s*(:root|:host|html)\b/.test(rule.selectorText)
        ) {
          for (const name of Array.from(rule.style)) {
            if (name.startsWith('--')) names.add(name);
          }
        } else if ('cssRules' in rule) {
          visit((rule as CSSGroupingRule).cssRules);
        }
      }
    };
    for (const sheet of Array.from(document.styleSheets)) {
      try {
        visit(sheet.cssRules);
      } catch {
        // settled-read: a stylesheet from another origin is not read; the application serves none.
      }
    }
    const value = (name: string): string | null => {
      if (/^--font-weight-/.test(name)) return '321';
      if (
        /^--(font-(sans|display|mono)|default-font-family|default-mono-font-family)$/.test(name)
      ) {
        return `"${font}", fantasy`;
      }
      if (/^--text-[a-z0-9-]+--line-height$/.test(name)) return '1.37';
      if (/^--text-[a-z0-9-]+--letter-spacing$/.test(name)) return '0.0137em';
      if (/^--text-[a-z0-9-]+--font-weight$/.test(name)) return '321';
      if (/^--text-[a-z0-9-]+$/.test(name)) return '13.7px';
      if (/^--radius-/.test(name)) return '13.7px';
      if (/^--(shadow|twes-shadow)-/.test(name)) return '0 0 0 3.7px rgb(1, 2, 3)';
      return null;
    };
    const lines: string[] = [];
    for (const name of names) {
      const v = value(name);
      if (v !== null) lines.push(`${name}: ${v};`);
    }
    const style = document.createElement('style');
    style.dataset['sentinel'] = '';
    style.textContent = `:root:root:root { ${lines.join(' ')} }`;
    document.head.append(style);
    return lines.length;
  }, SENTINEL_FONT);
}

interface Look {
  fonts: string[];
  sizes: string[];
  weights: string[];
  radii: string[];
  shadows: string[];
  /** Once swapped: each element still in another font, or with a size, weight, corner or shadow the page drew before. */
  kept: string[];
}

/**
 * Every visible element's type, weight, corners and elevation, as sets. Given the look before the swap, it also names
 * the elements that kept a value of it, or a font that is not the sentinel's: each is drawn from no token.
 */
async function look(page: Page, was: Look | null): Promise<Look> {
  return page.evaluate(
    ({ font, was }) => {
      const sets = {
        fonts: new Set<string>(),
        sizes: new Set<string>(),
        weights: new Set<string>(),
        radii: new Set<string>(),
        shadows: new Set<string>(),
      };
      const kept = new Map<string, string[]>();
      const keep = (what: string, element: HTMLElement) => {
        const classes =
          typeof element.className === 'string' && element.className.trim() !== ''
            ? '.' + element.className.trim().split(/\s+/).slice(0, 2).join('.')
            : '';
        const name = element.dataset['testid'] ?? element.tagName.toLowerCase() + classes;
        const names = kept.get(what) ?? [];
        if (names.length < 3 && !names.includes(name)) names.push(name);
        kept.set(what, names);
      };
      for (const element of Array.from(document.body.querySelectorAll<HTMLElement>('*'))) {
        if (element.closest('[data-sentinel], script, style, svg, .cdk-visually-hidden')) continue;
        const box = element.getBoundingClientRect();
        if (box.width === 0 || box.height === 0) continue;
        const css = getComputedStyle(element);
        if (css.visibility === 'hidden') continue;
        const hasText = Array.from(element.childNodes).some(
          (n) => n.nodeType === 3 && n.textContent!.trim() !== '',
        );
        if (hasText && !/Material Symbols/.test(css.fontFamily)) {
          sets.fonts.add(css.fontFamily);
          sets.sizes.add(css.fontSize);
          sets.weights.add(css.fontWeight);
          if (was) {
            if (!css.fontFamily.includes(font)) keep(`font ${css.fontFamily}`, element);
            // The browser's own 16px and 400, which no rule declares, draw the text no class sizes.
            if (was.sizes.includes(css.fontSize) && css.fontSize !== '16px') {
              keep(`size ${css.fontSize}`, element);
            }
            if (was.weights.includes(css.fontWeight) && css.fontWeight !== '400') {
              keep(`weight ${css.fontWeight}`, element);
            }
          }
        }
        // A corner is seen only on what draws a ground or a border. A circle (50 %, a dot, a radio, a ripple) is
        // geometry in every identity, and Material's checkbox box has a 2px corner of its own it offers no token for.
        const drawn =
          css.backgroundColor !== 'rgba(0, 0, 0, 0)' ||
          css.backgroundImage !== 'none' ||
          parseFloat(css.borderTopWidth) > 0;
        const geometry =
          css.borderTopLeftRadius === '50%' ||
          element.classList.contains('mdc-checkbox__background');
        if (css.borderTopLeftRadius !== '0px' && drawn && !geometry) {
          sets.radii.add(css.borderTopLeftRadius);
          if (was?.radii.includes(css.borderTopLeftRadius)) {
            keep(`radius ${css.borderTopLeftRadius}`, element);
          }
        }
        // A shadow whose every length is zero draws nothing (Material's elevation 0).
        if (
          css.boxShadow !== 'none' &&
          /(?<![\d.])(?:[1-9]\d*|\d*\.\d*[1-9])\d*px/.test(css.boxShadow)
        ) {
          sets.shadows.add(css.boxShadow);
          if (was?.shadows.includes(css.boxShadow)) keep(`shadow ${css.boxShadow}`, element);
        }
      }
      const list = (s: Set<string>) => [...s].sort();
      return {
        fonts: list(sets.fonts),
        sizes: list(sets.sizes),
        weights: list(sets.weights),
        radii: list(sets.radii),
        shadows: list(sets.shadows),
        kept: [...kept].map(([what, names]) => `${what}: ${names.join(', ')}`).sort(),
      };
    },
    { font: SENTINEL_FONT, was },
  );
}

/** Reads a family's page, swaps the tokens and asks that its drawing moved and no text kept another font. */
async function swapsWhole(page: Page, family: string): Promise<void> {
  // settled-read: every family's page is waited for by its caller before it is read.
  const before = await look(page, null);
  expect(await swapTokens(page), `${family}: the root declares its tokens`).toBeGreaterThan(40);
  await expect
    .poll(async () => (await look(page, before)).sizes.join('|'), {
      message: `${family}: the type moves`,
    })
    .not.toBe(before.sizes.join('|'));
  const after = await look(page, before);
  expect(after.kept, `${family}: drawn from no token`).toEqual([]);
  test.info().annotations.push({
    type: 'design-tokens',
    description: `${family}: sizes ${before.sizes.length}→${after.sizes.join(' ')}; radii ${after.radii.join(' ')}; shadows ${after.shadows.length}`,
  });
}

test('signed out, the sign-in, the phone scanner, the customer display and the legal pages draw from the tokens', async ({
  page,
}) => {
  for (const [family, path, landmark] of [
    ['sign-in', '/login', 'email'],
    ['phone-scanner', '/pair', null],
    ['customer-facing', '/customer-display', null],
    ['legal', '/legal/privacy', null],
  ] as const) {
    await page.goto(path);
    if (landmark) await expect(page.getByTestId(landmark)).toBeVisible({ timeout: 20_000 });
    else await expect(page.locator('main, [role="main"], body > *').first()).toBeVisible();
    await page.waitForLoadState('networkidle');
    await swapsWhole(page, family);
  }
});

const SIGNED_IN: readonly (readonly [string, string])[] = [
  ['home', '/'],
  ['watch', '/watch'],
  ['settings', '/company'],
  ['list', '/invoices'],
  ['dialogs-toasts-empty', '/not-allowed'],
  ['customers-list', '/customers'],
  ['product-page', '/products/new'],
  ['list-editor', '/products/categories'],
  ['editor', '/invoices/new'],
  ['delivery-notes', '/delivery-notes'],
  ['stock', '/stock'],
  ['stock-count', '/stock/count'],
  ['stock-plan', '/stock/plan'],
  ['import-wizard', '/imports/customers'],
  ['reports', '/reports'],
  ['platform', '/platform'],
  ['recently-deleted', '/company/recently-deleted'],
];

for (const [family, path] of SIGNED_IN) {
  test(`signed in, ${family} (${path}) draws from the tokens`, async ({ page }) => {
    await signIn(page);
    await inACompany(page, CSRF);
    await page.goto(path);
    await expect(page.locator('main').first()).toBeVisible({ timeout: 20_000 });
    await page.waitForLoadState('networkidle');
    await swapsWhole(page, family);
  });
}

test('signed in, a record read from the invoices list draws from the tokens', async ({ page }) => {
  await signIn(page);
  await inACompany(page, CSRF);
  await page.goto('/invoices');
  const first = page.getByTestId(/^invoice-[0-9a-f-]{36}$/).first();
  await expect(first, "the scenarios' company holds invoices").toBeVisible({ timeout: 20_000 });
  const id = (await first.getAttribute('data-testid'))!.replace(/^invoice-/, '');
  await page.goto(`/invoices/${id}`);
  await expect(page.locator('main').first()).toBeVisible({ timeout: 20_000 });
  await page.waitForLoadState('networkidle');
  await swapsWhole(page, 'record-read');
});
