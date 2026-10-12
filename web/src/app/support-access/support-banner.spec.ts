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
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { SupportBanner } from './support-banner';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      support_access: {
        banner_support: 'Vous lisez {{company}} pour le support jusqu’au {{until}}.',
        banner_members: 'Le support peut lire la société jusqu’au {{until}}.',
        manage: 'Gérer',
      },
    });
  }
}

describe('SupportBanner', () => {
  const me = signal<unknown>(null);

  function shown(role: string, until: string | null): HTMLElement {
    me.set({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Carthage', timezone: 'Africa/Tunis', role, countryCode: 'TN' },
      supportAccessUntil: until,
    });
    const fixture = TestBed.createComponent(SupportBanner);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [SupportBanner],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: AuthFacade, useValue: { me } },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('tells the operator reading through the grant which company and until when', () => {
    const banner = shown('support', '2026-10-13T09:00:00+00:00').querySelector(
      '[data-testid="support-banner"]',
    );

    expect(banner?.getAttribute('role')).toBe('status');
    expect(banner?.textContent).toContain('Vous lisez Carthage pour le support');
    expect(banner?.querySelector('[data-testid="support-banner-manage"]')).toBeNull();
  });

  it('tells the members, and offers the owner the way to close it', () => {
    const owner = shown('owner', '2026-10-13T09:00:00+00:00');
    expect(owner.textContent).toContain('Le support peut lire la société');
    expect(owner.querySelector('[data-testid="support-banner-manage"]')?.getAttribute('href')).toBe(
      '/company/support-access',
    );
  });

  it('says nothing while the company is not open to support', () => {
    expect(shown('owner', null).querySelector('[data-testid="support-banner"]')).toBeNull();
  });
});
