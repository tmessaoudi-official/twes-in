// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Page } from '@playwright/test';

const FIELD_OFFER = 'e2e-passkeys-under-the-field';

/**
 * Chromium's virtual authenticator: a platform authenticator that keeps its passkeys and verifies the user.
 *
 * It answers every request at once, the one the address field starts too, which a person only answers by picking a
 * passkey under the field. Left on, that request signs in or is refused on its own whenever the page reaches the field,
 * before or after whatever the scenario clicks. So the field offers nothing here until a scenario asks for it with
 * `passkeysUnderTheField(page, true)`.
 */
export async function addVirtualAuthenticator(page: Page): Promise<void> {
  await page.addInitScript((key) => {
    if (typeof PublicKeyCredential === 'undefined') return;
    const available =
      PublicKeyCredential.isConditionalMediationAvailable?.bind(PublicKeyCredential);
    PublicKeyCredential.isConditionalMediationAvailable = async () =>
      sessionStorage.getItem(key) === 'on' && available !== undefined && (await available());
  }, FIELD_OFFER);
  const cdp = await page.context().newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });
}

/** Whether the address field offers this browser's passkeys, read each time the login page reaches it. */
export async function passkeysUnderTheField(page: Page, on: boolean): Promise<void> {
  await page.evaluate(
    ([key, value]) => {
      if (value) sessionStorage.setItem(key, 'on');
      else sessionStorage.removeItem(key);
    },
    [FIELD_OFFER, on] as const,
  );
}
