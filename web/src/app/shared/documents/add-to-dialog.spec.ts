// SPDX-License-Identifier: AGPL-3.0-or-later

import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { By } from '@angular/platform-browser';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PickField } from '../form/pick-field';
import { FormatFacade } from '../i18n/format-facade';
import { AddToDialog, type AddToData, type AddToDraft, type AddToTarget } from './add-to-dialog';

const carthage: AddToDraft = {
  id: 'i1',
  customerName: 'Carthage',
  customerReference: 'BC-12',
  total: '119.000',
  lineCount: 2,
};

describe('AddToDialog', () => {
  const close = vi.fn();
  let fixture: ComponentFixture<AddToDialog>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  function target(kind: AddToTarget['kind'], drafts: readonly AddToDraft[]): AddToTarget {
    return {
      kind,
      drafts: vi.fn(async () => drafts),
      customers: vi.fn(async () => [{ id: 'k2', code: 'C-9', name: 'Sousse' }]),
    };
  }

  async function open(data: AddToData): Promise<void> {
    TestBed.configureTestingModule({
      imports: [AddToDialog],
      providers: [
        provideTranslateService(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MAT_DIALOG_DATA, useValue: data },
        { provide: MatDialogRef, useValue: { close } },
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
      ],
    });
    const texts = TestBed.inject(TranslateService);
    texts.setTranslation('fr', { documents: { add_to: { draft: '{{customer}} · {{total}}' } } });
    texts.use('fr');
    fixture = TestBed.createComponent(AddToDialog);
    await settled();
  }

  /** The drafts are read once the dialog opens, and again on each kind or customer: wait until a reading is shown. */
  async function settled(): Promise<void> {
    fixture.detectChanges();
    await vi.waitFor(() => {
      fixture.detectChanges();
      expect(q('add-to-none') ?? q('add-to-unreadable') ?? q('add-to-list')).not.toBeNull();
    });
  }

  beforeEach(() => close.mockReset());

  it('offers a new draft or an open one of the only kind there is, and closes on the one chosen', async () => {
    const invoices = target('invoice', [carthage]);
    await open({ targets: [invoices], count: 2, scale: 3, customer: null });

    expect(invoices.drafts).toHaveBeenCalledExactlyOnceWith(null);
    // One kind: nothing to choose between.
    expect(q('add-to-kind-invoice')).toBeNull();
    expect(q('add-to-draft-i1')?.textContent).toContain('Carthage');
    expect(q('add-to-draft-i1')?.textContent).toContain('BC-12');
    expect(q('add-to-draft-i1')?.textContent).toContain('~119.000');

    q('add-to-draft-i1')!.click();
    expect(close).toHaveBeenCalledExactlyOnceWith({ kind: 'invoice', draftId: 'i1' });

    q('add-to-new')!.click();
    expect(close).toHaveBeenLastCalledWith({ kind: 'invoice', draftId: null });
  });

  it('reads the drafts of the kind chosen, and of the customer chosen', async () => {
    const invoices = target('invoice', [carthage]);
    const quotes = target('quote', [{ ...carthage, id: 'q1', customerName: 'Sousse' }]);
    await open({ targets: [invoices, quotes], count: 1, scale: 3, customer: null });

    q('add-to-kind-quote')!.querySelector('input')!.click();
    await settled();
    expect(quotes.drafts).toHaveBeenCalledWith(null);
    expect(q('add-to-draft-q1')?.textContent).toContain('Sousse');
    expect(q('add-to-draft-i1')).toBeNull();

    // The customer is found through the kind shown, and only that customer's drafts are then read.
    const pick = fixture.debugElement.query(By.directive(PickField)).componentInstance as PickField;
    expect(await pick.search()('sous')).toEqual([{ id: 'k2', code: 'C-9', name: 'Sousse' }]);
    expect(quotes.customers).toHaveBeenLastCalledWith('sous');
    pick.picked.emit({ id: 'k2', code: 'C-9', name: 'Sousse' });
    await settled();
    expect(quotes.drafts).toHaveBeenLastCalledWith('k2');
    expect(invoices.customers).not.toHaveBeenCalledWith('sous');

    // Clearing the customer offers every customer's drafts again.
    pick.picked.emit(null);
    await settled();
    expect(quotes.drafts).toHaveBeenLastCalledWith(null);

    q('add-to-new')!.click();
    expect(close).toHaveBeenCalledExactlyOnceWith({ kind: 'quote', draftId: null });
  });

  it('shows the drafts of the last customer chosen, whichever answer comes back first', async () => {
    let slow: (drafts: readonly AddToDraft[]) => void = () => undefined;
    const invoices: AddToTarget = {
      ...target('invoice', []),
      drafts: vi.fn((customerId: string | null) =>
        customerId === null
          ? new Promise<readonly AddToDraft[]>((resolve) => (slow = resolve))
          : Promise.resolve([{ ...carthage, id: 'i2', customerName: 'Sousse' }]),
      ),
    };
    TestBed.configureTestingModule({
      imports: [AddToDialog],
      providers: [
        provideTranslateService(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        {
          provide: MAT_DIALOG_DATA,
          useValue: { targets: [invoices], count: 1, scale: 3, customer: null },
        },
        { provide: MatDialogRef, useValue: { close } },
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
      ],
    });
    fixture = TestBed.createComponent(AddToDialog);
    fixture.detectChanges();
    const pick = fixture.debugElement.query(By.directive(PickField)).componentInstance as PickField;
    pick.picked.emit({ id: 'k2', code: 'C-9', name: 'Sousse' });
    await settled();
    expect(q('add-to-draft-i2')).not.toBeNull();

    // Every customer's drafts were asked first and answer last: what they say is no longer what was asked.
    expect(invoices.drafts).toHaveBeenCalledTimes(2);
    slow([carthage]);
    await new Promise((resolve) => setTimeout(resolve, 0));
    fixture.detectChanges();
    expect(q('add-to-draft-i1')).toBeNull();
    expect(q('add-to-draft-i2')).not.toBeNull();
  });

  it('offers the drafts of the customer the screen knows, with no customer to pick', async () => {
    const notes = target('delivery_note', [carthage]);
    await open({
      targets: [notes],
      count: 1,
      scale: 3,
      customer: { id: 'k1', code: 'C-7', name: 'Carthage' },
    });
    expect(notes.drafts).toHaveBeenCalledExactlyOnceWith('k1');
    expect(q('add-to-customer')).toBeNull();
  });

  it('says when there is no open draft, and when the drafts could not be read', async () => {
    await open({ targets: [target('delivery_note', [])], count: 1, scale: 3, customer: null });
    expect(q('add-to-none')).not.toBeNull();
    expect(q('add-to-unreadable')).toBeNull();

    TestBed.resetTestingModule();
    const failing: AddToTarget = {
      ...target('invoice', []),
      drafts: vi.fn(async () => Promise.reject(new Error('down'))),
    };
    await open({ targets: [failing], count: 1, scale: 3, customer: null });
    expect(q('add-to-unreadable')).not.toBeNull();
    expect(q('add-to-none')).toBeNull();
    // A new draft needs nothing read.
    q('add-to-new')!.click();
    expect(close).toHaveBeenLastCalledWith({ kind: 'invoice', draftId: null });
  });

  it('closes on nothing chosen', async () => {
    await open({ targets: [target('invoice', [])], count: 1, scale: 3, customer: null });
    q('add-to-close')!.click();
    expect(close).toHaveBeenCalledExactlyOnceWith(null);
  });
});
