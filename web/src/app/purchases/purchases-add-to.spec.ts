// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PurchasesAddTo } from './purchases-add-to';
import { PURCHASE_ORDER_ADD_TO } from './purchases-add-to-source';
import { PurchasesApi } from './purchases-api';

describe('PurchasesAddTo', () => {
  const api = { orders: vi.fn(), pickVendors: vi.fn() };

  beforeEach(() => {
    api.orders.mockReset().mockResolvedValue({
      rows: [
        { id: 'o1', vendorName: 'Sotumag', totalNet: null, lines: [{}, {}] },
        { id: 'o2', vendorName: 'Sotumag', totalNet: '12.000', lines: [{}] },
      ],
      total: 2,
    });
    api.pickVendors
      .mockReset()
      .mockResolvedValue([{ id: 'v1', number: 'FRN-0001', name: 'Sotumag', active: true }]);
    TestBed.configureTestingModule({ providers: [{ provide: PurchasesApi, useValue: api }] });
  });

  it('is offered to whoever may draft an order, in the purchases module, found by vendor', async () => {
    expect(PURCHASE_ORDER_ADD_TO).toMatchObject({
      kind: 'purchase_order',
      party: 'vendor',
      module: 'purchases',
      permission: 'purchase.write',
      path: '/purchases',
    });
    expect(await PURCHASE_ORDER_ADD_TO.reader()).toBe(PurchasesAddTo);
  });

  it('reads the open drafts of the vendor given, a draft priced by nobody coming to no total', async () => {
    const reader = TestBed.inject(PurchasesAddTo);
    expect(await reader.drafts('c1', 'v1')).toEqual([
      { id: 'o1', customerName: 'Sotumag', customerReference: null, total: null, lineCount: 2 },
      { id: 'o2', customerName: 'Sotumag', customerReference: null, total: '12.000', lineCount: 1 },
    ]);
    expect(api.orders).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ status: ['draft'], vendorIds: ['v1'], page: 1 }),
    );

    await reader.drafts('c1', null);
    expect(api.orders).toHaveBeenLastCalledWith('c1', expect.objectContaining({ vendorIds: [] }));
  });

  it('finds the vendors an order may go to', async () => {
    expect(await TestBed.inject(PurchasesAddTo).customers('c1', 'sotu')).toEqual([
      { id: 'v1', code: 'FRN-0001', name: 'Sotumag' },
    ]);
    expect(api.pickVendors).toHaveBeenCalledExactlyOnceWith('c1', { words: 'sotu' });
  });
});
