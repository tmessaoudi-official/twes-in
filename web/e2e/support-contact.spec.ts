// SPDX-License-Identifier: AGPL-3.0-or-later
import { expect, test, type Page } from '@playwright/test';
import { wcagViolations } from './axe';
import { OPERATOR_PASSWORD, signIn } from './session';
import { proveIfHeldBack, sendProving } from './step-up';
import { toast } from './toast';

// How a company reaches the people who run the platform: the operators set a phone, a WhatsApp number and an address
// on the platform page, and every screen's help offers them under « Nous contacter », with the build support asks for.
const CHANNELS = ['phone', 'whatsapp', 'email'] as const;
type Contact = Record<(typeof CHANNELS)[number], string>;

test('the contact the operators set is offered in the help, a channel left empty not at all', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await signIn(page);
  // Every platform write waits for the operator's recent proof (docs/SPEC.md § 7), given only when asked: every
  // attempt counts against the operator's five in five minutes.
  const before = await readContact(page);
  try {
    await page.goto('/platform');
    const card = page.getByTestId('platform-support');
    // A wrong value is said once its field is left, before anything is saved or a password asked.
    await card.getByTestId('field-phone').fill('------');
    await card.getByTestId('field-phone').press('Tab');
    await expect(card.getByTestId('field-error-phone')).toContainText('6');
    await card.getByTestId('field-phone').fill('+216 71 000 000');
    await expect(card.getByTestId('field-error-phone')).toHaveCount(0);
    await card.getByTestId('field-whatsapp').fill('+216 20 123 456');
    await card.getByTestId('field-email').fill('');
    const saved = page.waitForResponse(
      (response) =>
        // A form's keys are saved together, in one request.
        new URL(response.url()).pathname === '/api/platform/settings' &&
        response.request().method() === 'PUT',
    );
    await page.getByTestId('platform-support-save').click();
    await proveIfHeldBack(page, OPERATOR_PASSWORD, saved);
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
  for (const [channel, value] of Object.entries(contact)) {
    const url = `/api/platform/settings/support.${channel}`;
    const answer = await sendProving(page, OPERATOR_PASSWORD, 'PUT', url, { value });
    if (answer.status < 200 || answer.status > 299)
      throw new Error(`restoring support.${channel} answered ${answer.status}`);
  }
}
