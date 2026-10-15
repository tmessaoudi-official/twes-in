// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthFacade } from '../auth/auth-facade';
import { RecurringApi } from '../recurring/recurring-api';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { TextsParts } from '../shared/i18n/texts-part';
import { onRecord, onRows } from '../shared/actions/record-action';
import { FileSaver } from '../shared/files/save-file';
import { rareOn } from '../shared/list/list-types';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { InvoiceActions } from './invoice-actions';
import { InvoicesFacade } from './invoices-facade';
import type { InvoiceRow } from './invoices-types';

const base: InvoiceRow = {
  id: 'i1',
  type: 'invoice',
  correctsInvoiceId: null,
  creditNoteReason: null,
  deposit: false,
  quoteId: null,
  number: 'FAC-2026-00045',
  status: 'issued',
  customerId: 'k1',
  recordedCustomerName: 'Leïla Gharbi',
  customerName: 'Leïla Gharbi',
  establishmentId: 'e1',
  issueDate: '2026-09-10',
  dueDate: '2026-10-10',
  supplyDate: null,
  paymentTermsDays: 30,
  customerReference: null,
  notesPrinted: null,
  notesInternal: null,
  discountAmount: null,
  operationCategory: null,
  vatOnDebits: null,
  documentTaxComponentIds: [],
  lines: [],
  subtotalNet: '100.000',
  documentDiscount: '0.000',
  savings: null,
  totalNet: '100.000',
  taxes: [],
  totalTax: '19.000',
  fixedTaxes: [],
  total: '119.000',
  withholdings: [],
  netToPay: '119.000',
  amountDue: '119.000',
  amountPaid: '0.000',
  amountCredited: '0.000',
  payments: [],
};

const draft: InvoiceRow = {
  ...base,
  number: null,
  status: 'draft',
  issueDate: null,
  dueDate: null,
};
const due = base;
const paid: InvoiceRow = { ...base, status: 'paid', amountDue: '0.000', amountPaid: '119.000' };
const abandoned: InvoiceRow = { ...draft, status: 'cancelled' };
const creditNote: InvoiceRow = {
  ...base,
  type: 'credit_note',
  correctsInvoiceId: 'i0',
  number: 'AV-2026-00002',
};

const STATES: Record<string, InvoiceRow> = { draft, due, paid, abandoned, creditNote };

