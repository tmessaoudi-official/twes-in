// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
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
import { ExpensesFacade } from './expenses-facade';
import { ExpensesHome } from './expenses-home';
import type { ExpenseSummary } from './expenses-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      expenses: {
        home: {
          title: 'Dépenses · {{month}}',
          note: 'comptabilisées, taxes comprises',
          see: 'Voir',
          versus: {
            up: '+{{amount}} sur les mêmes jours du mois dernier',
            down: '−{{amount}} sur les mêmes jours du mois dernier',
            same: 'Comme les mêmes jours du mois dernier',
          },
        },
      },
    });
  }
}

const summary: ExpenseSummary = {
  currency: 'TND',
  currencyScale: 3,
  today: '2026-09-21',
  month: '1234.500',
  lastMonth: '1000.000',
};

// docs/SPEC.md § 7, 2026-10-04 (row 113): the expenses recorded this month, beside the same days of last month, never a profit.
describe('ExpensesHome', () => {
  const current = signal<ExpenseSummary | null>(summary);
  const facade = { summary: current.asReadonly(), loadSummary: vi.fn() };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<ExpensesHome>;

  // The thousands separator is a narrow no-break space: read every space as a plain one.
  const text = (): string =>
    ((fixture.nativeElement as HTMLElement).textContent ?? '').replace(/\s/g, ' ');

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(ExpensesHome);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    facade.loadSummary.mockReset().mockResolvedValue(undefined);
    live.reloadOn.mockReset();
    current.set(summary);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: ExpensesFacade, useValue: facade },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({
              user: { id: 'u1' },
              company: { id: 'c1', countryCode: 'TN', timezone: 'Africa/Tunis' },
            }),
          },
        },
        { provide: LiveChanges, useValue: live },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('reads the summary of the working company and again when an expense changes', async () => {
    await open();

    expect(facade.loadSummary).toHaveBeenCalledWith('c1');
    expect(live.reloadOn.mock.calls[0]?.[0]).toEqual(['expense']);
  });

  it('shows the month, the amount recorded and how it moved against the same days of last month', async () => {
    await open();

    expect(text()).toContain('Dépenses · septembre');
    expect(text()).toContain('1 234,500');
    expect(text()).toContain('+234,500 sur les mêmes jours du mois dernier');
    expect(text()).toContain('comptabilisées, taxes comprises');
  });

  it('draws nothing until the summary has been read', async () => {
    current.set(null);
    await open();

    expect(text().trim()).toBe('');
  });
});
