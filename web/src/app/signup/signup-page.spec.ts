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
import { SignupFacade } from './signup-facade';
import { SignupPage } from './signup-page';
import type { SignupAvailability, SignupError } from './signup-types';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      signup: {
        request: {
          title: 'Créer un compte',
          email: 'Adresse e-mail',
          email_invalid: 'Adresse invalide',
          submit: 'Recevoir le lien',
          sent: 'Si cette adresse peut s’inscrire, un lien est en route.',
          closed: 'Les inscriptions sont fermées.',
          errors: {
            too_many: 'Trop de demandes',
            refused: 'Adresse refusée',
            not_usable: 'Fermé',
            network: 'Injoignable',
          },
        },
        back_to_login: 'Retour à la connexion',
      },
    });
  }
}

describe('SignupPage', () => {
  const availability = signal<SignupAvailability | null>(null);
  const requested = signal(false);
  const busy = signal(false);
  const error = signal<SignupError | null>(null);
  const facade = {
    availability,
    requested,
    busy,
    error,
    loadAvailability: vi.fn(),
    request: vi.fn(),
  };

  beforeEach(async () => {
    availability.set({ enabled: true, countries: ['TN'] });
    requested.set(false);
    busy.set(false);
    error.set(null);
    facade.loadAvailability.mockReset();
    facade.request.mockReset().mockImplementation(async () => {
      requested.set(true);
      return true;
    });
    await TestBed.configureTestingModule({
      imports: [SignupPage],
      providers: [
        // Before the page's own language, which the request is tested against.
        provideStillAppearance(),
        provideQuietFeedback(),
        provideRouter([]),
        { provide: SignupFacade, useValue: facade },
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
    const fixture = TestBed.createComponent(SignupPage);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, el, query };
  }

  function type(input: HTMLInputElement, value: string) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  it('reads whether signup is open, and offers the form while it is', async () => {
    const { query } = await render();

    expect(facade.loadAvailability).toHaveBeenCalled();
    expect(query('signup-email')).not.toBeNull();
    expect(query('signup-closed')).toBeNull();
  });

  it('says signup is closed, with the way back to sign in', async () => {
    availability.set({ enabled: false, countries: [] });

    const { query } = await render();

    expect(query('signup-closed')).not.toBeNull();
    expect(query('signup-email')).toBeNull();
    expect(query<HTMLAnchorElement>('signup-back')?.getAttribute('href')).toBe('/login');
  });

  it('asks for a link in the language the page speaks, then answers the same whoever the address is', async () => {
    const { fixture, query } = await render();

    type(query<HTMLInputElement>('signup-email')!, 'new@example.test');
    query<HTMLFormElement>('signup-form')!.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(facade.request).toHaveBeenCalledWith('new@example.test', 'en');
    expect(query('signup-sent')).not.toBeNull();
    expect(query('signup-form')).toBeNull();
  });

  it('refuses a malformed address before asking', async () => {
    const { fixture, query } = await render();

    type(query<HTMLInputElement>('signup-email')!, 'not an address');
    query<HTMLFormElement>('signup-form')!.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(facade.request).not.toHaveBeenCalled();
  });

  it('says why an ask was turned away', async () => {
    error.set('too_many');

    const { query } = await render();

    expect(query('signup-error')?.textContent).toContain('Trop de demandes');
  });
});
