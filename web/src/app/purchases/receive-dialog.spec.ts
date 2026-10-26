// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import type { PurchaseOrderLine, Receiving } from './purchases-types';
import { ReceiveDialog, type ReceiveDialogData } from './receive-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: { stock: { fields: { serial: 'N° de série' } } },
      purchases: { receive: { refused: 'Refusé : {{ field }}' } },
      import: {
        rejections: {
          above_expected: 'Il n’en reste que {{quantity}} à recevoir.',
          lot_required: 'Indiquez le lot de « {{reference}} ».',
          unknown_choice: 'Choisissez dans la liste proposée.',
        },
      },
      receivers: { received_by: 'Reçu par', required: 'Nommez qui a reçu.' },
    });
  }
}

function line(id: string, patch: Partial<PurchaseOrderLine> = {}): PurchaseOrderLine {
  return {
    id,
    productId: `p-${id}`,
    productReference: `REF-${id}`,
    productTracking: 'none',
    description: `Article ${id}`,
    quantity: '10.000',
    unitId: 'u1',
    unitCode: 'C62',
    unitName: 'pièce',
    unitPriceNet: '2.5000',
    net: '25.000',
    vendorCode: null,
    received: '0.000',
    outstanding: '10.000',
    ...patch,
  };
}

const receiving: Receiving = {
  places: [{ id: 'site', parentId: null, code: 'MAG', name: 'Magasin', isDefault: true }],
  homes: {},
  asksCostBasis: true,
};

