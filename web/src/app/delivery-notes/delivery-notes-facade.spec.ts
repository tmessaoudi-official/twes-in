// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { DeliveryNotesApi, DeliveryNotesRefused } from './delivery-notes-api';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import type {
  DeliveryNoteInput,
  DeliveryNoteOptions,
  DeliveryNoteRow,
} from './delivery-notes-types';

const options: DeliveryNoteOptions = {
  currency: 'TND',
  currencyScale: 3,
  establishments: [],
  customers: [],
  products: [],
  units: [],
  taxes: [],
};
const draft: DeliveryNoteRow = {
  id: 'n1',
  number: null,
  status: 'draft',
  customerId: 'k1',
  establishmentId: 'e1',
  customerName: null,
  issueDate: null,
  deliveryDate: null,
  deliveryAddress: { line1: null, line2: null, postalCode: null, city: null, countryCode: null },
  customerReference: null,
  remarksPrinted: null,
  notesInternal: null,
  lines: [],
  subtotalNet: '0.000',
  taxes: [],
  totalTax: '0.000',
  total: '0.000',
};
const input: DeliveryNoteInput = {
  customerId: 'k1',
  establishmentId: 'e1',
  deliveryDate: null,
  deliveryAddress: draft.deliveryAddress,
  customerReference: null,
  remarksPrinted: null,
  notesInternal: null,
  lines: [],
};

describe('DeliveryNotesFacade', () => {
  const api = {
    options: vi.fn(),
    notes: vi.fn(),
    note: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    validate: vi.fn(),
    deliver: vi.fn(),
    cancel: vi.fn(),
    invoice: vi.fn(),
  };
  let facade: DeliveryNotesFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.options.mockResolvedValue(options);
    api.notes.mockResolvedValue([draft]);
    api.note.mockResolvedValue(draft);
    TestBed.configureTestingModule({ providers: [{ provide: DeliveryNotesApi, useValue: api }] });
    facade = TestBed.inject(DeliveryNotesFacade);
  });

  it('reads the list with the options that name its customers', async () => {
    await facade.loadList('c1');

    expect(facade.notes()).toEqual([draft]);
    expect(facade.options()).toEqual(options);
    expect(facade.error()).toBeNull();
  });

  it('reads the options alone for a new note, and the note too for an existing one', async () => {
    await facade.loadNote('c1', null);
    expect(api.note).not.toHaveBeenCalled();
    expect(facade.note()).toBeNull();

    await facade.loadNote('c1', 'n1');
    expect(api.note).toHaveBeenCalledWith('c1', 'n1');
    expect(facade.note()).toEqual(draft);
  });

  it('keeps the note each step answers with', async () => {
    api.create.mockResolvedValue(draft);
    expect(await facade.create('c1', input)).toEqual(draft);
    expect(facade.note()).toEqual(draft);

    const numbered = { ...draft, status: 'validated' as const, number: 'BL-2026-00001' };
    api.revise.mockResolvedValue(draft);
    api.validate.mockResolvedValue(numbered);
    expect(await facade.reviseAndValidate('c1', 'n1', input)).toEqual(numbered);
    expect(api.revise).toHaveBeenCalledWith('c1', 'n1', input);
    expect(facade.note()).toEqual(numbered);

    api.deliver.mockResolvedValue({ ...numbered, status: 'delivered' });
    expect((await facade.deliver('c1', 'n1', '2026-09-20'))?.status).toBe('delivered');
    expect(api.deliver).toHaveBeenCalledWith('c1', 'n1', '2026-09-20');

    api.cancel.mockResolvedValue({ ...numbered, status: 'cancelled' });
    expect((await facade.cancel('c1', 'n1'))?.status).toBe('cancelled');
  });

  it('does not validate a draft whose changes were refused, and says why', async () => {
    api.revise.mockRejectedValue(new DeliveryNotesRefused('invalid'));

    expect(await facade.reviseAndValidate('c1', 'n1', input)).toBeNull();
    expect(api.validate).not.toHaveBeenCalled();
    expect(facade.error()).toBe('invalid');
    expect(facade.busy()).toBe(false);

    api.validate.mockRejectedValue(new DeliveryNotesRefused('conflict'));
    api.revise.mockResolvedValue(draft);
    expect(await facade.reviseAndValidate('c1', 'n1', input)).toBeNull();
    expect(facade.error()).toBe('conflict');
    expect(facade.note()).toEqual(draft);
  });
  it('answers the id of the invoice drafted from a note, or null with the reason', async () => {
    api.invoice.mockResolvedValue('i7');
    expect(await facade.invoice('c1', 'n1')).toBe('i7');
    expect(api.invoice).toHaveBeenCalledWith('c1', ['n1']);

    api.invoice.mockRejectedValue(new DeliveryNotesRefused('conflict'));
    expect(await facade.invoice('c1', 'n1')).toBeNull();
    expect(facade.error()).toBe('conflict');
  });
});
