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
import { FinishSignupPage } from './finish-signup-page';
import { SignupFacade } from './signup-facade';
import type { SignupAvailability, SignupCompleted, SignupError } from './signup-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      signup: {
        finish: {
          title: 'Terminer l’inscription',
          display_name: 'Votre nom',
          password: 'Mot de passe',
          password_hint: '12 caractères au moins',
          password_too_short: 'Trop court',
          company_name: 'Nom de l’entreprise',
          country: 'Pays',
          timezone: 'Fuseau horaire',
          submit: 'Créer mon compte',
          required: 'Obligatoire',
          ready: 'Votre compte est prêt.',
          pending: 'Un opérateur doit approuver {{company}}.',
          active: '{{company}} est prête.',
          errors: {
            not_usable: 'Lien invalide',
            refused: 'Refusé',
            too_many: 'Trop',
            network: 'Injoignable',
          },
        },
        back_to_login: 'Se connecter',
      },
    });
  }
}

describe('FinishSignupPage', () => {
  const availability = signal<SignupAvailability | null>({
    enabled: true,
    countries: ['FR', 'TN'],
  });
  const linkEmail = signal<string | null>('new@example.test');
  const completed = signal<SignupCompleted | null>(null);
  const busy = signal(false);
  const error = signal<SignupError | null>(null);
  const facade = {
    availability,
    linkEmail,
    completed,
    busy,
    error,
    loadAvailability: vi.fn(),
    loadLink: vi.fn(),
    complete: vi.fn(),
  };

  beforeEach(async () => {
    availability.set({ enabled: true, countries: ['FR', 'TN'] });
    linkEmail.set('new@example.test');
    completed.set(null);
    busy.set(false);
    error.set(null);
    facade.loadAvailability.mockReset();
    facade.loadLink.mockReset();
    facade.complete.mockReset().mockImplementation(async () => {
      const done = { companyName: 'Nouvelle Société', companyStatus: 'pending' };
      completed.set(done);
      return done;
    });
    await TestBed.configureTestingModule({
      imports: [FinishSignupPage],
      providers: [
        provideRouter([]),
        { provide: SignupFacade, useValue: facade },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(FinishSignupPage);
    fixture.componentRef.setInput('token', 'a-token');
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

  it('reads the link and shows the address it was sent to', async () => {
    const { query } = await render();

    expect(facade.loadLink).toHaveBeenCalledWith('a-token');
    expect(facade.loadAvailability).toHaveBeenCalled();
    expect(query('signup-finish-email')?.textContent).toContain('new@example.test');
  });

  it('finishes with what was typed, in the browser time zone unless another is chosen', async () => {
    const { fixture, query } = await render();

    expect(query<HTMLInputElement>('signup-timezone')!.value).toBe(
      Intl.DateTimeFormat().resolvedOptions().timeZone,
    );
    type(query<HTMLInputElement>('signup-name')!, 'Nadia');
    type(query<HTMLInputElement>('signup-password')!, 'a-long-enough-password');
    type(query<HTMLInputElement>('signup-company')!, 'Nouvelle Société');
    type(query<HTMLInputElement>('signup-timezone')!, 'Africa/Tunis');
    // A mat-select is set through its control, as a person picking an option would.
    (
      fixture.componentInstance as unknown as {
        form: { controls: { countryCode: { setValue(v: string): void } } };
      }
    ).form.controls.countryCode.setValue('TN');
    query<HTMLFormElement>('signup-finish-form')!.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(facade.complete).toHaveBeenCalledWith('a-token', {
      displayName: 'Nadia',
      password: 'a-long-enough-password',
      companyName: 'Nouvelle Société',
      countryCode: 'TN',
      timezone: 'Africa/Tunis',
    });
    expect(query('signup-completed')).not.toBeNull();
    expect(query('signup-completed-pending')?.textContent).toContain('Nouvelle Société');
    expect(query<HTMLAnchorElement>('signup-login')?.getAttribute('href')).toBe('/login');
  });

  it('refuses a short password before asking', async () => {
    const { fixture, query } = await render();

    type(query<HTMLInputElement>('signup-name')!, 'Nadia');
    type(query<HTMLInputElement>('signup-password')!, 'short');
    type(query<HTMLInputElement>('signup-company')!, 'Nouvelle Société');
    query<HTMLFormElement>('signup-finish-form')!.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(facade.complete).not.toHaveBeenCalled();
  });

  it('says a link that cannot be used cannot, and offers nothing to fill in', async () => {
    linkEmail.set(null);
    error.set('not_usable');

    const { query } = await render();

    expect(query('signup-link-error')?.textContent).toContain('Lien invalide');
    expect(query('signup-finish-form')).toBeNull();
  });
});
