// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { Feedback } from '../shared/feedback/feedback';
import { FileSaver } from '../shared/files/save-file';
import { StepUp } from '../shared/step-up/step-up';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { InvoiceBulk } from './invoice-bulk';
import { InvoicesFacade } from './invoices-facade';
import type { BulkCheck } from './invoices-types';

describe('InvoiceBulk', () => {
  let check: BulkCheck | Error;
  let saved: string[];

  const checked = vi.fn<
    (companyId: string, action: string, ids: readonly string[]) => Promise<BulkCheck>
  >(() => (check instanceof Error ? Promise.reject(check) : Promise.resolve(check)));

  function create(ids: readonly string[]) {
    saved = [];
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr' }),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideQuietFeedback(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: StepUp, useValue: { request: vi.fn() } },
        { provide: FileSaver, useValue: { save: (_file: Blob, name: string) => saved.push(name) } },
        {
          provide: InvoicesFacade,
          useValue: {
            bulkCheck: checked,
            selectedExportUrl: (companyId: string, chosen: readonly string[], format: string) =>
              `/api/companies/${companyId}/exports/invoices.${format}?${chosen.map((id) => `id[]=${id}`).join('&')}`,
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(InvoiceBulk);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('ids', ids);
    fixture.detectChanges();
    return fixture;
  }

  afterEach(() => {
    checked.mockClear();
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  const row = (id: string, number: string | null, included = true) => ({
    id,
    number,
    customerName: included ? 'Carthage Conseil' : null,
    included,
    reason: included ? null : ('not_found' as const),
  });
  const body = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve));
  }

  async function exportAs(
    fixture: ReturnType<typeof create>,
    format: 'csv' | 'xlsx',
  ): Promise<void> {
    (
      fixture.nativeElement.querySelector('[data-testid="invoices-bulk-export"]') as HTMLElement
    ).click();
    fixture.detectChanges();
    body(`invoices-bulk-export-${format}`)!.click();
    await settle();
    fixture.detectChanges();
  }

  it('downloads the selected invoices straight away when the API finds every one of them', async () => {
    check = { rows: [row('a', 'FAC-1'), row('b', null)], included: 2, skipped: 0 };
    const fixture = create(['a', 'b']);

    await exportAs(fixture, 'xlsx');
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/c1/exports/invoices.xlsx?id[]=a&id[]=b')
      .flush(new Blob(['file']));
    await settle();

    expect(checked).toHaveBeenCalledExactlyOnceWith('c1', 'export', ['a', 'b']);
    expect(body('invoice-bulk-skipped')).toBeNull();
    expect(saved).toEqual(['invoices.xlsx']);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      expect.objectContaining({ key: 'invoices.bulk.exported', params: { count: 2 } }),
    ]);
  });

  it('names each invoice that cannot go in, and downloads the others only once the person says so', async () => {
    check = { rows: [row('a', 'FAC-1'), row('z', null, false)], included: 1, skipped: 1 };
    const fixture = create(['a', 'z']);
    const http = TestBed.inject(HttpTestingController);

    await exportAs(fixture, 'csv');

    expect(body('invoice-bulk-skipped')?.textContent).toContain('invoices.bulk.reasons.not_found');
    expect(body('invoice-bulk-skipped-z')).not.toBeNull();
    expect(body('invoice-bulk-skipped-a')).toBeNull();
    http.expectNone((request) => request.url.includes('/exports/'));

    body('invoice-bulk-go')!.click();
    await settle();
    http
      .expectOne('/api/companies/c1/exports/invoices.csv?id[]=a&id[]=z')
      .flush(new Blob(['file']));
    await settle();

    expect(saved).toEqual(['invoices.csv']);
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
      expect.objectContaining({ key: 'invoices.bulk.exported', params: { count: 1 } }),
    ]);
  });

  it('fetches nothing when the person stops at the invoices that cannot go in', async () => {
    check = { rows: [row('a', 'FAC-1'), row('z', null, false)], included: 1, skipped: 1 };
    const fixture = create(['a', 'z']);

    await exportAs(fixture, 'csv');
    body('invoice-bulk-cancel')!.click();
    await settle();

    TestBed.inject(HttpTestingController).expectNone((request) =>
      request.url.includes('/exports/'),
    );
    expect(saved).toEqual([]);
  });

  it('offers only to close when nothing selected can go in', async () => {
    check = { rows: [row('z', null, false)], included: 0, skipped: 1 };
    const fixture = create(['z']);

    await exportAs(fixture, 'csv');

    expect(body('invoice-bulk-go')).toBeNull();
    expect(body('invoice-bulk-cancel')?.textContent).toContain('invoices.bulk.close');
  });

  it('says the selection could not be checked, and fetches nothing, when the API fails', async () => {
    check = new Error('network');
    const fixture = create(['a']);

    await exportAs(fixture, 'csv');

    TestBed.inject(HttpTestingController).expectNone((request) =>
      request.url.includes('/exports/'),
    );
    expect((TestBed.inject(Feedback) as RecordedFeedback).said.map((toast) => toast.key)).toEqual([
      'invoices.bulk.check_failed',
    ]);
  });
});
