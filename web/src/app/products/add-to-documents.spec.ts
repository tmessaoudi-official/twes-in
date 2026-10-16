// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthFacade } from '../auth/auth-facade';
import { DeliveryNotesApi } from '../delivery-notes/delivery-notes-api';
import { InvoicesApi } from '../invoices/invoices-api';
import { QuotesApi } from '../quotes/quotes-api';
import type { AddToChoice, AddToData } from '../shared/documents/add-to-dialog';
import { AddToDocuments } from './add-to-documents';

const row = {
  id: 'd1',
  customerName: 'Carthage',
  customerReference: 'BC-12',
  total: '119.000',
  lines: [{}, {}],
};

describe('AddToDocuments', () => {
  const granted = new Set<string>();
  const modules = new Set<string>();
  const invoices = { invoices: vi.fn(), pickCustomers: vi.fn() };
  const quotes = { quotes: vi.fn(), pickCustomers: vi.fn() };
  const notes = { notes: vi.fn(), pickCustomers: vi.fn() };
  const dialog = { open: vi.fn() };
  const navigate = vi.fn();

  function configure(): void {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ company: { id: 'c1' } }),
            hasPermission: (permission: string) => granted.has(permission),
            hasModule: (module: string) => modules.has(module),
          },
        },
        { provide: InvoicesApi, useValue: invoices },
        { provide: QuotesApi, useValue: quotes },
        { provide: DeliveryNotesApi, useValue: notes },
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
    for (const list of [invoices.invoices, quotes.quotes, notes.notes]) {
      list.mockReset().mockResolvedValue({ rows: [row], total: 1 });
    }
    for (const pick of [invoices.pickCustomers, quotes.pickCustomers, notes.pickCustomers]) {
      pick.mockReset().mockResolvedValue([{ id: 'k1', number: 'C-7', name: 'Carthage' }]);
    }
    dialog.open.mockReset();
    navigate.mockReset().mockResolvedValue(true);
    configure();
  });

  it('offers each kind the person may write, in a module switched on', () => {
    granted.delete('quote.write');
    modules.delete('delivery_notes');
    expect(service().kinds()).toEqual(['invoice']);
  });

  it('offers nothing when no kind may be written', () => {
    granted.clear();
    expect(service().kinds()).toEqual([]);
  });

  it('opens the document chosen with the products on its lines, a new one or an open draft', async () => {
    chooses({ kind: 'quote', draftId: null });
    await service().open(['p1', 'p2']);

    expect(data().count).toBe(2);
    expect(data().targets.map((target) => target.kind)).toEqual([
      'invoice',
      'quote',
      'delivery_note',
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
  });

  it('goes nowhere when nothing was chosen', async () => {
    chooses(null);
    await service().open(['p1']);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('reads the open drafts of the customer chosen, or of every customer', async () => {
    chooses(null);
    const known = { id: 'k1', code: 'C-7', name: 'Carthage' };
    await service().open(['p1'], known);
    expect(data().customer).toEqual(known);
    const [invoice, quote, note] = data().targets;

    expect(await invoice!.drafts('k1')).toEqual([
      {
        id: 'd1',
        customerName: 'Carthage',
        customerReference: 'BC-12',
        total: '119.000',
        lineCount: 2,
      },
    ]);
    expect(invoices.invoices).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({
        status: ['draft'],
        documentType: ['invoice'],
        customerIds: ['k1'],
      }),
    );
    await quote!.drafts('k1');
    expect(quotes.quotes).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ status: ['draft'], customerIds: ['k1'] }),
    );
    await note!.drafts('k1');
    expect(notes.notes).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ status: ['draft'], customerIds: ['k1'] }),
    );

    await invoice!.drafts(null);
    expect(invoices.invoices).toHaveBeenLastCalledWith(
      'c1',
      expect.objectContaining({ customerIds: [] }),
    );
  });

  it('finds the customers through each kind’s own options', async () => {
    chooses(null);
    await service().open(['p1']);
    expect(data().customer).toBeNull();
    const [invoice, quote, note] = data().targets;

    expect(await invoice!.customers('carth')).toEqual([
      { id: 'k1', code: 'C-7', name: 'Carthage' },
    ]);
    expect(invoices.pickCustomers).toHaveBeenCalledExactlyOnceWith('c1', { words: 'carth' });
    await quote!.customers('sous');
    expect(quotes.pickCustomers).toHaveBeenCalledExactlyOnceWith('c1', { words: 'sous' });
    await note!.customers('bizerte');
    expect(notes.pickCustomers).toHaveBeenCalledExactlyOnceWith('c1', { words: 'bizerte' });
  });
});
