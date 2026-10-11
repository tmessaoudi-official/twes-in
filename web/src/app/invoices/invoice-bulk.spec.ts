// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog, type MatDialogRef } from '@angular/material/dialog';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FileSaver } from '../shared/files/save-file';
import { todayIn } from '../shared/i18n/format';
import { StepUp } from '../shared/step-up/step-up';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { InvoiceBulk } from './invoice-bulk';
import { InvoicesFacade } from './invoices-facade';
import type { BulkCheck, BulkSkipReason } from './invoices-types';

describe('InvoiceBulk', () => {
  let check: BulkCheck | Error;
  let saved: string[];

  const checked = vi.fn<
    (companyId: string, action: string, ids: readonly string[]) => Promise<BulkCheck>
  >(() => (check instanceof Error ? Promise.reject(check) : Promise.resolve(check)));

  function create(ids: readonly string[], mayPay = false) {
    saved = [];
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr' }),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideQuietFeedback(),
        { provide: AuthFacade, useValue: { me: () => null } },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: StepUp, useValue: { request: vi.fn() } },
        { provide: FileSaver, useValue: { save: (_file: Blob, name: string) => saved.push(name) } },
        {
          provide: InvoicesFacade,
          useValue: {
            bulkCheck: checked,
            selectedExportUrl: (companyId: string, chosen: readonly string[], format: string) =>
              `/api/companies/${companyId}/exports/invoices.${format}?${chosen.map((id) => `id[]=${id}`).join('&')}`,
            selectedPdfUrl: (companyId: string, chosen: readonly string[], format: string) =>
              `/api/companies/${companyId}/exports/invoices.${format}?${chosen.map((id) => `id[]=${id}`).join('&')}`,
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(InvoiceBulk);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('ids', ids);
    fixture.componentRef.setInput('mayPay', mayPay);
    fixture.componentRef.setInput('scale', 3);
    fixture.componentRef.setInput('timeZone', 'Africa/Tunis');
    fixture.detectChanges();
    return fixture;
  }

  afterEach(() => {
    checked.mockClear();
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  const row = (
    id: string,
    number: string | null,
    included = true,
    reason: BulkSkipReason = 'not_found',
  ) => ({
    id,
    number,
    customerName: included || reason !== 'not_found' ? 'Carthage Conseil' : null,
    included,
    reason: included ? null : reason,
    amount: null,
  });
  const body = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve));
  }

  async function exportAs(
    fixture: ReturnType<typeof create>,
    format: 'csv' | 'xlsx' | 'pdf' | 'zip',
  ): Promise<void> {
    const menu =
      format === 'pdf' || format === 'zip' ? 'invoices-bulk-pdf' : 'invoices-bulk-export';
    (fixture.nativeElement.querySelector(`[data-testid="${menu}"]`) as HTMLElement).click();
    fixture.detectChanges();
    body(`${menu}-${format}`)!.click();
    await settle();
    fixture.detectChanges();
  }

  const said = () => (TestBed.inject(Feedback) as RecordedFeedback).said;

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

  it('prints the selected invoices as one PDF straight away when every one of them goes in', async () => {
    check = { rows: [row('a', 'FAC-1'), row('b', 'FAC-2')], included: 2, skipped: 0 };
    const fixture = create(['a', 'b']);

    await exportAs(fixture, 'pdf');
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/c1/exports/invoices.pdf?id[]=a&id[]=b')
      .flush(new Blob(['%PDF-']));
    await settle();

    expect(checked).toHaveBeenCalledExactlyOnceWith('c1', 'pdf', ['a', 'b']);
    expect(body('invoice-bulk-skipped')).toBeNull();
    expect(saved).toEqual(['invoices.pdf']);
    expect(said()).toEqual([
      expect.objectContaining({ key: 'invoices.bulk.printed', params: { count: 2 } }),
    ]);
  });

  it('prints them as a zip of one PDF each when that is the entry chosen', async () => {
    check = { rows: [row('a', 'FAC-1')], included: 1, skipped: 0 };
    const fixture = create(['a']);

    await exportAs(fixture, 'zip');
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/c1/exports/invoices.zip?id[]=a')
      .flush(new Blob(['PK']));
    await settle();

    expect(saved).toEqual(['invoices.zip']);
  });

  // Drafts and cancelled ones print only when asked for, each kind by its own box, off at every opening.
  it('offers a box for the drafts and one for the cancelled ones selected, and prints those ticked under their watermark', async () => {
    check = {
      rows: [
        row('a', 'FAC-1'),
        row('d', null, false, 'draft'),
        row('x', null, false, 'cancelled'),
        row('z', null, false),
      ],
      included: 1,
      skipped: 3,
    };
    const fixture = create(['a', 'd', 'x', 'z']);

    await exportAs(fixture, 'pdf');
    const drafts = body('invoice-bulk-include-drafts')?.querySelector('input') as HTMLInputElement;
    const cancelled = body('invoice-bulk-include-cancelled')?.querySelector(
      'input',
    ) as HTMLInputElement;
    expect([drafts.checked, cancelled.checked]).toEqual([false, false]);
    expect(body('invoice-bulk-skipped-d')).not.toBeNull();

    drafts.click();
    await settle();
    fixture.detectChanges();
    expect(body('invoice-bulk-skipped-d')).toBeNull();
    expect(body('invoice-bulk-skipped-x')).not.toBeNull();
    expect(body('invoice-bulk-skipped-z')).not.toBeNull();
    body('invoice-bulk-go')!.click();
    await settle();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/c1/exports/invoices.pdf?id[]=a&id[]=d&id[]=x&id[]=z&drafts=1')
      .flush(new Blob(['%PDF-']));
    await settle();

    expect(said()).toEqual([
      expect.objectContaining({ key: 'invoices.bulk.printed', params: { count: 2 } }),
    ]);
  });

  it('offers no box for a kind the selection does not hold, and none at all for an export', async () => {
    check = { rows: [row('a', 'FAC-1'), row('d', null, false, 'draft')], included: 1, skipped: 1 };
    const fixture = create(['a', 'd']);

    await exportAs(fixture, 'pdf');
    expect(body('invoice-bulk-include-drafts')).not.toBeNull();
    expect(body('invoice-bulk-include-cancelled')).toBeNull();
    body('invoice-bulk-cancel')!.click();
    await settle();

    // A file of rows has no watermark to print a draft under: whatever the selection holds, no box.
    check = { rows: [row('a', 'FAC-1'), row('d', null, false, 'draft')], included: 1, skipped: 1 };
    await exportAs(fixture, 'csv');
    expect(body('invoice-bulk-include-drafts')).toBeNull();
    expect(body('invoice-bulk-include-cancelled')).toBeNull();
  });

  it('lets the drafts alone go in once their box is ticked, when nothing else was selected', async () => {
    check = { rows: [row('d', null, false, 'draft')], included: 0, skipped: 1 };
    const fixture = create(['d']);

    await exportAs(fixture, 'pdf');
    expect(body('invoice-bulk-go')).toBeNull();
    (body('invoice-bulk-include-drafts')?.querySelector('input') as HTMLInputElement).click();
    await settle();
    fixture.detectChanges();

    body('invoice-bulk-go')!.click();
    await settle();
    TestBed.inject(HttpTestingController)
      .expectOne('/api/companies/c1/exports/invoices.pdf?id[]=d&drafts=1')
      .flush(new Blob(['%PDF-']));
  });

  it('offers « Encaisser » only to a member who may record payments', () => {
    expect(
      create(['a']).nativeElement.querySelector('[data-testid="invoices-bulk-pay"]'),
    ).toBeNull();
    TestBed.resetTestingModule();
    expect(
      create(['a'], true).nativeElement.querySelector('[data-testid="invoices-bulk-pay"]'),
    ).not.toBeNull();
  });

  it('opens the payment of the selection on the company day, then says what was received and has the list read again', async () => {
    const fixture = create(['a', 'b'], true);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of({ recorded: 2, total: '1500.250' }),
    } as MatDialogRef<unknown>);
    const changed = vi.fn();
    fixture.componentInstance.changed.subscribe(changed);

    (
      fixture.nativeElement.querySelector('[data-testid="invoices-bulk-pay"]') as HTMLElement
    ).click();
    await settle();

    expect(open.mock.calls[0]![1]!.data).toEqual({
      companyId: 'c1',
      ids: ['a', 'b'],
      today: todayIn('Africa/Tunis'),
      scale: 3,
    });
    expect(said()).toEqual([
      expect.objectContaining({
        key: 'invoices.bulk.paid',
        params: { count: 2, total: expect.stringMatching(/1[\s\u202f.]?500[.,]250/) },
      }),
    ]);
    expect(changed).toHaveBeenCalledOnce();
  });

  it('says nothing and reads nothing again when the payment dialog is closed without paying', async () => {
    const fixture = create(['a'], true);
    vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(null),
    } as MatDialogRef<unknown>);
    const changed = vi.fn();
    fixture.componentInstance.changed.subscribe(changed);

    (
      fixture.nativeElement.querySelector('[data-testid="invoices-bulk-pay"]') as HTMLElement
    ).click();
    await settle();

    expect(said()).toEqual([]);
    expect(changed).not.toHaveBeenCalled();
  });
});