describe('ReceiveDialog', () => {
  const ref = { close: vi.fn() };
  const receive = vi.fn();
  let fixture: ComponentFixture<ReceiveDialog>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const field = q(testId) as HTMLInputElement | HTMLTextAreaElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));
  }

  const confirm = (): HTMLButtonElement => q('receive-confirm') as HTMLButtonElement;

  async function open(patch: Partial<ReceiveDialogData> = {}): Promise<void> {
    const data: ReceiveDialogData = {
      lines: [line('a'), line('s', { productTracking: 'serial', outstanding: '2.000' })],
      receiving,
      seesCost: true,
      timeZone: 'Africa/Tunis',
      issueDate: '2026-01-05',
      receive,
      ...patch,
    };
    TestBed.configureTestingModule({
      imports: [ReceiveDialog],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: MAT_DIALOG_DATA, useValue: data },
        { provide: MatDialogRef, useValue: ref },
        {
          provide: Session,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', countryCode: 'TN' } }),
          },
        },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(ReceiveDialog);
    await settle();
  }

  beforeEach(() => {
    ref.close.mockReset();
    receive.mockReset().mockResolvedValue(null);
  });

  it('records everything still expected, a piece per serial number, then closes', async () => {
    await open();
    type('receive-row-1-serials', 'SN1\nSN2');
    type('receive-reference', 'BL 78');
    await settle();
    confirm().click();
    await settle();

    expect(receive).toHaveBeenCalledWith(
      expect.objectContaining({
        supplierReference: 'BL 78',
        apply: null,
        lines: [
          expect.objectContaining({ orderLineId: 'a', quantity: '10', unitCost: '2.5' }),
          expect.objectContaining({ orderLineId: 's', quantity: '1', lotCode: 'SN1' }),
          expect.objectContaining({ orderLineId: 's', quantity: '1', lotCode: 'SN2' }),
        ],
      }),
    );
    expect(ref.close).toHaveBeenCalledWith(true);
  });

  it('records nothing more than is still expected', async () => {
    await open();
    type('receive-row-0-quantity', '11');
    await settle();

    expect(q('receive-row-0-problem')).not.toBeNull();
    expect(confirm().disabled).toBe(true);
  });

  it('refuses a day before the order was sent', async () => {
    await open({ issueDate: '2026-01-05' });
    type('receive-day', '04/01/2026');
    await settle();

    expect(q('receive-day-error')?.textContent).toContain('purchases.receive.day_problems.before');
    expect(confirm().disabled).toBe(true);
  });

  it('stays open on a refusal with no words and names the serial number on its row', async () => {
    receive.mockResolvedValue({ code: 'invalid', field: 'lines[2].lotCode', refusal: null });
    await open();
    type('receive-row-1-serials', 'SN1\nSN2');
    await settle();
    confirm().click();
    await settle();

    expect(ref.close).not.toHaveBeenCalled();
    expect(q('receive-row-1-refused')?.textContent?.trim()).toBe('Refusé : N° de série');
    expect((q('receive-row-1-serials') as HTMLTextAreaElement).value).toBe('SN1\nSN2');

    type('receive-row-1-serials', 'SN1\nSN3');
    await settle();
    expect(q('receive-row-1-refused')).toBeNull();
  });

  it('says a refusal in its reason’s words on its row, and the receipt’s own below them', async () => {
    receive.mockResolvedValueOnce({
      code: 'invalid',
      field: 'lines[2].lotCode',
      refusal: {
        field: 'lines[2].lotCode',
        reason: 'lot_required',
        params: { reference: 'VIS-8' },
      },
    });
    await open();
    type('receive-row-1-serials', 'SN1\nSN2');
    await settle();
    confirm().click();
    await settle();

    expect(ref.close).not.toHaveBeenCalled();
    expect(q('receive-row-1-refused')?.textContent?.trim()).toBe('Indiquez le lot de « VIS-8 ».');

    receive.mockResolvedValueOnce({
      code: 'invalid',
      field: 'receivedOn',
      refusal: { field: 'receivedOn', reason: 'unknown_reason', params: {} },
    });
    confirm().click();
    await settle();
    expect(q('receive-refused')?.textContent?.trim()).toContain('Refusé');
  });

  it('asks no cost, nor a cost basis, of whoever may not read costs', async () => {
    await open({ seesCost: false });

    expect(q('receive-row-0-cost')).toBeNull();
    expect(q('receive-apply')).toBeNull();
    confirm().click();
    await settle();
    expect(receive).toHaveBeenCalledWith(
      expect.objectContaining({
        apply: null,
        lines: [expect.objectContaining({ unitCost: null })],
      }),
    );
  });

  it('asks the cost basis only where the company asks it', async () => {
    await open({ receiving: { ...receiving, asksCostBasis: false } });
    expect(q('receive-apply')).toBeNull();
    expect(q('receive-row-0-cost')).not.toBeNull();
  });

  describe('« Reçu par »', () => {
    const members = [
      { contactId: 'me', name: 'Sami Ben Ali' },
      { contactId: 'm2', name: 'Amel Gharbi' },
    ];

    it('is not asked where the company’s receipts name nobody, and sends nobody', async () => {
      await open();

      expect(q('receive-receiver-name')).toBeNull();
      confirm().click();
      await settle();
      expect(receive).toHaveBeenCalledWith(expect.objectContaining({ receivedBy: null }));
    });

    it('opens empty, never on the person recording, and sends a name typed as typed', async () => {
      await open({ receiver: { required: false, members: Promise.resolve(members) } });

      const name = q('receive-receiver-name') as HTMLInputElement;
      expect(name.value).toBe('');
      confirm().click();
      await settle();
      expect(receive).toHaveBeenLastCalledWith(expect.objectContaining({ receivedBy: null }));

      type('receive-receiver-name', '  Le chauffeur ');
      await settle();
      confirm().click();
      await settle();
      expect(receive).toHaveBeenLastCalledWith(
        expect.objectContaining({
          receivedBy: { contactId: null, name: 'Le chauffeur', addToContacts: false },
        }),
      );
    });

    it('says under the field that someone must be named where it is required, and sends nothing', async () => {
      await open({ receiver: { required: true, members: Promise.resolve(members) } });

      confirm().click();
      await settle();

      expect(receive).not.toHaveBeenCalled();
      expect(q('receive-receiver-error')?.textContent?.trim()).toBe('Nommez qui a reçu.');
    });

    it('says the API’s refusal of who received under the field, in its reason’s words, and stays open', async () => {
      receive.mockResolvedValueOnce({
        code: 'invalid',
        field: 'receivedBy',
        refusal: { field: 'receivedBy', reason: 'unknown_choice', params: {} },
      });
      await open({ receiver: { required: false, members: Promise.resolve(members) } });
      type('receive-receiver-name', 'Ines');
      await settle();

      confirm().click();
      await settle();

      expect(ref.close).not.toHaveBeenCalled();
      expect(q('receive-receiver-error')?.textContent?.trim()).toBe(
        'Choisissez dans la liste proposée.',
      );
      expect(q('receive-refused')).toBeNull();
    });
  });
});
