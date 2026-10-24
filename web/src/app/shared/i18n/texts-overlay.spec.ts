// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { LiveChanges } from '../realtime/live-changes';
import { Session, type SessionState } from '../session/session';
import { provideOverriddenLoader } from './overridden-loader';
import { MAIN_TEXTS, type ScreenOverrides, TextOverrides } from './text-overrides';
import { TEXT_CHANGES, TextsOverlay } from './texts-overlay';
import { TextsParts } from './texts-part';

/**
 * The screens keep the wording in force: a company entered, or a text worded otherwise elsewhere, lays the wording again
 * over the language's file and every page's part, at once and without emptying anything first.
 */
describe('TextsOverlay', () => {
  const me = signal<SessionState | null>(null);
  let http: HttpTestingController;
  let worded: ScreenOverrides;
  let heard: (() => void) | undefined;
  const parts = { refresh: vi.fn(() => Promise.resolve()), share: vi.fn() };
  const forget = vi.fn();

  function settled(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve));
  }

  beforeEach(async () => {
    me.set(null);
    worded = {};
    heard = undefined;
    parts.refresh.mockClear();
    parts.share.mockClear();
    forget.mockClear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideOverriddenLoader('/i18n/', MAIN_TEXTS),
        }),
        { provide: Session, useValue: { me } },
        { provide: TextOverrides, useValue: { of: () => Promise.resolve(worded), forget } },
        { provide: TextsParts, useValue: parts },
        {
          provide: LiveChanges,
          useValue: {
            on: (kinds: readonly string[], handler: () => void) => {
              if (kinds.includes(TEXT_CHANGES)) heard = handler;
            },
          },
        },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const root = TestBed.inject(TranslateService);
    const loading = firstValueFrom(root.use('fr'));
    await settled();
    http.expectOne('/i18n/fr.json').flush({ home: { title: 'Accueil' } });
    await loading;
    TestBed.inject(TextsOverlay);
    TestBed.tick();
  });

  afterEach(() => http.verify());

  it('lays the company’s wording over the language’s file once a company is entered, and over every part', async () => {
    const root = TestBed.inject(TranslateService);
    worded = { screen: { 'home.title': 'Tableau de bord' } };

    me.set({ user: { id: 'u' }, company: { id: 'c1', countryCode: 'TN', currency: 'TND' } });
    TestBed.tick();
    await settled();
    expect(root.instant('home.title')).toBe('Accueil');
    http.expectOne('/i18n/fr.json').flush({ home: { title: 'Accueil' } });
    await settled();

    expect(forget).toHaveBeenCalled();
    expect(root.instant('home.title')).toBe('Tableau de bord');
    expect(parts.refresh).toHaveBeenCalledWith('fr');
    expect(parts.share).toHaveBeenCalledWith('fr');
  });

  it('lays it again when a text is worded otherwise in another tab or by another person', async () => {
    const root = TestBed.inject(TranslateService);
    worded = { screen: { 'home.title': 'Début' } };

    expect(heard).toBeDefined();
    heard?.();
    await settled();
    http.expectOne('/i18n/fr.json').flush({ home: { title: 'Accueil' } });
    await settled();

    expect(root.instant('home.title')).toBe('Début');
  });

  it('reads nothing again at start, nor while the company stays the same', async () => {
    await settled();
    http.expectNone('/i18n/fr.json');
    expect(parts.refresh).not.toHaveBeenCalled();

    const acme = { id: 'c1', countryCode: 'TN', currency: 'TND' };
    me.set({ user: { id: 'u' }, company: acme });
    TestBed.tick();
    await settled();
    http.expectOne('/i18n/fr.json').flush({ home: { title: 'Accueil' } });
    await settled();
    parts.refresh.mockClear();

    // The session read again, a new answer naming the same company.
    me.set({ user: { id: 'u' }, company: { ...acme } });
    TestBed.tick();
    await settled();

    http.expectNone('/i18n/fr.json');
    expect(parts.refresh).not.toHaveBeenCalled();
  });
});
