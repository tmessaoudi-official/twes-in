// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { wcagViolations } from './axe';
import { OPERATOR_PASSWORD, signIn } from './session';
import { proveByApi } from './step-up';
import { toast } from './toast';

// How a company reaches the people who run the platform: the operators set a phone, a WhatsApp number and an address
// on the platform page, and every screen's help offers them under « Nous contacter », with the build support asks for.
const CSRF = '0123456789abcdef0123456789abcdef';
const CHANNELS = ['phone', 'whatsapp', 'email'] as const;
type Contact = Record<(typeof CHANNELS)[number], string>;

test('the contact the operators set is offered in the help, a channel left empty not at all', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await signIn(page);
  // Every platform write waits for the operator's recent proof (docs/SPEC.md § 7); one holds the scenario through.
  await proveByApi(page, OPERATOR_PASSWORD);
  const before = await readContact(page);
  try {
    await page.goto('/platform');
    await page.getByTestId('platform-support-phone').fill('+216 71 000 000');
    await page.getByTestId('platform-support-whatsapp').fill('+216 20 123 456');
    await page.getByTestId('platform-support-email').fill('');
    await page.getByTestId('platform-support-save').click();
    await expect(toast(page)).toBeVisible();

    await page.getByTestId('help-open').click();
    const help = page.getByRole('dialog');
    await expect(help.getByTestId('help-contact')).toBeVisible();
    await expect(help.getByTestId('help-contact-phone')).toHaveAttribute(
      'href',
      'tel:+21671000000',
    );
    await expect(help.getByTestId('help-contact-whatsapp')).toHaveAttribute(
      'href',
      'https://wa.me/21620123456',
    );
    await expect(help.getByTestId('help-contact-email')).toHaveCount(0);
    await expect(help.getByTestId('help-build').getByTestId('build-line')).toBeVisible();
    expect(await wcagViolations(page)).toEqual([]);
  } finally {
    await writeContact(page, before);
  }
});

async function readContact(page: Page): Promise<Contact> {
  return page.evaluate(async (channels) => {
    const answer = await fetch('/api/platform/settings');
    if (!answer.ok) throw new Error(`reading the platform settings answered ${answer.status}`);
    const rows = (await answer.json()) as { key: string; value: unknown }[];
    const valueOf = (channel: string) => {
      const value = rows.find((row) => row.key === `support.${channel}`)?.value;
      return typeof value === 'string' ? value : '';
    };
    return Object.fromEntries(channels.map((channel) => [channel, valueOf(channel)])) as Contact;
  }, CHANNELS);
}

/** Puts each channel back as the run found it, so the shared platform is left as it was. */
async function writeContact(page: Page, contact: Contact): Promise<void> {
  await page.evaluate(
    async ({ contact, token }) => {
      for (const [channel, value] of Object.entries(contact)) {
        const answer = await fetch(`/api/platform/settings/support.${channel}`, {
          method: 'PUT',
          headers: { 'content-type': 'application/json', 'csrf-token': token },
          body: JSON.stringify({ value }),
        });
        if (!answer.ok) throw new Error(`restoring support.${channel} answered ${answer.status}`);
      }
    },
    { contact, token: CSRF },
  );
}
