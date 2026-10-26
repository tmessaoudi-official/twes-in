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
import { noticeToasts, provideQuietFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
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
  /** What the session says once read again; a grant closed leaves the operator in no company. */
  let afterRefresh: unknown = null;
  const refresh = vi.fn(async () => me.set(afterRefresh));

  function shown(role: string, until: string | null, reason: string | null = null): HTMLElement {
    me.set({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Carthage', timezone: 'Africa/Tunis', role, countryCode: 'TN' },
      supportAccessUntil: until,
      supportAccessReason: reason,
    });
    const fixture = TestBed.createComponent(SupportBanner);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    afterRefresh = null;
    refresh.mockClear();
    TestBed.configureTestingModule({
      imports: [SupportBanner],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        provideQuietFeedback(),
        { provide: AuthFacade, useValue: { me, refresh } },
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

  it('tells the operator what the owner said is wrong, and says nothing of it when they said nothing', () => {
    const said = shown('support', '2026-10-13T09:00:00+00:00', 'La facture du 3 mars');
    expect(said.querySelector('[data-testid="support-banner-reason"]')?.textContent).toContain(
      'La facture du 3 mars',
    );

    const silent = shown('support', '2026-10-13T09:00:00+00:00');
    expect(silent.querySelector('[data-testid="support-banner-reason"]')).toBeNull();
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

  it('tells the operator the owner closed the access, and takes them back to the platform', async () => {
    // Workflow hunt SUP-3: the open page turned to « introuvable » with nothing said.
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    shown('support', '2026-10-13T09:00:00+00:00');
    afterRefresh = { user: { id: 'u1' }, company: null, supportAccessUntil: null };

    await announceSaved('support_grant', 'g1', null, 'support_access.ended');

    await vi.waitFor(() => expect(navigate).toHaveBeenCalledWith('/platform'));
    expect(noticeToasts()).toEqual([
      { key: 'support_access.closed_by_owner', params: { company: 'Carthage' } },
    ]);
  });

  it('says nothing when the owner opens it again, or a member hears it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    shown('support', '2026-10-13T09:00:00+00:00');
    afterRefresh = me();
    await announceSaved('support_grant', 'g2', null, 'support_access.granted');

    shown('owner', '2026-10-13T09:00:00+00:00');
    afterRefresh = { user: { id: 'u1' }, company: null };
    await announceSaved('support_grant', 'g2', null, 'support_access.ended');

    expect(noticeToasts()).toEqual([]);
    expect(navigate).not.toHaveBeenCalled();
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
