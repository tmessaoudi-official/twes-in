// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { CompanyFacade } from '../company/company-facade';
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
        leave: 'Quitter',
      },
    });
  }
}

describe('SupportBanner', () => {
  const me = signal<unknown>(null);
  const companies = { switching: signal(false), leave: vi.fn(async () => true) };

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
        { provide: CompanyFacade, useValue: companies },
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

  it('lets the operator leave the company, back to the platform: nothing read after is journalled', async () => {
    // Done reading, the operator had no way out but the home's « Gérer la plateforme », the session left in the shop.
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    shown('support', '2026-10-13T09:00:00+00:00')
      .querySelector<HTMLButtonElement>('[data-testid="support-banner-leave"]')
      ?.click();

    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/platform'));
    expect(companies.leave).toHaveBeenCalled();
  });

  it('offers no way out to the company’s own members, who work there', () => {
    expect(
      shown('owner', '2026-10-13T09:00:00+00:00').querySelector(
        '[data-testid="support-banner-leave"]',
      ),
    ).toBeNull();
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
