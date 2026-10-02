// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { ForgotPasswordPage } from './forgot-password-page';
import { PasswordResetFacade } from './password-reset-facade';
import type { PasswordResetError } from './password-reset-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      password_reset: {
        forgot: { sent: 'Un lien est en route.', email_invalid: 'Adresse invalide' },
        errors: { too_many_attempts: 'Trop de demandes' },
        back_to_login: 'Retour à la connexion',
      },
    });
  }
}

describe('ForgotPasswordPage', () => {
  const requested = signal(false);
  const busy = signal(false);
  const error = signal<PasswordResetError | null>(null);
  const facade = { requested, busy, error, request: vi.fn() };

  beforeEach(async () => {
    requested.set(false);
    busy.set(false);
    error.set(null);
    facade.request.mockReset().mockImplementation(async () => requested.set(true));
    await TestBed.configureTestingModule({
      imports: [ForgotPasswordPage],
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        provideRouter([]),
        { provide: PasswordResetFacade, useValue: facade },
        { provide: LanguageFacade, useValue: { current: signal('en') } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(ForgotPasswordPage);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  function type(input: HTMLInputElement, value: string) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  it('asks for the link in the language the page is read in, then says the neutral sentence', async () => {
    const { fixture, query } = await render();

    type(query<HTMLInputElement>('forgot-email')!, ' someone@example.test ');
    query<HTMLButtonElement>('forgot-submit')!.click();
    await fixture.whenStable();

    expect(facade.request).toHaveBeenCalledWith('someone@example.test', 'en');
    expect(query('forgot-sent')?.textContent).toContain('Un lien est en route.');
    expect(query('forgot-form')).toBeNull();
  });

  it('does not ask for an address that is not one', async () => {
    const { fixture, query } = await render();

    type(query<HTMLInputElement>('forgot-email')!, 'nope');
    query<HTMLButtonElement>('forgot-submit')!.click();
    await fixture.whenStable();

    expect(facade.request).not.toHaveBeenCalled();
    expect(fixture.nativeElement.textContent).toContain('Adresse invalide');
  });

  it('says why a request was refused and keeps the form', async () => {
    error.set('too_many_attempts');

    const { query } = await render();

    expect(query('forgot-error')?.textContent).toContain('Trop de demandes');
    expect(query('forgot-form')).not.toBeNull();
  });

  it('offers the way back to sign in', async () => {
    const { query } = await render();

    expect(query('forgot-back')?.getAttribute('href')).toBe('/login');
  });
});
