// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ReportDocumentsDialog, type ReportDocumentsData } from './report-documents-dialog';
import { ReportsFacade } from './reports-facade';
import type { ReportDocuments } from './reports-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      reports: {
        documents: {
          title: 'Documents : {{row}}',
          none: 'Aucun document.',
          more: 'Les premiers seulement.',
          failed: 'Illisibles.',
          kinds: { expense: 'Dépense' },
        },
      },
    });
  }
}

const DATA: ReportDocumentsData = {
  companyId: 'k1',
  key: 'expenses-by-supplier',
  query: { from: '2026-09-01', to: '2026-09-30', compare: 'none', establishment: null },
  row: 'payee:abc',
  label: 'Société X',
};

describe('ReportDocumentsDialog', () => {
  const documents = vi.fn<() => Promise<ReportDocuments>>();
  const close = vi.fn();

  async function open(): Promise<HTMLElement> {
    TestBed.configureTestingModule({
      imports: [ReportDocumentsDialog],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MAT_DIALOG_DATA, useValue: DATA },
        { provide: MatDialogRef, useValue: { close } },
        { provide: ReportsFacade, useValue: { documents } },
        {
          provide: FormatFacade,
          useValue: { amount: (v: string) => v, day: (v: string) => v },
        },
      ],
    });
    const fixture = TestBed.createComponent(ReportDocumentsDialog);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  beforeEach(() => {
    documents.mockReset();
    close.mockReset();
  });

  it('lists the row’s documents, each on its own page, a draft by its kind, and says when there are more', async () => {
    documents.mockResolvedValue({
      documents: [
        {
          kind: 'expense',
          id: 'x1',
          number: 'DEP-1',
          party: 'Société X',
          day: '2026-09-12',
          amount: '20.000',
        },
        {
          kind: 'expense',
          id: 'x2',
          number: null,
          party: 'société x',
          day: '2026-09-15',
          amount: '30.000',
        },
      ],
      more: true,
    });
    const el = await open();

    expect(documents).toHaveBeenCalledWith('k1', 'expenses-by-supplier', DATA.query, 'payee:abc');
    expect(el.querySelector('[data-testid="report-documents-title"]')?.textContent).toContain(
      'Documents : Société X',
    );
    const rows = [...el.querySelectorAll('[data-testid="report-document"]')];
    expect(rows.map((row) => row.querySelector('a')?.textContent?.trim())).toEqual([
      'DEP-1',
      'Dépense',
    ]);
    expect(rows.map((row) => row.querySelector('a')?.getAttribute('href'))).toEqual([
      '/expenses/x1',
      '/expenses/x2',
    ]);
    expect(el.querySelector('[data-testid="report-documents-more"]')?.textContent).toContain(
      'Les premiers seulement.',
    );
  });

  it('says when there is no document, and when they cannot be read', async () => {
    documents.mockResolvedValueOnce({ documents: [], more: false });
    let el = await open();
    expect(el.querySelector('[data-testid="report-documents-none"]')?.textContent).toContain(
      'Aucun document.',
    );

    TestBed.resetTestingModule();
    documents.mockRejectedValueOnce(new Error('offline'));
    el = await open();
    expect(el.querySelector('[data-testid="report-documents-failed"]')?.textContent).toContain(
      'Illisibles.',
    );
  });
});
