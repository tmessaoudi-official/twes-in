// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DeliveryNotesAddTo } from './delivery-notes-add-to';
import { DeliveryNotesApi } from './delivery-notes-api';
import { DELIVERY_NOTE_ADD_TO } from './delivery-notes-add-to-source';

describe('DeliveryNotesAddTo', () => {
  const api = { notes: vi.fn(), pickCustomers: vi.fn() };

  beforeEach(() => {
    api.notes.mockReset().mockResolvedValue({
      rows: [
        { id: 'n1', customerName: 'Sousse', customerReference: 'BL', total: '0.000', lines: [{}] },
      ],
      total: 1,
    });
    api.pickCustomers
      .mockReset()
      .mockResolvedValue([{ id: 'k1', number: 'C-7', name: 'Carthage' }]);
    TestBed.configureTestingModule({ providers: [{ provide: DeliveryNotesApi, useValue: api }] });
  });

  it('is offered to whoever may write a delivery note, in its module, and loaded only then', async () => {
    expect(DELIVERY_NOTE_ADD_TO).toMatchObject({
      kind: 'delivery_note',
      module: 'delivery_notes',
      permission: 'delivery_note.write',
      path: '/delivery-notes',
    });
    expect(await DELIVERY_NOTE_ADD_TO.reader()).toBe(DeliveryNotesAddTo);
  });

  it('reads the open delivery note drafts of the customer given', async () => {
    const reader = TestBed.inject(DeliveryNotesAddTo);
    expect(await reader.drafts('c1', 'k1')).toEqual([
      { id: 'n1', customerName: 'Sousse', customerReference: 'BL', total: '0.000', lineCount: 1 },
    ]);
    expect(api.notes).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ status: ['draft'], customerIds: ['k1'] }),
    );
  });

  it('finds the customers a delivery note may be written for', async () => {
    await TestBed.inject(DeliveryNotesAddTo).customers('c1', 'bizerte');
    expect(api.pickCustomers).toHaveBeenCalledExactlyOnceWith('c1', { words: 'bizerte' });
  });
});
