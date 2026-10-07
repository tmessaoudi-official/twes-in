// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { MembersFacade } from '../company/members-facade';
import { Session } from '../shared/session/session';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { ActivityFacade } from './activity-facade';
import { ActivityPage } from './activity-page';
import type { ActivityRow } from './activity-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      activity: {
        title: 'Journal d’activité',
        nobody: 'Personne',
        kinds: { invoice: 'Facture', role: 'Rôle' },
        actions: { invoice: { issued: 'a émis la facture' } },
      },
    });
  }
}

const issued: ActivityRow = {
  id: 'a1',
  at: '2026-03-04T10:00:00+00:00',
  action: 'invoice.issued',
  entityType: 'invoice',
  entityId: 'i1',
  actorId: 'u2',
  actorName: 'Leila',
  fields: [],
  ip: null,
};
const unknown: ActivityRow = {
  id: 'a2',
  at: '2026-03-04T09:00:00+00:00',
  action: 'role.renamed_someday',
  entityType: 'role',
  entityId: 'r1',
  actorId: null,
  actorName: null,
  fields: ['name'],
  ip: null,
};

describe('ActivityPage', () => {
  const facade = {
    rows: signal<readonly ActivityRow[]>([issued, unknown]).asReadonly(),
    total: signal(2).asReadonly(),
    error: signal(null).asReadonly(),
    loadPage: vi.fn(),
    exportUrl: vi.fn(
      (companyId: string, _search: unknown, format: string) =>
        `/api/companies/${companyId}/exports/activity.${format}`,
    ),
  };
  const members = {
    members: signal([
      {
        userId: 'u2',
        email: 'leila@twes.local',
        displayName: 'Leila',
        role: 'admin',
        joinedAt: '',
        status: 'joined' as const,
      },
    ]).asReadonly(),
    load: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<ActivityPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    facade.loadPage.mockReset().mockResolvedValue(undefined);
    members.load.mockReset().mockResolvedValue(undefined);
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [ActivityPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ActivityFacade, useValue: facade },
        { provide: MembersFacade, useValue: members },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(ActivityPage);
    await settle();
  });

  it('asks for the newest entries first, a page of fifty', () => {
    expect(facade.loadPage).toHaveBeenCalledWith('c1', {
      page: 1,
      itemsPerPage: 50,
      q: '',
      actorIds: [],
      entityTypes: [],
      entityId: null,
      intervals: {},
      direction: 'desc',
    });
  });

  it('says what was done in words, and opens the record that has a page', () => {
    const row = q('activity-a1');
    expect(row?.textContent).toContain('Leila');
    expect(row?.textContent).toContain('a émis la facture');
    expect(row?.querySelector('[data-testid="activity-record"]')?.getAttribute('href')).toBe(
      '/invoices/i1',
    );
  });

  it('shows an action it cannot say as recorded, and nobody when no one was signed in', () => {
    const row = q('activity-a2');
    expect(row?.querySelector('code')?.textContent).toBe('role.renamed_someday');
    expect(row?.textContent).toContain('Personne');
    expect(row?.querySelector('[data-testid="activity-record"]')).toBeNull();
  });

  it('offers what the journal shows as a file', () => {
    expect(q('activity-export-csv')?.getAttribute('data-address')).toBe(
      '/api/companies/c1/exports/activity.csv',
    );
  });

  it('reads the journal again when something changes elsewhere', async () => {
    facade.loadPage.mockClear();

    await announceSaved('invoice', 'i9');

    expect(facade.loadPage).toHaveBeenCalledWith('c1', expect.objectContaining({ page: 1 }));
  });

  it('narrows to one record when its « Historique » sent the reader, until they ask for every record', async () => {
    const record = '0192f0a0-0000-7000-8000-00000000000a';
    await TestBed.inject(Router).navigateByUrl(`/?kind=customer&record=${record}`);
    facade.loadPage.mockClear();
    fixture = TestBed.createComponent(ActivityPage);
    await settle();

    expect(facade.loadPage).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ entityId: record, entityTypes: ['customer'] }),
    );
    expect(q('activity-one-record')).not.toBeNull();

    q('activity-every-record')?.click();
    await settle();

    expect(facade.loadPage).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ entityId: null, entityTypes: ['customer'] }),
    );
    expect(q('activity-one-record')).toBeNull();
  });

  it('reads the members to pick who did it only for a reader allowed to see them', async () => {
    expect(members.load).toHaveBeenCalledWith('c1');

    members.load.mockClear();
    auth.hasPermission.mockImplementation((permission: string) => permission !== 'user.read');
    fixture = TestBed.createComponent(ActivityPage);
    await settle();

    expect(members.load).not.toHaveBeenCalled();
  });
});
