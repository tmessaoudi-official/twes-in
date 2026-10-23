// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal, type Type } from '@angular/core';
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
import { PasswordResetApi, PasswordResetRefused } from './password-reset-api';
import { ResetPasswordPage } from './reset-password-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      password_reset: {
        forgot: { sent: 'Un lien est en route.' },
        errors: { too_many_attempts: 'Trop de demandes', link_not_usable: 'Lien expiré' },
      },
    });
  }
}

/**
 * The facade both pages read lives as long as the application: these cases keep ONE injector across visits, as the
 * browser does between two navigations, so a page opened again must not read what the previous visit left behind.
 */
describe('Getting back into an account, visited again', () => {
  const api = { forgot: vi.fn(), reset: vi.fn() };

  beforeEach(async () => {
    api.forgot.mockReset().mockResolvedValue(undefined);
    api.reset.mockReset().mockResolvedValue(undefined);
    await TestBed.configureTestingModule({
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        provideRouter([]),
        { provide: PasswordResetApi, useValue: api },
        { provide: LanguageFacade, useValue: { current: signal('fr') } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function visit<T>(page: Type<T>, token?: string) {
    const fixture = TestBed.createComponent(page);
    if (token !== undefined) fixture.componentRef.setInput('token', token);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <E extends HTMLElement>(id: string) =>
      el.querySelector<E>(`[data-testid="${id}"]`);
    return { fixture, query };
  }

  async function ask(
    query: Awaited<ReturnType<typeof visit>>['query'],
    fixture: { whenStable(): Promise<unknown> },
  ) {
    const input = query<HTMLInputElement>('forgot-email')!;
    input.value = 'someone@example.test';
    input.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('forgot-submit')!.click();
    await fixture.whenStable();
  }

  it('shows a fresh form, not the earlier « sent », when the page is opened again', async () => {
    const first = await visit(ForgotPasswordPage);
    await ask(first.query, first.fixture);
    expect(first.query('forgot-sent')).not.toBeNull();
    first.fixture.destroy();

    const again = await visit(ForgotPasswordPage);

    expect(again.query('forgot-sent')).toBeNull();
    expect(again.query('forgot-form')).not.toBeNull();
  });

  it('forgets a refused request when the page is opened again', async () => {
    api.forgot.mockRejectedValueOnce(new PasswordResetRefused('too_many_attempts'));
    const first = await visit(ForgotPasswordPage);
    await ask(first.query, first.fixture);
    expect(first.query('forgot-error')).not.toBeNull();
    first.fixture.destroy();

    const again = await visit(ForgotPasswordPage);

    expect(again.query('forgot-error')).toBeNull();
    expect(again.query('forgot-form')).not.toBeNull();
  });

  it('does not carry an expired link’s refusal to the page asking for a new one', async () => {
    api.reset.mockRejectedValueOnce(new PasswordResetRefused('link_not_usable'));
    const link = await visit(ResetPasswordPage, 'an-old-token');
    for (const id of ['reset-password', 'reset-again']) {
      const input = link.query<HTMLInputElement>(id)!;
      input.value = 'a-long-enough-password';
      input.dispatchEvent(new Event('input'));
    }
    link.query<HTMLButtonElement>('reset-submit')!.click();
    await link.fixture.whenStable();
    expect(link.query('reset-error')).not.toBeNull();
    link.fixture.destroy();

    const forgot = await visit(ForgotPasswordPage);

    expect(forgot.query('forgot-error')).toBeNull();
  });

  it('does not carry the forgot page’s refusal to a link opened next', async () => {
    api.forgot.mockRejectedValueOnce(new PasswordResetRefused('too_many_attempts'));
    const forgot = await visit(ForgotPasswordPage);
    await ask(forgot.query, forgot.fixture);
    expect(forgot.query('forgot-error')).not.toBeNull();
    forgot.fixture.destroy();

    const link = await visit(ResetPasswordPage, 'a-token');

    expect(link.query('reset-error')).toBeNull();
    expect(link.query('reset-form')).not.toBeNull();
  });
});
