// SPDX-License-Identifier: AGPL-3.0-or-later

import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { RecordDeletes } from '../shared/records/record-deletes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { effectToasts, provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { InvoiceInstruments } from './invoice-instruments';
import { InstrumentsApi } from './instruments-api';
import { InvoicesRefused } from './invoices-api';
import type { InstrumentRow } from './instruments-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const held: InstrumentRow = {
  id: 'i1',
  kind: 'check',
  amount: '500.000',
  dueOn: '2026-11-15',
  bank: 'BT',
  number: 'CHQ-77',
  status: 'held',
  settledOn: null,
  paymentId: null,
};
const deposited: InstrumentRow = { ...held, id: 'i2', kind: 'draft', status: 'deposited' };
const cashed: InstrumentRow = {
  ...held,
  id: 'i3',
  status: 'cashed',
  settledOn: '2026-10-01',
  paymentId: 'p1',
};

describe('InvoiceInstruments', () => {
  let fixture: ComponentFixture<InvoiceInstruments>;
  let rows: InstrumentRow[];
  const api = {
    list: vi.fn(async () => rows),
    receive: vi.fn(),
    advance: vi.fn(),
  };
  const deletes = { remove: vi.fn() };
  const live = { reloadOn: vi.fn() };
  const changes: unknown[] = [];

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    // The facade's reads and writes are promises of its own: let them finish before what they changed is looked at.
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  async function show(
    shown: InstrumentRow[],
    options: { mayPay?: boolean; amountDue?: string } = {},
  ): Promise<void> {
    rows = shown;
    fixture.componentRef.setInput('mayPay', options.mayPay ?? true);
    fixture.componentRef.setInput('amountDue', options.amountDue ?? '1178.100');
    await settle();
  }

  beforeEach(async () => {
    rows = [];
    changes.length = 0;
    api.list.mockClear();
    api.receive.mockReset().mockResolvedValue(held);
    api.advance.mockReset().mockResolvedValue(held);
    deletes.remove.mockReset().mockResolvedValue(true);
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [InvoiceInstruments],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: InstrumentsApi, useValue: api },
        { provide: RecordDeletes, useValue: deletes },
        { provide: LiveChanges, useValue: live },
        { provide: AuthFacade, useValue: { me: () => null } },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(InvoiceInstruments);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('invoiceId', 'f1');
    fixture.componentRef.setInput('scale', 3);
    fixture.componentRef.setInput('today', '2026-10-04');
    fixture.componentInstance.changed.subscribe(() => changes.push(true));
  });

  it("reads the invoice's instruments when it opens, and says when it has none", async () => {
    await show([]);
    expect(api.list).toHaveBeenCalledWith('c1', 'f1');
    expect(q('invoice-instruments-none')).not.toBeNull();

    api.list.mockClear();
    rows = [held];
    fixture.componentRef.setInput('invoiceId', 'f2');
    await settle();
    expect(api.list).toHaveBeenCalledWith('c1', 'f2');
    expect(q('instrument-i1')).not.toBeNull();
  });

  it('offers a held one every step, a deposited one all but the deposit and the deletion, a settled one none', async () => {
    await show([held, deposited, cashed]);

    expect(
      ['deposit', 'cash', 'unpaid', 'delete'].map((step) => q(`instrument-i1-${step}`) !== null),
    ).toEqual([true, true, true, true]);
    expect(
      ['deposit', 'cash', 'unpaid', 'delete'].map((step) => q(`instrument-i2-${step}`) !== null),
    ).toEqual([false, true, true, false]);
    expect(
      ['deposit', 'cash', 'unpaid', 'delete'].map((step) => q(`instrument-i3-${step}`) !== null),
    ).toEqual([false, false, false, false]);
  });

  it('offers no step and no way to receive to a member who may not record payments', async () => {
    await show([held], { mayPay: false });

    expect(q('instrument-i1-cash')).toBeNull();
    expect(q('instrument-receive')).toBeNull();
  });

  // Audit 2026-10-06, C-8: every step on a cheque or traite asks through the shared confirmation, which says it cannot
  // be taken back, « Remettre à l'encaissement » included; the toast after it says the same word.
  const dialog = (testId: string) => {
    const all = document.querySelectorAll(`[data-testid="${testId}"]`);
    return all[all.length - 1] as HTMLElement | undefined;
  };
  async function answer(testId: 'confirm-run' | 'confirm-keep'): Promise<void> {
    await vi.waitFor(() => expect(dialog(testId)).toBeDefined());
    dialog(testId)!.click();
    await settle();
  }

  it('asks before depositing, says it cannot be taken back, and deposits only once confirmed', async () => {
    await show([held]);

    q('instrument-i1-deposit')!.click();
    await settle();
    await vi.waitFor(() =>
      expect(dialog('confirm-kind')?.getAttribute('data-kind')).toBe('definitif'),
    );
    await answer('confirm-keep');
    expect(api.advance).not.toHaveBeenCalled();

    q('instrument-i1-deposit')!.click();
    await settle();
    await answer('confirm-run');
    await vi.waitFor(() =>
      expect(api.advance).toHaveBeenLastCalledWith('c1', 'f1', 'i1', 'deposit'),
    );
    expect(changes).toEqual([]);
    expect(effectToasts()).toEqual(['invoices.instruments.done.deposit:definitif']);
  });

  it('cashes only once confirmed, which tells the page the invoice changed', async () => {
    await show([held]);

    q('instrument-i1-cash')!.click();
    await settle();
    await answer('confirm-run');
    await vi.waitFor(() => expect(api.advance).toHaveBeenLastCalledWith('c1', 'f1', 'i1', 'cash'));
    expect(changes).toEqual([true]);
    expect(effectToasts()).toEqual(['invoices.instruments.done.cash:definitif']);
  });

  it('marks unpaid only once confirmed, and deletes a held one without asking, as a record is', async () => {
    await show([held]);

    q('instrument-i1-unpaid')!.click();
    await settle();
    await answer('confirm-keep');
    expect(api.advance).not.toHaveBeenCalled();

    // Undone from its toast or « Récemment supprimés » within the day: nothing to confirm, named by its number.
    api.list.mockClear();
    q('instrument-i1-delete')!.click();
    await settle();
    expect(document.body.querySelector('[data-testid="confirm-run"]')).toBeNull();
    expect(deletes.remove).toHaveBeenCalledWith('c1', 'payment_instrument', [
      { id: 'i1', label: 'CHQ-77 BT' },
    ]);
    expect(api.list).toHaveBeenCalledWith('c1', 'f1');
    expect(changes).toEqual([]);
  });

  it('reads its instruments again when one is deleted or put back, here or elsewhere', async () => {
    await show([held]);
    const [kinds, reload] = live.reloadOn.mock.calls[0]!;
    expect(kinds).toEqual(expect.arrayContaining(['payment_instrument', 'invoice']));
    api.list.mockClear();
    await reload();
    expect(api.list).toHaveBeenCalledWith('c1', 'f1');
  });

  it('starts a new one for what the open ones leave free, and sends what was typed', async () => {
    await show([held]);

    q('instrument-receive')!.click();
    await settle();
    const amount = q('field-amount') as HTMLInputElement;
    expect(amount.value).toBe('678,100');
    amount.value = '600';
    amount.dispatchEvent(new Event('input'));
    (q('field-number') as HTMLInputElement).value = 'CHQ-78';
    (q('field-number') as HTMLInputElement).dispatchEvent(new Event('input'));
    // An instrument typed and not received holds the page until it is.
    expect(TestBed.inject(UnsavedChanges).count()).toBe(2);
    q('instrument-save')!.click();
    await settle();
    expect(TestBed.inject(UnsavedChanges).count()).toBe(0);

    expect(api.receive).toHaveBeenCalledWith('c1', 'f1', {
      kind: 'check',
      amount: '600',
      dueOn: '2026-10-04',
      bank: null,
      number: 'CHQ-78',
    });
    expect(successToasts()).toContain('invoices.instruments.done.received');
    expect(q('instrument-save')).toBeNull();
  });

  // Audit 2026-10-06, E-8: what is left free is counted exactly, at the currency's decimals, never through a float.
  it('counts what is left free exactly and at the currency’s decimals', async () => {
    fixture.componentRef.setInput('scale', 2);
    await show(
      [
        { ...held, id: 'a', amount: '0.10' },
        { ...held, id: 'b', amount: '0.20' },
      ],
      { amountDue: '0.31' },
    );

    q('instrument-receive')!.click();
    await settle();
    expect((q('field-amount') as HTMLInputElement).value).toBe('0,01');
  });

  it('offers no new one once the open ones cover everything due', async () => {
    await show([{ ...held, amount: '1178.100' }]);

    expect(q('instrument-receive')).toBeNull();
  });

  it('keeps the form open and says why when the API refuses', async () => {
    await show([], { amountDue: '100.000' });
    q('instrument-receive')!.click();
    await settle();
    api.receive.mockRejectedValue(new InvoicesRefused('invalid'));
    q('instrument-save')!.click();
    await settle();

    expect(q('instrument-save')).not.toBeNull();
    expect(q('instrument-error')).not.toBeNull();
  });
});
