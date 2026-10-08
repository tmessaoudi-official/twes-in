// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { FileSaver } from '../shared/files/save-file';
import { todayIn } from '../shared/i18n/format';
import { ExportApi } from '../shared/list/export-api';
import { StepUp } from '../shared/step-up/step-up';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { AccountingExportPage } from './accounting-export-page';
import { lastMonth } from './accounting-files';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

describe('AccountingExportPage', () => {
  let fixture: ComponentFixture<AccountingExportPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  function setDay(testId: string, day: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = day;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [AccountingExportPage],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ExportApi, useValue: { file: vi.fn() } },
        { provide: StepUp, useValue: { request: vi.fn() } },
        { provide: FileSaver, useValue: { save: vi.fn() } },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', timezone: 'Africa/Tunis' } }),
          },
        },
      ],
    });
    fixture = TestBed.createComponent(AccountingExportPage);
    fixture.detectChanges();
  });

  it('offers the four files of last month, each as CSV and Excel', () => {
    const { from, to } = lastMonth(todayIn('Africa/Tunis'));

    expect((q('accounting-export-from') as HTMLInputElement).value).toBe(from);
    expect((q('accounting-export-to') as HTMLInputElement).value).toBe(to);
    for (const file of ['sales-journal', 'purchases-journal', 'payments-journal', 'vat-summary']) {
      expect(q(`accounting-file-${file}`)).not.toBeNull();
      expect(q(`accounting-export-${file}-csv`)?.dataset['address']).toBe(
        `/api/companies/c1/exports/${file}.csv?from=${from}&to=${to}`,
      );
      expect(q(`accounting-export-${file}-xlsx`)?.dataset['address']).toBe(
        `/api/companies/c1/exports/${file}.xlsx?from=${from}&to=${to}`,
      );
    }
  });

  it('takes the period written', () => {
    setDay('accounting-export-from', '2026-01-01');
    setDay('accounting-export-to', '2026-03-31');

    expect(q('accounting-export-vat-summary-csv')?.dataset['address']).toBe(
      '/api/companies/c1/exports/vat-summary.csv?from=2026-01-01&to=2026-03-31',
    );
  });

  it('offers no file for days out of order or more than a year apart, and says why', () => {
    setDay('accounting-export-from', '2026-09-30');
    setDay('accounting-export-to', '2026-09-01');

    expect(q('accounting-export-sales-journal-csv')).toBeNull();
    expect(q('accounting-export-period-error')).not.toBeNull();

    setDay('accounting-export-from', '2024-01-01');
    setDay('accounting-export-to', '2026-01-01');

    expect(q('accounting-export-sales-journal-csv')).toBeNull();
  });
});
