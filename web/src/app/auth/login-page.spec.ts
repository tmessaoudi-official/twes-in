// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import type { Me } from '../api/types.gen';
import { LoginPage } from './login-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      app: { name: 'twes-in', tagline: 'Facturation' },
      health: { api: 'API', ok: 'opérationnelle', checking: 'vérification…' },
      auth: {
        login: {
          title: 'Connexion',
          email: 'Adresse e-mail',
          email_required: 'E-mail obligatoire',
          email_invalid: 'E-mail invalide',
          password: 'Mot de passe',
          password_required: 'Mot de passe obligatoire',
          submit: 'Se connecter',
          submitting: 'Connexion…',
        },
        errors: {
          invalid_credentials: 'Identifiants incorrects',
          account_locked: 'Compte verrouillé',
          network: 'Serveur injoignable',
        },
      },
    });
  }
}

const me: Me = {
  user: {
    id: '1',
    email: 'owner@example.test',
    displayName: 'Owner',
    locale: 'fr',
    isPlatformOperator: false,
  },
  company: null,
  permissions: [],
  mfa: { enrolled: false, required: false },
  modules: [],
};

describe('LoginPage', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [LoginPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  async function render() {
    const fixture = TestBed.createComponent(LoginPage);
    // The health probe is issued at construction; whenStable() would wait for it, so answer it first.
    const http = TestBed.inject(HttpTestingController);
    http.expectOne('/api/health').flush({ status: 'ok', database: 'ok' });
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    return { fixture, el, http, query };
  }

  /** whenStable() covers pending HTTP; the component continuation after a flush is a microtask later. */
  async function settle(fixture: { whenStable(): Promise<unknown> }) {
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await fixture.whenStable();
  }

  function type(input: HTMLInputElement, value: string) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  it('renders the form and the API status line', async () => {
    const { el, query } = await render();
    expect(el.querySelector('h1')?.textContent).toContain('twes-in');
    expect(query('email')).not.toBeNull();
    expect(query('password')).not.toBeNull();
    expect(query('api-status')?.textContent).toContain('opérationnelle');
  });

  it('refuses an empty submission without calling the API', async () => {
    const { fixture, el, query } = await render();
    query<HTMLFormElement>('login-form')?.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(el.textContent).toContain('E-mail obligatoire');
    expect(el.textContent).toContain('Mot de passe obligatoire');
    // afterEach verifies no /api/auth/login request was made.
  });

  it('flags a malformed email before submitting', async () => {
    const { fixture, el, query } = await render();
    type(query<HTMLInputElement>('email')!, 'not-an-email');
    type(query<HTMLInputElement>('password')!, 'pw');
    query<HTMLFormElement>('login-form')?.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(el.textContent).toContain('E-mail invalide');
  });

  it('posts the credentials and navigates home on success', async () => {
    const { fixture, http, query } = await render();
    const router = TestBed.inject(Router);
    const navigate = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    type(query<HTMLInputElement>('email')!, 'owner@example.test');
    type(query<HTMLInputElement>('password')!, 'pw');
    query<HTMLFormElement>('login-form')?.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    const request = http.expectOne({ method: 'POST', url: '/api/auth/login' });
    expect(request.request.body).toEqual({ email: 'owner@example.test', password: 'pw' });
    request.flush(me);
    await settle(fixture);

    expect(navigate).toHaveBeenCalledWith('/');
    expect(query('login-error')).toBeNull();
  });

  it('shows the API refusal and clears the password', async () => {
    const { fixture, http, query } = await render();
    type(query<HTMLInputElement>('email')!, 'owner@example.test');
    type(query<HTMLInputElement>('password')!, 'wrong');
    query<HTMLFormElement>('login-form')?.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    http
      .expectOne('/api/auth/login')
      .flush({ error: 'account_locked' }, { status: 401, statusText: 'Unauthorized' });
    await settle(fixture);

    expect(query('login-error')?.textContent).toContain('Compte verrouillé');
    expect(query<HTMLInputElement>('password')?.value).toBe('');
    expect(query<HTMLInputElement>('email')?.value).toBe('owner@example.test');
  });
});