// docs/SPEC.md § 7, 2026-10-11 08:26: one declaration per record type, read by the page, the sheet and the rows.
describe('InvoiceActions', () => {
  const granted = new Set<string>();
  const modules = new Set<string>();
  let country = 'TN';
  const facade = {
    busy: signal(false).asReadonly(),
    pdfUrl: (companyId: string, id: string) => `/api/companies/${companyId}/invoices/${id}/pdf`,
    pdfCopyUrl: (companyId: string, id: string, kind: string) =>
      `/api/companies/${companyId}/invoices/${id}/pdf?copy=${kind}`,
    recordPayment: vi.fn().mockResolvedValue(true),
    cancel: vi.fn(),
  };
  const dialog = { open: vi.fn() };

  function actions(): InvoiceActions {
    return TestBed.inject(InvoiceActions);
  }

  /** What the record's page offers of the declaration for a document: its ids, as placed, rare read first as the bars do. */
  function onPage(invoice: InvoiceRow): string[] {
    return onRecord(actions().declare(), invoice, false)
      .filter((action) => action.shown !== false)
      .map(
        (action) =>
          `${action.id}:${action.rare || action.destructive ? 'rare' : action.primary ? 'next' : 'frequent'}`,
      );
  }

  /** What the same document's row offers. */
  function onRow(invoice: InvoiceRow): string[] {
    return onRows(actions().declare())
      .filter((action) => action.shown?.(invoice) ?? true)
      .map(
        (action) =>
          `${action.id}:${rareOn(action, invoice) ? 'rare' : action.next?.(invoice) ? 'next' : 'frequent'}`,
      );
  }

  beforeEach(() => {
    granted.clear();
    for (const permission of [
      'invoice.write',
      'invoice.issue',
      'invoice.credit',
      'payment.write',
    ]) {
      granted.add(permission);
    }
    modules.clear();
    modules.add('recurring');
    country = 'TN';
    dialog.open.mockReset();
    facade.recordPayment.mockClear();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        provideQuietFeedback(),
        { provide: InvoicesFacade, useValue: facade },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ company: { id: 'c1', timezone: 'Africa/Tunis', countryCode: country } }),
            hasPermission: (permission: string) => granted.has(permission),
            hasModule: (module: string) => modules.has(module),
          },
        },
        { provide: MatDialog, useValue: dialog },
        { provide: FileSaver, useValue: { save: vi.fn() } },
        { provide: RecurringApi, useValue: { create: vi.fn() } },
        { provide: LanguageFacade, useValue: { current: () => 'fr' } },
        { provide: TextsParts, useValue: { open: () => Promise.resolve() } },
      ],
    });
  });

  it('offers a row exactly what the document’s page offers, in every state', () => {
    for (const [state, invoice] of Object.entries(STATES)) {
      expect(onRow(invoice), state).toEqual(onPage(invoice));
    }
  });

  it('offers each state what the confirmed design draws', () => {
    expect(onPage(due)).toEqual([
      'record-payment:next',
      'pdf:frequent',
      'duplicate:frequent',
      'print-duplicate:rare',
      'print-current:rare',
      'make-recurring:rare',
      'credit-note:rare',
    ]);
    expect(onPage(draft)).toEqual([
      'pdf:rare',
      'duplicate:rare',
      'make-recurring:rare',
      'cancel:rare',
    ]);
    expect(onPage(paid)).toEqual([
      'pdf:frequent',
      'duplicate:frequent',
      'print-duplicate:rare',
      'print-current:rare',
      'record-overpayment:rare',
      'make-recurring:rare',
      'credit-note:rare',
    ]);
    expect(onPage(abandoned)).toEqual([
      'pdf:frequent',
      'duplicate:frequent',
      'make-recurring:rare',
    ]);
    // A credit note is paid back, never collected.
    expect(onPage(creditNote)).toEqual([
      'pdf:frequent',
      'duplicate:frequent',
      'print-duplicate:rare',
      'print-current:rare',
    ]);
  });

  it('offers a credit note while the invoice has something left to credit, paid or not, as the API takes one', () => {
    const credits = (invoice: InvoiceRow) => onPage(invoice).includes('credit-note:rare');
    // Goods returned after the invoice was paid in full are credited: the excess dialog says where the money goes.
    expect(credits(paid)).toBe(true);
    expect(credits({ ...paid, amountCredited: '100.000' })).toBe(true);
    // Credited down to its net to pay, it has nothing left, withheld at source or not.
    expect(credits({ ...paid, amountCredited: '119.000' })).toBe(false);
    expect(
      credits({ ...paid, total: '119.000', netToPay: '117.810', amountCredited: '117.810' }),
    ).toBe(false);
    expect(
      credits({ ...paid, total: '119.000', netToPay: '117.810', amountCredited: '117.800' }),
    ).toBe(true);
  });

  it('offers nothing a person may not do', () => {
    granted.clear();
    expect(onPage(due)).toEqual(['pdf:frequent', 'print-duplicate:rare', 'print-current:rare']);
    expect(onPage(draft)).toEqual(['pdf:rare']);
  });

  it('offers the Factur-X files only where the company’s preset writes them, of an issued document', () => {
    country = 'FR';
    expect(onPage(due)).toContain('factur-x-pdf:rare');
    expect(onPage(due)).toContain('factur-x-xml:rare');
    expect(onPage(draft)).not.toContain('factur-x-pdf:rare');
    expect(onPage(abandoned)).not.toContain('factur-x-pdf:rare');
  });

  it('asks before abandoning a draft, with the words that say it is for good', () => {
    const abandon = onRecord(actions().declare(), draft, false).find(
      (action) => action.id === 'cancel',
    );

    expect(abandon?.confirm).toEqual({
      kind: 'definitif',
      title: 'invoices.actions.cancel_title',
      message: 'invoices.actions.cancel_message',
      confirmLabel: 'invoices.actions.confirm_cancel',
      keepLabel: 'invoices.actions.keep',
    });
    expect(abandon?.icon).toBe('delete_forever');
  });

  it('records a payment from a row, asked in the dialog with what the row still owes, and says the row changed', async () => {
    dialog.open.mockReturnValue({
      afterClosed: () =>
        of({ amount: '119.000', date: '2026-10-11', method: 'cash', reference: '', notes: '' }),
    });
    const changed = vi.fn();
    const pay = onRows(actions().declare({ changed })).find(
      (action) => action.id === 'record-payment',
    )!;

    pay.run?.(due);
    await vi.waitFor(() => expect(changed).toHaveBeenCalledWith(due));

    expect(dialog.open.mock.calls[0]![1].data.group.getRawValue().amount).toBe('119.000');
    expect(facade.recordPayment).toHaveBeenCalledWith(
      'c1',
      'i1',
      expect.objectContaining({ amount: '119.000' }),
    );
  });
});
