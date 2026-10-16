// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QuotesAddTo } from './quotes-add-to';
import { QuotesApi } from './quotes-api';
import { QUOTE_ADD_TO } from './quotes-add-to-source';

describe('QuotesAddTo', () => {
  const api = { quotes: vi.fn(), pickCustomers: vi.fn() };

  beforeEach(() => {
    api.quotes.mockReset().mockResolvedValue({
      rows: [{ id: 'q1', customerName: null, customerReference: null, total: '10.000', lines: [] }],
      total: 1,
    });
    api.pickCustomers
      .mockReset()
      .mockResolvedValue([{ id: 'k1', number: 'C-7', name: 'Carthage' }]);
    TestBed.configureTestingModule({ providers: [{ provide: QuotesApi, useValue: api }] });
  });

  it('is offered to whoever may write a quote, in the quotes module, and loaded only then', async () => {
    expect(QUOTE_ADD_TO).toMatchObject({
      kind: 'quote',
      module: 'quotes',
      permission: 'quote.write',
      path: '/quotes',
    });
    expect(await QUOTE_ADD_TO.reader()).toBe(QuotesAddTo);
  });

  it('reads the open quote drafts of the customer given', async () => {
    const reader = TestBed.inject(QuotesAddTo);
    expect(await reader.drafts('c1', 'k1')).toEqual([
      { id: 'q1', customerName: null, customerReference: null, total: '10.000', lineCount: 0 },
    ]);
    expect(api.quotes).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ status: ['draft'], customerIds: ['k1'] }),
    );
  });

  it('finds the customers a quote may be written for', async () => {
    await TestBed.inject(QuotesAddTo).customers('c1', 'sous');
    expect(api.pickCustomers).toHaveBeenCalledExactlyOnceWith('c1', { words: 'sous' });
  });
});
