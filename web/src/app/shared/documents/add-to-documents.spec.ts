// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Session } from '../session/session';
import type { AddToChoice, AddToData, AddToDraft } from './add-to-dialog';
import { AddToDocuments } from './add-to-documents';
import { ADD_TO_SOURCES, type AddToReader, type AddToSource } from './add-to-sources';

const draft: AddToDraft = {
  id: 'd1',
  customerName: 'Carthage',
  customerReference: 'BC-12',
  total: '119.000',
  lineCount: 2,
};

const read = { drafts: vi.fn(), customers: vi.fn() };

@Injectable({ providedIn: 'root' })
class StubReader implements AddToReader {
  drafts(companyId: string, customerId: string | null): Promise<readonly AddToDraft[]> {
    return read.drafts(companyId, customerId);
  }

  customers(companyId: string, words: string) {
    return read.customers(companyId, words);
  }
}

const source = (kind: AddToSource['kind'], module: string, path: string): AddToSource => ({
  kind,
  party: kind === 'purchase_order' ? 'vendor' : 'customer',
  module,
  permission: `${kind}.write`,
  path,
  reader: async () => StubReader,
});

describe('AddToDocuments', () => {
  const granted = new Set<string>();
  const modules = new Set<string>();
  const dialog = { open: vi.fn() };
  const navigate = vi.fn();

  function configure(): void {
    TestBed.configureTestingModule({
      providers: [
        AddToDocuments,
        {
          provide: Session,
          useValue: {
            me: () => ({ company: { id: 'c1' } }),
            hasPermission: (permission: string) => granted.has(permission),
            hasModule: (module: string) => modules.has(module),
          },
        },
        {
          provide: ADD_TO_SOURCES,
          useValue: [
            source('invoice', 'invoices', '/invoices'),
            source('quote', 'quotes', '/quotes'),
            source('delivery_note', 'delivery_notes', '/delivery-notes'),
            source('purchase_order', 'purchases', '/purchases'),
          ],
        },
        { provide: MatDialog, useValue: dialog },
        { provide: Router, useValue: { navigate } },
      ],
    });
  }

  const service = (): AddToDocuments => TestBed.inject(AddToDocuments);

  function chooses(choice: AddToChoice | null): void {
    dialog.open.mockReturnValue({ afterClosed: () => of(choice) });
  }

  const data = (): AddToData => dialog.open.mock.calls[0]![1].data;

  beforeEach(() => {
    granted.clear();
    modules.clear();
    for (const each of ['invoice.write', 'quote.write', 'delivery_note.write']) granted.add(each);
    for (const each of ['invoices', 'quotes', 'delivery_notes']) modules.add(each);
    granted.add('purchase_order.write');
    modules.add('purchases');
    read.drafts.mockReset().mockResolvedValue([draft]);
    read.customers.mockReset().mockResolvedValue([{ id: 'k1', code: 'C-7', name: 'Carthage' }]);
    dialog.open.mockReset();
    navigate.mockReset().mockResolvedValue(true);
    configure();
  });

  it('offers each kind the person may write, in a module switched on', () => {
    granted.delete('quote.write');
    modules.delete('delivery_notes');
    expect(service().kinds()).toEqual(['invoice', 'purchase_order']);
    modules.delete('purchases');
    expect(service().kinds()).toEqual(['invoice']);
  });

  it('offers nothing when no kind may be written', () => {
    granted.clear();
    expect(service().kinds()).toEqual([]);
  });

  it('asks nothing when there is nothing to add or nowhere to add it', async () => {
    await service().open([]);
    granted.clear();
    await service().open(['p1']);
    expect(dialog.open).not.toHaveBeenCalled();
  });

  it('opens the document chosen with the products on its lines, a new one or an open draft', async () => {
    chooses({ kind: 'quote', draftId: null });
    await service().open(['p1', 'p2']);

    expect(data().count).toBe(2);
    expect(data().title).toBe('documents.add_to.title');
    expect(data().help).toBeNull();
    expect(data().targets.map((target) => [target.kind, target.party])).toEqual([
      ['invoice', 'customer'],
      ['quote', 'customer'],
      ['delivery_note', 'customer'],
      ['purchase_order', 'vendor'],
    ]);
    expect(navigate).toHaveBeenCalledExactlyOnceWith(['/quotes', 'new'], {
      queryParams: { add: ['p1', 'p2'] },
    });

    dialog.open.mockReset();
    navigate.mockReset();
    chooses({ kind: 'delivery_note', draftId: 'n7' });
    await service().open(['p1']);
    expect(navigate).toHaveBeenCalledExactlyOnceWith(['/delivery-notes', 'n7'], {
      queryParams: { add: ['p1'] },
    });

    dialog.open.mockReset();
    navigate.mockReset();
    chooses({ kind: 'purchase_order', draftId: 'o3' });
    await service().open(['p1']);
    expect(navigate).toHaveBeenCalledExactlyOnceWith(['/purchases', 'o3'], {
      queryParams: { add: ['p1'] },
    });
  });

  it('goes nowhere when nothing was chosen', async () => {
    chooses(null);
    await service().open(['p1']);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('reads drafts and customers through each kind’s reader, for the company acted for', async () => {
    chooses(null);
    await service().open(['p1'], 'k1');
    expect(data().customerId).toBe('k1');
    const [invoice] = data().targets;

    expect(await invoice!.drafts('k1')).toEqual([draft]);
    expect(read.drafts).toHaveBeenCalledExactlyOnceWith('c1', 'k1');
    await invoice!.customers('carth');
    expect(read.customers).toHaveBeenCalledExactlyOnceWith('c1', 'carth');
  });

  it('lets the person pick the customer when the screen knows none', async () => {
    chooses(null);
    await service().open(['p1']);
    expect(data().customerId).toBeNull();
  });
});
