// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Session, type SessionState } from '../session/session';
import { laid, TextOverrides } from './text-overrides';

describe('laid', () => {
  it('replaces the texts worded otherwise, deep in the file, and nothing else', () => {
    const shipped = { auth: { mfa: { title: 'Vérification', hint: 'Un code' } }, home: 'Accueil' };

    const texts = laid(shipped, { 'auth.mfa.title': 'Double vérification', home: 'Début' });

    expect(texts).toEqual({
      auth: { mfa: { title: 'Double vérification', hint: 'Un code' } },
      home: 'Début',
    });
    expect(shipped.auth.mfa.title).toBe('Vérification');
  });

  it('leaves out a wording of a text the release no longer ships, or of a group of texts', () => {
    const shipped = { auth: { mfa: { title: 'Vérification' } } };

    expect(
      laid(shipped, { 'auth.gone': 'Rien', 'auth.mfa': 'Rien', 'nowhere.at.all': 'Rien' }),
    ).toEqual(shipped);
    expect(laid(shipped, undefined)).toBe(shipped);
  });
});

describe('TextOverrides', () => {
  const me = signal<SessionState | null>(null);
  let http: HttpTestingController;
  let overrides: TextOverrides;

  beforeEach(() => {
    me.set(null);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: Session, useValue: { me, hasPermission: () => false, hasModule: () => false } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    overrides = TestBed.inject(TextOverrides);
  });

  afterEach(() => http.verify());

  it("reads the installation's with no company, and once a language", async () => {
    const first = overrides.of('fr');
    const again = overrides.of('fr');
    http.expectOne('/api/texts/fr').flush({ screen: { home: 'Début' } });

    expect(await first).toEqual({ screen: { home: 'Début' } });
    expect(await again).toEqual({ screen: { home: 'Début' } });
  });

  it("reads the company's in a company, and again once forgotten", async () => {
    me.set({ user: { id: 'u' }, company: { id: 'c1', countryCode: 'TN', currency: 'TND' } });
    const first = overrides.of('ar');
    http
      .expectOne('/api/companies/c1/texts/ar')
      .flush({ 'screen:two-factor': { 'auth.two_factor.title': 'تحقق' } });
    expect(await first).toEqual({ 'screen:two-factor': { 'auth.two_factor.title': 'تحقق' } });

    overrides.forget();
    const second = overrides.of('ar');
    http.expectOne('/api/companies/c1/texts/ar').flush({});
    expect(await second).toEqual({});
  });

  it("reads the installation's and a company's apart, in the same language", async () => {
    const installation = overrides.of('fr');
    http.expectOne('/api/texts/fr').flush({ screen: { home: 'Début' } });
    expect(await installation).toEqual({ screen: { home: 'Début' } });

    me.set({ user: { id: 'u' }, company: { id: 'c1', countryCode: 'TN', currency: 'TND' } });
    const company = overrides.of('fr');
    http.expectOne('/api/companies/c1/texts/fr').flush({ screen: { home: 'Accueil Acme' } });
    expect(await company).toEqual({ screen: { home: 'Accueil Acme' } });
  });

  it('answers none when the read fails, and asks again the next time', async () => {
    const failed = overrides.of('en');
    http.expectOne('/api/texts/en').flush('down', { status: 503, statusText: 'Unavailable' });
    expect(await failed).toEqual({});

    const next = overrides.of('en');
    http.expectOne('/api/texts/en').flush({ screen: { home: 'Start' } });
    expect(await next).toEqual({ screen: { home: 'Start' } });
  });
});
