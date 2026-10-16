// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { InvoicesAddTo } from './invoices-add-to';
import { InvoicesApi } from './invoices-api';
import { INVOICE_ADD_TO } from './invoices-add-to-source';

describe('InvoicesAddTo', () => {
  const api = { invoices: vi.fn(), pickCustomers: vi.fn() };

  beforeEach(() => {
    api.invoices.mockReset().mockResolvedValue({
      rows: [
        {
          id: 'd1',
          customerName: 'Carthage',
          customerReference: 'BC-12',
          total: '119.000',
          lines: [{}, {}],
        },
      ],
      total: 1,
    });
    api.pickCustomers
      .mockReset()
      .mockResolvedValue([{ id: 'k1', number: 'C-7', name: 'Carthage' }]);
    TestBed.configureTestingModule({ providers: [{ provide: InvoicesApi, useValue: api }] });
  });

  it('is offered to whoever may write an invoice, in the invoices module, and loaded only then', async () => {
    expect(INVOICE_ADD_TO).toMatchObject({
      kind: 'invoice',
      module: 'invoices',
      permission: 'invoice.write',
      path: '/invoices',
    });
    expect(await INVOICE_ADD_TO.reader()).toBe(InvoicesAddTo);
  });

  it('reads the open invoice drafts of one customer or of every customer, never a credit note', async () => {
    const reader = TestBed.inject(InvoicesAddTo);
    expect(await reader.drafts('c1', 'k1')).toEqual([
      {
        id: 'd1',
        customerName: 'Carthage',
        customerReference: 'BC-12',
        total: '119.000',
        lineCount: 2,
      },
    ]);
    expect(api.invoices).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        status: ['draft'],
        documentType: ['invoice'],
        customerIds: ['k1'],
      }),
    );
    await reader.drafts('c1', null);
    expect(api.invoices).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ customerIds: [] }),
    );
  });

  it('finds the customers an invoice may be written for', async () => {
    expect(await TestBed.inject(InvoicesAddTo).customers('c1', 'carth')).toEqual([
      { id: 'k1', code: 'C-7', name: 'Carthage' },
    ]);
    expect(api.pickCustomers).toHaveBeenCalledExactlyOnceWith('c1', { words: 'carth' });
  });
});
