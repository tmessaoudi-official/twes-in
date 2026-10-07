// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
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
import { provideQuietFeedback } from '../shared/testing/feedback';
import { announceSaved } from '../shared/testing/live';
import { ActivityApi } from './activity-api';
import type { ActivityRow } from './activity-types';
import { HISTORY_SIZE, RecordHistory } from './record-history';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      activity: {
        nobody: 'Personne',
        history: { none: 'Rien encore', all: 'Tout voir' },
        actions: { customer: { revised: 'a modifié le client' } },
      },
    });
  }
}

const revised: ActivityRow = {
  id: 'a1',
  at: '2026-03-04T10:00:00+00:00',
  action: 'customer.revised',
  entityType: 'customer',
  entityId: 'c9',
  actorId: 'u2',
  actorName: 'Leila',
  fields: ['email', 'phone'],
  ip: null,
};

@Component({
  imports: [RecordHistory],
  template: `<app-record-history kind="customer" [recordId]="id()" />`,
})
class Host {
  readonly id = signal('c9');
}

describe('RecordHistory', () => {
  const api = { entries: vi.fn() };
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    api.entries.mockReset().mockResolvedValue({ rows: [revised], total: 1 });
    TestBed.configureTestingModule({
      imports: [Host],
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
        { provide: ActivityApi, useValue: api },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'k1' } }),
            hasPermission: () => true,
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it("asks the journal for the record's latest entries, newest first", () => {
    expect(api.entries).toHaveBeenCalledWith('k1', {
      page: 1,
      itemsPerPage: HISTORY_SIZE,
      q: '',
      actorIds: [],
      entityTypes: ['customer'],
      entityId: 'c9',
      intervals: {},
      direction: 'desc',
    });
  });

  it('says who did what and names what changed', () => {
    const entry = q('record-history-entry')?.textContent ?? '';
    expect(entry).toContain('Leila');
    expect(entry).toContain('a modifié le client');
    expect(entry).toContain('email, phone');
  });

  it('leads to the whole journal narrowed to the record', () => {
    expect(q('record-history-all')?.getAttribute('href')).toBe(
      '/company/activity?kind=customer&record=c9',
    );
  });

  it('reads the history again when the record changes elsewhere', async () => {
    api.entries.mockClear();

    await announceSaved('customer', 'c9');

    expect(api.entries).toHaveBeenCalledTimes(1);
  });

  it('says when nothing was done to the record', async () => {
    api.entries.mockResolvedValue({ rows: [], total: 0 });
    fixture.componentInstance.id.set('c10');
    await settle();

    expect(q('record-history-empty')).not.toBeNull();
  });
});
