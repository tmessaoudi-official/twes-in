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
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { provideKnownPasswordRule } from '../shared/testing/password-rule';
import { PasswordResetFacade } from './password-reset-facade';
import type { PasswordResetError } from './password-reset-types';
import { ResetPasswordPage } from './reset-password-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      password_reset: {
        reset: { done: 'Mot de passe changé.', password_hint: 'Au moins {{min}} caractères.' },
        errors: {
          mismatch: 'Pas identiques',
          link_not_usable: 'Lien périmé',
          too_short: 'Au moins {{min}} caractères, pas moins.',
        },
      },
    });
  }
}

describe('ResetPasswordPage', () => {
  const busy = signal(false);
  const error = signal<PasswordResetError | null>(null);
  const facade = { busy, error, reset: vi.fn(), forget: vi.fn() };
  const LONG = 'a-long-enough-password';

  beforeEach(async () => {
    busy.set(false);
    error.set(null);
    facade.reset.mockReset().mockResolvedValue(true);
    await TestBed.configureTestingModule({
      imports: [ResetPasswordPage],
      providers: [
        // Fourteen rather than the API's twelve: the page says the number it reads, never one of its own.
        provideKnownPasswordRule({ minLength: 14, maxLength: 4096 }),
        provideStillAppearance(),
        provideQuietFeedback(),
        provideRouter([]),
        { provide: PasswordResetFacade, useValue: facade },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(ResetPasswordPage);
    fixture.componentRef.setInput('token', 'the-token');
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

  async function submit(
    fixture: { whenStable(): Promise<unknown> },
    query: <T extends HTMLElement>(id: string) => T | null,
    password: string,
    again: string,
  ) {
    type(query<HTMLInputElement>('reset-password')!, password);
    type(query<HTMLInputElement>('reset-again')!, again);
    query<HTMLButtonElement>('reset-submit')!.click();
    await fixture.whenStable();
  }

  it('sends the link token with the new password and then offers sign-in', async () => {
    const { fixture, query } = await render();

    await submit(fixture, query, LONG, LONG);

    expect(facade.reset).toHaveBeenCalledWith('the-token', LONG);
    expect(query('reset-done')?.textContent).toContain('Mot de passe changé.');
    expect(query('reset-signin')?.getAttribute('href')).toBe('/login');
    expect(query('reset-form')).toBeNull();
  });

  it('never sends two entries that differ, since a typo would lock the person out', async () => {
    const { fixture, query } = await render();

    await submit(fixture, query, LONG, LONG + 'x');

    expect(facade.reset).not.toHaveBeenCalled();
    expect(query('reset-mismatch')?.textContent).toContain('Pas identiques');
  });

  it('does not send a password shorter than the API takes, and says the length it read', async () => {
    const { fixture, query } = await render();
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Au moins 14 caractères.');

    // Thirteen characters: what the API's own twelve would take, and the rule read refuses.
    await submit(fixture, query, 'treize lettre', 'treize lettre');
    fixture.detectChanges();

    expect(facade.reset).not.toHaveBeenCalled();
    expect(query('reset-password-error')?.textContent).toContain(
      'Au moins 14 caractères, pas moins.',
    );
  });

  it('says a link that cannot be used is, and keeps the form with the way to ask again', async () => {
    facade.reset.mockResolvedValue(false);
    error.set('link_not_usable');

    const { fixture, query } = await render();
    await submit(fixture, query, LONG, LONG);

    expect(query('reset-error')?.textContent).toContain('Lien périmé');
    expect(query('reset-done')).toBeNull();
    expect(query('reset-ask-again')?.getAttribute('href')).toBe('/forgot-password');
  });
});
