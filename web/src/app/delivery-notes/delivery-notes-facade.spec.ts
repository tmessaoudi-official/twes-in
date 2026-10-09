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
  units: [],
  taxes: [],
};
const draft: DeliveryNoteRow = {
  id: 'n1',
  number: null,
  status: 'draft',
  customerId: 'k1',
  establishmentId: 'e1',
  recordedCustomerName: null,
  customerName: 'Carthage',
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
    draftsOf: vi.fn(),
    statusCounts: vi.fn(),
    credit: vi.fn(),
    left: vi.fn(),
    preview: vi.fn(),
  };
  let facade: DeliveryNotesFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.options.mockResolvedValue(options);
    api.notes.mockResolvedValue({ rows: [draft], total: 1 });
    api.note.mockResolvedValue(draft);
    TestBed.configureTestingModule({ providers: [{ provide: DeliveryNotesApi, useValue: api }] });
    facade = TestBed.inject(DeliveryNotesFacade);
  });

  it('reads one page of notes with the options that name its customers, and how many there are in all', async () => {
    const search = {
      page: 1,
      itemsPerPage: 25,
      q: '',
      status: [],
      customerIds: [],
      intervals: {},
      order: null,
    } as const;

    await facade.loadListContext('c1');
    await facade.loadPage('c1', search);

    expect(api.notes).toHaveBeenCalledWith('c1', search);
    expect(facade.notes()).toEqual([draft]);
    expect(facade.total()).toBe(1);
    expect(facade.options()).toEqual(options);
    expect(facade.error()).toBeNull();
  });

  it('shows the chips’ counts of the latest search, whatever order the answers come back in', async () => {
    const search = {
      page: 1,
      itemsPerPage: 25,
      q: '',
      status: [],
      customerIds: [],
      intervals: {},
      order: null,
    } as const;
    const counts = (all: number) => ({
      all,
      statuses: { draft: all, validated: 0, delivered: 0, cancelled: 0, invoiced: 0 },
    });
    let answerStale: (value: ReturnType<typeof counts>) => void = () => undefined;
    api.statusCounts
      .mockReturnValueOnce(new Promise((resolve) => (answerStale = resolve)))
      .mockResolvedValueOnce(counts(4));

    const stale = facade.loadStatusCounts('c1', search);
    const latest = facade.loadStatusCounts('c1', { ...search, q: 'carthage' });
    await latest;
    answerStale(counts(40));
    await stale;

    expect(facade.statusCounts()?.all).toBe(4);
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
    expect(api.invoice).toHaveBeenCalledWith('c1', ['n1'], undefined, undefined);
    await facade.invoice('c1', 'n1', { l1: '2' });
    expect(api.invoice).toHaveBeenLastCalledWith('c1', ['n1'], { l1: '2' }, undefined);
    await facade.invoice('c1', 'n1', undefined, 'd1');
    expect(api.invoice).toHaveBeenLastCalledWith('c1', ['n1'], undefined, 'd1');

    api.invoice.mockRejectedValue(new DeliveryNotesRefused('conflict'));
    expect(await facade.invoice('c1', 'n1')).toBeNull();
    expect(facade.error()).toBe('conflict');
  });

  it('answers the drafts a note could be added to, and null with the reason when they could not be read', async () => {
    const drafts = [{ id: 'd1', total: '10.000', lineCount: 1, customerReference: null }];
    api.draftsOf.mockResolvedValue(drafts);
    expect(await facade.draftsOf('c1', 'k1', 'e1')).toEqual(drafts);
    expect(api.draftsOf).toHaveBeenCalledWith('c1', 'k1', 'e1');

    api.draftsOf.mockRejectedValue(new DeliveryNotesRefused('network'));
    expect(await facade.draftsOf('c1', 'k1', 'e1')).toBeNull();
    expect(facade.error()).toBe('network');
  });

  it('answers the credit position of a note, and null when it could not be read, without raising the screen’s error', async () => {
    const credit = {
      limit: '10.000',
      owed: '0.000',
      noteTotal: '5.000',
      afterDelivery: '5.000',
      over: false,
    };
    api.credit.mockResolvedValue(credit);
    expect(await facade.credit('c1', 'n1')).toEqual(credit);

    api.credit.mockRejectedValue(new DeliveryNotesRefused('network'));
    expect(await facade.credit('c1', 'n1')).toBeNull();
    expect(facade.error()).toBeNull();
  });

  it('answers what is typed would come to, and null without raising the screen’s error when the API refuses it', async () => {
    api.preview.mockResolvedValue({ total: '1.000' });
    expect(await facade.preview('c1', null, input)).toEqual({ total: '1.000' });

    // A draft being typed is often not one yet: its refusal is no error to report.
    api.preview.mockRejectedValue(new DeliveryNotesRefused('invalid'));
    expect(await facade.preview('c1', 'n1', input)).toBeNull();
    expect(facade.error()).toBeNull();
  });

  it('answers what is left of a note, or null with the reason in `error`', async () => {
    const left = { lines: [{ lineId: 'l1', quantity: '2.000', invoiced: '0.000', left: '2.000' }] };
    api.left.mockResolvedValue(left);
    expect(await facade.left('c1', 'n1')).toEqual(left);

    api.left.mockRejectedValue(new DeliveryNotesRefused('conflict'));
    expect(await facade.left('c1', 'n1')).toBeNull();
    expect(facade.error()).toBe('conflict');
  });
});
