// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed, type ComponentFixture } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideTranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { Feedback } from '../shared/feedback/feedback';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { InvoiceBulkPayDialog } from './invoice-bulk-pay-dialog';
import { InvoicesRefused } from './invoices-api';
import { InvoicesFacade } from './invoices-facade';
import type { BulkCheck, BulkCheckRow, BulkPaid, BulkPayment } from './invoices-types';

describe('InvoiceBulkPayDialog', () => {
  let checks: (BulkCheck | Promise<BulkCheck>)[];
  let paid: BulkPaid | Error;
  const close = vi.fn();
  const checked = vi.fn<
    (
      companyId: string,
      action: string,
      ids: readonly string[],
      date: string | null,
    ) => Promise<BulkCheck>
  >(() => Promise.resolve(checks.shift() ?? { rows: [], included: 0, skipped: 0 }));
  const payments = vi.fn<(companyId: string, payment: BulkPayment) => Promise<BulkPaid>>(() =>
    paid instanceof Error ? Promise.reject(paid) : Promise.resolve(paid),
  );

  const due = (id: string, number: string, amount: string): BulkCheckRow => ({
    id,
    number,
    customerName: 'Carthage Conseil',
    included: true,
    reason: null,
    amount,
  });
  const left = (id: string, number: string, reason: BulkCheckRow['reason']): BulkCheckRow => ({
    id,
    number,
    customerName: 'Carthage Conseil',
    included: false,
    reason,
    amount: null,
  });
  const preview = (...rows: BulkCheckRow[]): BulkCheck => ({
    rows,
    included: rows.filter((row) => row.included).length,
    skipped: rows.filter((row) => !row.included).length,
  });

  async function open(): Promise<ComponentFixture<InvoiceBulkPayDialog>> {
    TestBed.configureTestingModule({
      imports: [InvoiceBulkPayDialog],
      providers: [
        provideTranslateService({ lang: 'fr' }),
        provideQuietFeedback(),
        { provide: AuthFacade, useValue: { me: () => null } },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MatDialogRef, useValue: { close } },
        {
          provide: MAT_DIALOG_DATA,
          useValue: { companyId: 'c1', ids: ['a', 'b', 'c'], today: '2026-10-11', scale: 3 },
        },
        { provide: InvoicesFacade, useValue: { bulkCheck: checked, bulkPay: payments } },
      ],
    });
    const fixture = TestBed.createComponent(InvoiceBulkPayDialog);
    await settle(fixture);
    return fixture;
  }

  async function settle(fixture: ComponentFixture<InvoiceBulkPayDialog>, wait = 0): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve, wait));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const el = (
    fixture: ComponentFixture<InvoiceBulkPayDialog>,
    testId: string,
  ): HTMLElement | null => fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const field = (fixture: ComponentFixture<InvoiceBulkPayDialog>, id: string): HTMLInputElement =>
    fixture.nativeElement.querySelector(`[data-testid="field-${id}"]`);
  /** The day is typed as the locale writes it; the control holds the API's day, which is what is under test. */
  const dayOf = (fixture: ComponentFixture<InvoiceBulkPayDialog>) =>
    (fixture.componentInstance as unknown as { group: DescriptorFormGroup }).group.controls[
      'date'
    ]!;
  const go = (fixture: ComponentFixture<InvoiceBulkPayDialog>) =>
    el(fixture, 'invoice-bulk-pay-go') as HTMLButtonElement;
  const said = () => (TestBed.inject(Feedback) as RecordedFeedback).said;

  afterEach(() => {
    checked.mockClear();
    payments.mockClear();
    close.mockClear();
  });

  it('previews on the company day what each invoice receives, their total, and each one left out with why', async () => {
    checks = [
      preview(due('a', 'FAC-1', '100.500'), left('b', 'FAC-2', 'paid'), due('c', 'FAC-3', '0.250')),
    ];
    const fixture = await open();

    expect(checked).toHaveBeenCalledExactlyOnceWith('c1', 'pay', ['a', 'b', 'c'], '2026-10-11');
    const rows = el(fixture, 'invoice-bulk-pay-rows')!.textContent!;
    expect(rows).toContain('FAC-1');
    expect(rows).toContain('FAC-3');
    expect(rows).not.toContain('FAC-2');
    expect(el(fixture, 'invoice-bulk-pay-total')!.textContent).toMatch(/100[.,]750/);
    expect(el(fixture, 'invoice-bulk-pay-skipped-b')!.textContent).toContain(
      'invoices.bulk.reasons.paid',
    );
    expect(go(fixture).disabled).toBe(false);
  });

  it('asks again for another day, and dates the payments by the day it previewed', async () => {
    checks = [preview(due('a', 'FAC-1', '10.000')), preview(left('a', 'FAC-1', 'issued_after'))];
    const fixture = await open();

    dayOf(fixture).setValue('2026-10-01');
    await settle(fixture, 350);

    expect(checked).toHaveBeenLastCalledWith('c1', 'pay', ['a', 'b', 'c'], '2026-10-01');
    expect(el(fixture, 'invoice-bulk-pay-skipped-a')!.textContent).toContain(
      'invoices.bulk.reasons.issued_after',
    );
    expect(el(fixture, 'invoice-bulk-pay-rows')).toBeNull();
    expect(go(fixture).disabled).toBe(true);
  });

  it('records the rows it showed by one method, cash unless another is picked, day and reference, and hands back what was recorded', async () => {
    checks = [
      preview(
        due('a', 'FAC-1', '100.500'),
        left('b', 'FAC-2', 'draft'),
        due('c', 'FAC-3', '0.250'),
      ),
    ];
    paid = { recorded: 2, total: '100.750' };
    const fixture = await open();
    const reference = field(fixture, 'reference');
    reference.value = '  VIR-42 ';
    reference.dispatchEvent(new Event('input'));

    go(fixture).click();
    await settle(fixture);

    expect(payments).toHaveBeenCalledExactlyOnceWith('c1', {
      method: 'cash',
      date: '2026-10-11',
      reference: 'VIR-42',
      rows: [
        { id: 'a', amount: '100.500' },
        { id: 'c', amount: '0.250' },
      ],
    });
    expect(close).toHaveBeenCalledExactlyOnceWith({ recorded: 2, total: '100.750' });
  });

  it('pays nothing and shows the preview as it now stands when an invoice changed since', async () => {
    checks = [
      preview(due('a', 'FAC-1', '100.500'), due('b', 'FAC-2', '5.000')),
      preview(due('a', 'FAC-1', '100.500'), left('b', 'FAC-2', 'paid')),
    ];
    paid = new InvoicesRefused('conflict');
    const fixture = await open();

    go(fixture).click();
    await settle(fixture);

    expect(close).not.toHaveBeenCalled();
    expect(said()).toEqual([expect.objectContaining({ key: 'invoices.bulk.pay_changed' })]);
    expect(checked).toHaveBeenCalledTimes(2);
    expect(el(fixture, 'invoice-bulk-pay-skipped-b')!.textContent).toContain(
      'invoices.bulk.reasons.paid',
    );
    expect(el(fixture, 'invoice-bulk-pay-total')!.textContent).toMatch(/100[.,]500/);
  });

  it('says why when the API refuses for another reason, and stays open', async () => {
    checks = [preview(due('a', 'FAC-1', '1.000'))];
    paid = new InvoicesRefused('period_closed');
    const fixture = await open();

    go(fixture).click();
    await settle(fixture);

    expect(close).not.toHaveBeenCalled();
    expect(said()).toEqual([expect.objectContaining({ key: 'invoices.errors.period_closed' })]);
  });

  it('shows the answer to the day asked last, even when an earlier answer arrives after it', async () => {
    let answerFirst!: (check: BulkCheck) => void;
    checks = [
      new Promise<BulkCheck>((resolve) => (answerFirst = resolve)),
      preview(due('a', 'FAC-1', '7.000')),
    ];
    const fixture = await open();
    dayOf(fixture).setValue('2026-10-10');
    await settle(fixture, 350);

    answerFirst(preview(left('a', 'FAC-1', 'paid')));
    await settle(fixture);

    expect(el(fixture, 'invoice-bulk-pay-total')!.textContent).toMatch(/7[.,]000/);
    expect(el(fixture, 'invoice-bulk-pay-skipped-a')).toBeNull();
  });
});
