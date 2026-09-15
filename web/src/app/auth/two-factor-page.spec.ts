// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from './auth-facade';
import type { SignedInState } from './auth-types';
import { TwoFactorPage } from './two-factor-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      auth: {
        two_factor: { title: 'Vérification en deux étapes' },
        errors: { invalid_code: 'Code incorrect' },
      },
    });
  }
}

const SECRET = 'JBSWY3DPEHPK3PXP';
const URI = `otpauth://totp/twes-in:owner%40example.test?secret=${SECRET}&issuer=twes-in`;

function signedIn(mfa: { enrolled: boolean; required: boolean }): SignedInState {
  return {
    user: {
      id: '1',
      email: 'owner@example.test',
      displayName: 'Owner',
      locale: 'fr',
      isPlatformOperator: false,
    },
    company: null,
    permissions: [],
    modules: [],
    mfa,
  };
}

describe('TwoFactorPage', () => {
  let facade: {
    me: ReturnType<typeof signal<SignedInState | null>>;
    beginTotpEnrolment: ReturnType<typeof vi.fn>;
    confirmTotpEnrolment: ReturnType<typeof vi.fn>;
  };

  async function render(mfa: { enrolled: boolean; required: boolean }) {
    facade = {
      me: signal<SignedInState | null>(signedIn(mfa)),
      beginTotpEnrolment: vi.fn().mockResolvedValue({
        ok: true,
        enrolment: { secret: SECRET, provisioningUri: URI },
      }),
      confirmTotpEnrolment: vi.fn(),
    };
    TestBed.configureTestingModule({
      imports: [TwoFactorPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: facade },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
    const fixture = TestBed.createComponent(TwoFactorPage);
    await settle(fixture);
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, el, query };
  }

  async function settle(fixture: { whenStable(): Promise<unknown> }) {
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await fixture.whenStable();
  }

  async function submitCode(rendered: Awaited<ReturnType<typeof render>>, code: string) {
    const input = rendered.query<HTMLInputElement>('two-factor-code')!;
    input.value = code;
    input.dispatchEvent(new Event('input'));
    rendered.query<HTMLFormElement>('two-factor-form')?.dispatchEvent(new Event('submit'));
    await settle(rendered.fixture);
  }

  it('starts an enrolment on arrival and shows the QR code and the secret to type in by hand', async () => {
    const { query } = await render({ enrolled: false, required: true });

    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
    expect(query('two-factor-qr')?.querySelector('svg rect[data-module]')).not.toBeNull();
    expect(query('two-factor-secret')?.textContent?.replace(/\s/g, '')).toBe(SECRET);
  });

  it('confirms with a code, shows the recovery codes once, then continues home', async () => {
    const rendered = await render({ enrolled: false, required: true });
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    facade.confirmTotpEnrolment.mockResolvedValue({
      ok: true,
      recoveryCodes: ['aaaa-bbbb', 'cccc-dddd'],
    });

    await submitCode(rendered, '123456');

    expect(facade.confirmTotpEnrolment).toHaveBeenCalledWith('123456');
    expect(rendered.query('two-factor-form')).toBeNull();
    const codes = [...(rendered.query('two-factor-recovery-codes')?.querySelectorAll('li') ?? [])];
    expect(codes.map((item) => item.textContent?.trim())).toEqual(['aaaa-bbbb', 'cccc-dddd']);

    rendered.query<HTMLButtonElement>('two-factor-continue')?.click();
    await settle(rendered.fixture);
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('shows a refused code, clears it and keeps the same secret on screen', async () => {
    const rendered = await render({ enrolled: false, required: false });
    facade.confirmTotpEnrolment.mockResolvedValue({ ok: false, error: 'invalid_code' });

    await submitCode(rendered, '000000');

    expect(rendered.query('two-factor-error')?.textContent).toContain('Code incorrect');
    expect(rendered.query<HTMLInputElement>('two-factor-code')?.value).toBe('');
    expect(rendered.query('two-factor-secret')?.textContent?.replace(/\s/g, '')).toBe(SECRET);
    expect(facade.beginTotpEnrolment).toHaveBeenCalledTimes(1);
  });

  it('says an authenticator is already on, and starts nothing', async () => {
    const { query } = await render({ enrolled: true, required: false });

    expect(query('two-factor-enabled')).not.toBeNull();
    expect(query('two-factor-form')).toBeNull();
    expect(facade.beginTotpEnrolment).not.toHaveBeenCalled();
  });
});
