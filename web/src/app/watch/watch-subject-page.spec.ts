// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { ActivatedRoute, convertToParamMap, provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { WatchFacade } from './watch-facade';
import { WatchSubjectPage } from './watch-subject-page';
import type { WatchSummary } from './watch-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      invoices: { portfolio: { open: 'Portefeuille chèques et traites' } },
      watch: { none: 'Rien.' },
    });
  }
}

const summary: WatchSummary = {
  count: 3,
  subjects: [
    { kind: 'invoices.instruments_due', count: 2 },
    { kind: 'invoices.late_customer', count: 1 },
  ],
};

// docs/SPEC.md § 7, audit B-2: the cheques fallen due lead to the whole portfolio, which no other screen linked to.
describe('WatchSubjectPage', () => {
  let fixture: ComponentFixture<WatchSubjectPage>;
  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(kind: string): Promise<void> {
    TestBed.configureTestingModule({
      imports: [WatchSubjectPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        {
          provide: ActivatedRoute,
          useValue: {
            paramMap: of(convertToParamMap({ kind })),
            queryParamMap: of(convertToParamMap({})),
            snapshot: {
              paramMap: convertToParamMap({ kind }),
              queryParamMap: convertToParamMap({}),
            },
          },
        },
        {
          provide: WatchFacade,
          useValue: {
            summary: signal(summary).asReadonly(),
            rows: signal({ status: 'ready', page: { rows: [], total: 0 } }).asReadonly(),
            load: vi.fn().mockResolvedValue(undefined),
            loadRows: vi.fn().mockResolvedValue(undefined),
          },
        },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
            hasPermission: () => true,
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: LiveChanges, useValue: { reloadOn: vi.fn() } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(WatchSubjectPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it('leads from the cheques fallen due to the whole portfolio', async () => {
    await open('invoices.instruments_due');

    const link = q('watch-elsewhere');
    expect(link?.getAttribute('href')).toBe('/instruments');
    expect(link?.textContent).toContain('Portefeuille chèques et traites');
  });

  it('offers no way elsewhere on a subject that has none', async () => {
    await open('invoices.late_customer');

    expect(q('watch-subject-title')).not.toBeNull();
    expect(q('watch-elsewhere')).toBeNull();
  });
});
