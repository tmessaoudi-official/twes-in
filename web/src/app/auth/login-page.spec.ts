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
import { PasskeyClient } from './passkey-client';
import { provideStillAppearance } from '../shared/testing/appearance';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      app: { name: 'twes-in' },
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
          signup: 'Créer un compte',
        },
        mfa: {
          title: 'Vérification en deux étapes',
          intro: 'Votre compte est protégé par une deuxième étape',
          recovery_hint: 'Un code de secours fonctionne aussi',
          code: 'Code',
          code_required: 'Code obligatoire',
          submit: 'Vérifier',
          submitting: 'Vérification…',
          back: 'Retour',
          passkey: 'Utiliser une clé d’accès',
        },
        errors: {
          invalid_credentials: 'Identifiants incorrects',
          account_locked: 'Compte verrouillé',
          invalid_code: 'Code incorrect',
          mfa_not_pending: 'Reconnectez-vous',
          network: 'Serveur injoignable',
          invalid_passkey: 'Clé refusée',
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
  mfa: { enrolled: false, required: false, totp: false, passkeys: 0 },
  modules: [],
};

describe('LoginPage', () => {
  const passkeyClient = { supported: () => true, get: vi.fn() };

  beforeEach(async () => {
    passkeyClient.get.mockReset();
    await TestBed.configureTestingModule({
      imports: [LoginPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: PasskeyClient, useValue: passkeyClient },
        provideStillAppearance(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  async function render(signupOpen = false) {
    const fixture = TestBed.createComponent(LoginPage);
    // The health probe and the signup availability are asked at construction; whenStable() would wait for them.
    const http = TestBed.inject(HttpTestingController);
    http.expectOne('/api/health').flush({ status: 'ok', database: 'ok' });
    http
      .expectOne('/api/signup')
      .flush({ enabled: signupOpen, countries: signupOpen ? ['TN'] : [] });
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));
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
    expect(el.querySelector('header [role="img"]')?.getAttribute('aria-label')).toBe('twes-in');
    expect(el.querySelector('h1')?.textContent).toContain('Connexion');
    expect(query('email')).not.toBeNull();
    expect(query('password')).not.toBeNull();
    expect(query('api-status')?.textContent).toContain('opérationnelle');
  });

  it('puts each label above its field and ties it to the control', async () => {
    const { query } = await render();

    for (const id of ['email', 'password']) {
      const input = query<HTMLInputElement>(id)!;
      expect(input.labels?.[0]?.textContent).toContain(
        id === 'email' ? 'Adresse e-mail' : 'Mot de passe',
      );
      expect(input.closest('mat-form-field')?.querySelector('mat-label')).toBeNull();
    }
  });

  it('offers to create an account while signup is open', async () => {
    const { query } = await render(true);

    expect(query<HTMLAnchorElement>('login-signup')?.getAttribute('href')).toBe('/signup');
    expect(query('login-signup')?.textContent).toContain('Créer un compte');
  });

  it('offers no account creation while signup is closed', async () => {
    const { query } = await render(false);

    expect(query('login-signup')).toBeNull();
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

  async function passThePassword(rendered: Awaited<ReturnType<typeof render>>) {
    const { fixture, http, query } = rendered;
    type(query<HTMLInputElement>('email')!, 'owner@example.test');
    type(query<HTMLInputElement>('password')!, 'pw');
    query<HTMLFormElement>('login-form')?.dispatchEvent(new Event('submit'));
    await fixture.whenStable();
    http.expectOne('/api/auth/login').flush({ mfaRequired: true });
    await settle(fixture);
  }

  async function submitCode(rendered: Awaited<ReturnType<typeof render>>, code: string) {
    const { fixture, query } = rendered;
    type(query<HTMLInputElement>('mfa-code')!, code);
    query<HTMLFormElement>('mfa-form')?.dispatchEvent(new Event('submit'));
    await fixture.whenStable();
  }

  it('asks for a code when the password was right but a second factor is owed', async () => {
    const rendered = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    await passThePassword(rendered);

    expect(rendered.query('login-form')).toBeNull();
    expect(rendered.query('mfa-form')).not.toBeNull();
    expect(navigate).not.toHaveBeenCalled();

    await submitCode(rendered, '123456');
    const request = rendered.http.expectOne({ method: 'POST', url: '/api/auth/mfa/verify' });
    expect(request.request.body).toEqual({ code: '123456' });
    request.flush(me);
    await settle(rendered.fixture);

    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('says why the code is asked for, and that a recovery code works too', async () => {
    const rendered = await render();
    await passThePassword(rendered);

    const input = rendered.query<HTMLInputElement>('mfa-code')!;
    const described = (input.getAttribute('aria-describedby') ?? '')
      .split(' ')
      .map((id) => rendered.el.querySelector(`[id="${id}"]`)?.textContent ?? '')
      .join(' ');
    expect(described).toContain('protégé par une deuxième étape');
    expect(described).toContain('Un code de secours fonctionne aussi');
    expect(input.labels?.[0]?.textContent).toContain('Code');
  });

  it('shows a refused code, clears it and keeps asking', async () => {
    const rendered = await render();
    await passThePassword(rendered);
    await submitCode(rendered, '000000');
    rendered.http
      .expectOne('/api/auth/mfa/verify')
      .flush({ error: 'invalid_code' }, { status: 401, statusText: 'Unauthorized' });
    await settle(rendered.fixture);

    expect(rendered.query('login-error')?.textContent).toContain('Code incorrect');
    expect(rendered.query<HTMLInputElement>('mfa-code')?.value).toBe('');
    expect(rendered.query('mfa-form')).not.toBeNull();
  });

  it('goes back to the password when the half-finished login has expired', async () => {
    const rendered = await render();
    await passThePassword(rendered);
    await submitCode(rendered, '123456');
    rendered.http
      .expectOne('/api/auth/mfa/verify')
      .flush({ error: 'mfa_not_pending' }, { status: 401, statusText: 'Unauthorized' });
    await settle(rendered.fixture);

    expect(rendered.query('mfa-form')).toBeNull();
    expect(rendered.query('login-form')).not.toBeNull();
    expect(rendered.query('login-error')?.textContent).toContain('Reconnectez-vous');
  });

  it('lets the person go back to the password step', async () => {
    const rendered = await render();
    await passThePassword(rendered);

    rendered.query<HTMLButtonElement>('mfa-back')?.click();
    await settle(rendered.fixture);

    expect(rendered.query('login-form')).not.toBeNull();
    expect(rendered.query<HTMLInputElement>('password')?.value).toBe('');
  });

  async function usePasskey(rendered: Awaited<ReturnType<typeof render>>) {
    passkeyClient.get.mockResolvedValue({ id: 'cred' });
    rendered.query<HTMLButtonElement>('mfa-passkey')?.click();
    await rendered.fixture.whenStable();
    rendered.http
      .expectOne({ method: 'POST', url: '/api/auth/mfa/passkey-login/options' })
      .flush({ challenge: 'xyz' });
    await settle(rendered.fixture);
    expect(passkeyClient.get).toHaveBeenCalledWith({ challenge: 'xyz' });
    const request = rendered.http.expectOne({ method: 'POST', url: '/api/auth/mfa/passkey-login' });
    expect(request.request.body).toEqual({ credential: { id: 'cred' } });
    return request;
  }

  it('finishes the second step with a passkey instead of a code', async () => {
    const rendered = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    await passThePassword(rendered);

    (await usePasskey(rendered)).flush(me);
    await settle(rendered.fixture);

    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('shows a refused passkey and stays on the second step', async () => {
    const rendered = await render();
    await passThePassword(rendered);

    (await usePasskey(rendered)).flush(
      { error: 'invalid_passkey' },
      { status: 401, statusText: 'Unauthorized' },
    );
    await settle(rendered.fixture);

    expect(rendered.query('login-error')?.textContent).toContain('Clé refusée');
    expect(rendered.query('mfa-form')).not.toBeNull();
  });
});
