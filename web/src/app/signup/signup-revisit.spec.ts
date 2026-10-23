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
import { FinishSignupPage } from './finish-signup-page';
import { SignupApi, SignupRefused } from './signup-api';
import { SignupPage } from './signup-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      signup: {
        request: { sent: 'Un lien est en route.' },
        finish: { errors: { not_usable: 'Lien invalide', too_many: 'Trop' } },
        errors: { too_many: 'Trop' },
      },
    });
  }
}

/**
 * The signup facade lives as long as the application: one injector is kept across visits, as the browser keeps it
 * between two navigations, so a page opened again must not read what the previous visit left behind.
 */
describe('Signing up, visited again', () => {
  const api = { availability: vi.fn(), request: vi.fn(), link: vi.fn(), complete: vi.fn() };

  beforeEach(async () => {
    api.availability.mockReset().mockResolvedValue({ enabled: true, countries: ['TN'] });
    api.request.mockReset().mockResolvedValue(undefined);
    api.link.mockReset().mockImplementation(async (token: string) => `${token}@example.test`);
    api.complete.mockReset().mockResolvedValue({ companyName: 'Acme', companyStatus: 'active' });
    await TestBed.configureTestingModule({
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        provideRouter([]),
        { provide: SignupApi, useValue: api },
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

  function type(input: HTMLInputElement | null, value: string): void {
    input!.value = value;
    input!.dispatchEvent(new Event('input'));
  }

  async function ask(visited: Awaited<ReturnType<typeof visit>>): Promise<void> {
    type(visited.query<HTMLInputElement>('signup-email'), 'new@example.test');
    visited.query<HTMLButtonElement>('signup-submit')!.click();
    await visited.fixture.whenStable();
  }

  it('shows a fresh form, not the earlier « sent », when the page is opened again', async () => {
    const first = await visit(SignupPage);
    await ask(first);
    expect(first.query('signup-sent')).not.toBeNull();
    first.fixture.destroy();

    const again = await visit(SignupPage);

    expect(again.query('signup-sent')).toBeNull();
    expect(again.query('signup-form')).not.toBeNull();
  });

  it('forgets a refused request when the page is opened again', async () => {
    api.request.mockRejectedValueOnce(new SignupRefused('too_many'));
    const first = await visit(SignupPage);
    await ask(first);
    expect(first.query('signup-error')).not.toBeNull();
    first.fixture.destroy();

    const again = await visit(SignupPage);

    expect(again.query('signup-error')).toBeNull();
    expect(again.query('signup-form')).not.toBeNull();
  });

  it('opens a second link on its own form, not on the first one’s completion', async () => {
    const first = await visit(FinishSignupPage, 'first');
    first.fixture.componentInstance['form'].setValue({
      displayName: 'New Person',
      password: 'a-long-enough-password',
      companyName: 'Acme',
      countryCode: 'TN',
      timezone: 'Africa/Tunis',
    });
    first.query<HTMLButtonElement>('signup-finish-submit')!.click();
    await first.fixture.whenStable();
    expect(first.query('signup-completed')).not.toBeNull();
    first.fixture.destroy();

    const second = await visit(FinishSignupPage, 'second');

    expect(second.query('signup-completed')).toBeNull();
    expect(second.query('signup-finish-email')?.textContent).toContain('second@example.test');
  });
});
