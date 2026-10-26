// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { RecordDeletes } from '../shared/records/record-deletes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import {
  noticeToasts,
  provideQuietFeedback,
  type RecordedFeedback,
  successToasts,
} from '../shared/testing/feedback';
import { PurchaseOrderPage } from './purchase-order-page';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { PurchasesFacade } from './purchases-facade';
import type {
  ProductOption,
  PurchaseOrderRow,
  PurchaseOrderStatus,
  PurchasesError,
  Receiving,
  VendorOption,
} from './purchases-types';
import type { ReceiveDialogData } from './receive-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      import: {
        rejections: {
          quantity_above_zero: 'Attendu : une quantité au-dessus de zéro.',
          vendor_deactivated: 'Le fournisseur « {{number}} » est désactivé.',
        },
      },
      purchases: {
        lines: { refused: 'Refusé par le serveur : {{ field }}', quantity: 'Quantité' },
      },
    });
  }
}

const sotumag: VendorOption = { id: 'v1', number: 'FRN-0001', name: 'Sotumag', active: true };
const screws: ProductOption = {
  id: 'p1',
  reference: 'VIS-6',
  name: 'Vis 6 mm',
  unitId: 'u1',
  unitName: 'pièce',
  tracking: 'none',
  goods: true,
  active: true,
  costPrice: '0.1200',
};

function orderIn(status: PurchaseOrderStatus): PurchaseOrderRow {
  return {
    id: 'o1',
    version: 4,
    number: status === 'draft' ? null : 'BC-2026-0007',
    status,
    vendorId: 'v1',
    vendorName: 'Sotumag',
    recordedVendorNumber: status === 'draft' ? null : 'FRN-0001',
    establishmentId: null,
    issueDate: status === 'draft' ? null : '2026-10-10',
    expectedOn: null,
    remarksPrinted: null,
    notesInternal: null,
    lines: [
      {
        id: 'l1',
        productId: 'p1',
        productReference: 'VIS-6',
        productTracking: 'none',
        description: 'Vis 6 mm',
        quantity: '100.000',
        unitId: 'u1',
        unitCode: 'C62',
        unitName: 'pièce',
        unitPriceNet: '0.1200',
        net: '12.000',
        vendorCode: 'SUD-VIS6',
        received: status === 'partly_received' ? '40.000' : '0.000',
        outstanding: status === 'partly_received' ? '60.000' : '100.000',
      },
    ],
    totalNet: '12.000',
    currencyScale: 3,
    receipts:
      status === 'partly_received'
        ? [
            {
              id: 'r1',
              receivedOn: '2026-10-12',
              supplierReference: 'BL 77',
              lines: [
                {
                  orderLineId: 'l1',
                  locationId: 's1',
                  quantity: '40.000',
                  lotCode: null,
                  lotExpiresOn: null,
                  unitCost: null,
                },
              ],
            },
          ]
        : [],
  };
}

const receiving: Receiving = {
  places: [{ id: 's1', parentId: null, code: 'MAG', name: 'Magasin', isDefault: true }],
  homes: {},
  asksCostBasis: false,
};

describe('PurchaseOrderPage', () => {
  const order = signal<PurchaseOrderRow | null>(null);
  const error = signal<PurchasesError | null>(null);
  const refused = signal<string | null>(null);
  const refusals = signal<readonly FieldRefusal[]>([]);
  const facade = {
    order: order.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    refused: refused.asReadonly(),
    refusals: refusals.asReadonly(),
    loadOrder: vi.fn(),
    pickVendors: vi.fn(),
    pickProducts: vi.fn(),
    create: vi.fn(),
    revise: vi.fn(),
    reviseAndSend: vi.fn(),
    receiving: vi.fn(),
    receive: vi.fn(),
    close: vi.fn(),
    cancel: vi.fn(),
    clearError: vi.fn(),
    pdfUrl: (companyId: string, id: string) =>
      `/api/companies/${companyId}/purchase-orders/${id}/pdf`,
  };
  const deletes = { remove: vi.fn() };
  const granted = new Set<string>();
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', currency: 'TND', timezone: 'Africa/Tunis' },
      plannedModules: [{ key: 'mailing', planned: 'v1' }],
    }),
    hasPermission: (permission: string) => granted.has(permission),
    hasModule: () => true,
  };
  let fixture: ComponentFixture<PurchaseOrderPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const over = (testId: string): HTMLElement | null =>
    document.body.querySelector(`.cdk-overlay-container [data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function pick(testId: string, label: string): Promise<void> {
    (q(testId) as HTMLInputElement).dispatchEvent(new Event('focusin'));
    await settle();
    const option = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.trim() === label,
    );
    expect(option, label).toBeDefined();
    option!.click();
    await settle();
  }

  async function open(id: string | undefined, vendorId?: string): Promise<void> {
    fixture = TestBed.createComponent(PurchaseOrderPage);
    if (id !== undefined) fixture.componentRef.setInput('purchaseOrderId', id);
    if (vendorId !== undefined) fixture.componentRef.setInput('vendorId', vendorId);
    await settle();
    await settle();
  }

  /** The actions the bar offers, drawn or folded into « ⋮ ». */
  async function offered(): Promise<string[]> {
    const drawn = [
      ...fixture.nativeElement.querySelectorAll('[data-testid^="document-action-"]'),
    ].map((each) => (each as HTMLElement).dataset['testid']!.replace('document-action-', ''));
    const more = q('document-more');
    if (more === null) return drawn.sort();
    more.click();
    await settle();
    const folded = [
      ...document.body.querySelectorAll('.cdk-overlay-container [data-testid^="document-menu-"]'),
    ]
      .map((each) => (each as HTMLElement).dataset['testid']!.replace('document-menu-', ''))
      .filter((id) => id !== 'final');
    // The menu closes as a person would leave it, so what is clicked next is the page, not the menu.
    document.body.querySelector<HTMLElement>('.cdk-overlay-backdrop')?.click();
    await settle();
    return [...drawn, ...folded].sort();
  }

  beforeEach(() => {
    order.set(null);
    error.set(null);
    refused.set(null);
    refusals.set([]);
    granted.clear();
    for (const each of ['purchase.write', 'purchase.receive', 'product.cost.read']) {
      granted.add(each);
    }
    facade.loadOrder.mockReset().mockResolvedValue(undefined);
    facade.pickVendors.mockReset().mockResolvedValue([sotumag]);
    facade.pickProducts.mockReset().mockResolvedValue([screws]);
    facade.create.mockReset().mockResolvedValue({ ...orderIn('draft'), id: 'o9' });
    facade.revise.mockReset().mockResolvedValue(orderIn('draft'));
    facade.reviseAndSend.mockReset().mockResolvedValue(orderIn('sent'));
    facade.receiving.mockReset().mockResolvedValue(receiving);
    facade.receive.mockReset().mockResolvedValue(orderIn('received'));
    facade.close.mockReset().mockResolvedValue(orderIn('closed'));
    facade.cancel.mockReset().mockResolvedValue(orderIn('cancelled'));
    facade.clearError.mockReset();
    deletes.remove.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [PurchaseOrderPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: PurchasesFacade, useValue: facade },
        { provide: RecordDeletes, useValue: deletes },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('starts an order for the vendor it was opened from, named in the header', async () => {
    await open(undefined, 'v1');

    expect(facade.loadOrder).toHaveBeenCalledWith('c1', null);
    expect(facade.pickVendors).toHaveBeenCalledWith('c1', { ids: ['v1'] });
    expect((q('field-vendorId') as HTMLInputElement).value).toContain('Sotumag');
    expect(await offered()).toEqual(['save']);
  });

  it('fills a chosen product’s name, cost and unit in, then creates the order and opens it', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined, 'v1');

    await pick('purchase-line-0-product', 'VIS-6 · Vis 6 mm');
    expect((q('purchase-line-0-description') as HTMLInputElement).value).toBe('Vis 6 mm');
    expect((q('purchase-line-0-price') as HTMLInputElement).value).toBe('0,12');
    expect(q('purchase-line-0-unit')?.textContent?.trim()).toBe('pièce');
    type('purchase-line-0-quantity', '100');
    await settle();
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith('c1', {
      vendorId: 'v1',
      establishmentId: null,
      expectedOn: null,
      remarksPrinted: null,
      notesInternal: null,
      lines: [{ productId: 'p1', description: 'Vis 6 mm', quantity: '100', unitPriceNet: '0.12' }],
    });
    await vi.waitFor(() =>
      expect(navigate).toHaveBeenCalledWith(['/purchases', 'o9'], { replaceUrl: true }),
    );
    expect(successToasts()).toContain('purchases.saved');
  });

  it('neither shows nor sends a price to whoever may not read costs', async () => {
    granted.delete('product.cost.read');
    vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
    await open(undefined, 'v1');

    await pick('purchase-line-0-product', 'VIS-6 · Vis 6 mm');
    expect(q('purchase-line-0-price')).toBeNull();
    type('purchase-line-0-quantity', '5');
    await settle();
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).toHaveBeenCalledWith(
      'c1',
      expect.objectContaining({ lines: [expect.objectContaining({ unitPriceNet: null })] }),
    );
  });

  it('saves nothing while a line has no product, and says so on the line', async () => {
    await open(undefined, 'v1');
    type('purchase-line-0-quantity', '5');
    await settle();
    q('document-action-save')!.click();
    await settle();

    expect(facade.create).not.toHaveBeenCalled();
    expect(q('purchase-line-0-product-error')?.textContent).toContain(
      'purchases.lines.errors.productId',
    );
  });

  it('sends a draft once confirmed, saving what is shown at the version it was opened at', async () => {
    order.set(orderIn('draft'));
    await open('o1');
    expect(await offered()).toEqual(['cancel', 'delete', 'pdf', 'save', 'send']);

    q('document-action-send')!.click();
    await settle();
    over('confirm-run')!.click();
    await settle();

    expect(facade.reviseAndSend).toHaveBeenCalledWith(
      'c1',
      'o1',
      expect.objectContaining({ lines: [expect.objectContaining({ quantity: '100' })] }),
      4,
      expect.any(Function),
    );
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said.at(-1);
    expect(said).toMatchObject({
      key: 'purchases.sent',
      params: { number: 'BC-2026-0007' },
      effect: 'corrigeable',
      action: { key: 'purchases.suggest.receive' },
    });
  });

  it('reads a sent order, offers to receive it or withdraw it, and nothing to type', async () => {
    order.set(orderIn('sent'));
    await open('o1');

    expect(q('purchase-order-view')).not.toBeNull();
    expect(q('purchase-order-form')).toBeNull();
    expect(q('purchase-order-lines-read')).not.toBeNull();
    expect(q('purchase-order-line-0-outstanding')?.textContent?.trim()).toBe('100');
    expect(await offered()).toEqual(['cancel', 'pdf', 'receive']);
  });

  it('writes a price at the currency’s scale, as the printed order does, finer only when the price is', async () => {
    const sent = orderIn('sent');
    sent.lines = [
      sent.lines[0]!,
      { ...sent.lines[0]!, id: 'l2', unitPriceNet: '2.5625', net: '256.250' },
    ];
    order.set(sent);
    await open('o1');

    expect(q('purchase-order-line-0-price')?.textContent?.trim()).toMatch(/^0[,.]120$/);
    expect(q('purchase-order-line-1-price')?.textContent?.trim()).toMatch(/^2[,.]5625$/);
  });

  it('offers to receive the rest or close a part-received order, and lists what came', async () => {
    order.set(orderIn('partly_received'));
    await open('o1');

    expect(await offered()).toEqual(['close', 'pdf', 'receive']);
    expect(q('purchase-order-line-0-received')?.textContent?.trim()).toBe('40');
    expect(q('purchase-order-receipt-r1')?.textContent).toContain('BL 77');
  });

  it('offers the printed order, its prices being costs, to a cost reader only', async () => {
    order.set(orderIn('sent'));
    await open('o1');

    expect(q('document-action-pdf')?.getAttribute('href')).toBe(
      '/api/companies/c1/purchase-orders/o1/pdf',
    );
    expect(q('document-action-pdf')?.textContent).not.toContain('pdf_draft');

    fixture.destroy();
    order.set(orderIn('draft'));
    await open('o1');
    expect(q('document-action-pdf')?.textContent).toContain('purchases.actions.pdf_draft');

    fixture.destroy();
    granted.delete('product.cost.read');
    order.set(orderIn('sent'));
    await open('o1');
    expect(await offered()).toEqual(['cancel', 'receive']);
  });

  it('names each line by the vendor’s own code for its product, in a draft as on a sent order', async () => {
    order.set(orderIn('draft'));
    await open('o1');
    expect(q('purchase-line-0-vendor-code')?.textContent).toContain('SUD-VIS6');

    fixture.destroy();
    order.set(orderIn('sent'));
    await open('o1');
    expect(q('purchase-order-line-0-vendor-code')?.textContent).toContain('SUD-VIS6');

    fixture.destroy();
    order.set({
      ...orderIn('sent'),
      lines: orderIn('sent').lines.map((line) => ({ ...line, vendorCode: null })),
    });
    await open('o1');
    expect(q('purchase-order-line-0-vendor-code')).toBeNull();
  });

  it('offers nothing on a sent order to whoever neither writes orders nor receives goods', async () => {
    granted.clear();
    order.set(orderIn('sent'));
    await open('o1');

    expect(await offered()).toEqual([]);
    expect(q('purchase-order-read-only')).not.toBeNull();
  });

  it('opens the receipt on the order’s lines and places, and says when everything has come', async () => {
    order.set(orderIn('sent'));
    await open('o1');
    const dialog = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => {
        order.set(orderIn('received'));
        return of(true);
      },
    } as never);

    q('document-action-receive')!.click();
    await settle();

    await vi.waitFor(() => expect(dialog).toHaveBeenCalled());
    const data = dialog.mock.calls[0]?.[1]?.data as ReceiveDialogData;
    expect(facade.receiving).toHaveBeenCalledWith('c1', 'o1');
    expect(data).toMatchObject({
      receiving,
      seesCost: true,
      timeZone: 'Africa/Tunis',
      issueDate: '2026-10-10',
    });
    expect(data.lines.map((line) => line.id)).toEqual(['l1']);
    await vi.waitFor(() => expect(successToasts()).toContain('purchases.received_all'));
  });

  it('says the rest is still expected when only a part came', async () => {
    order.set(orderIn('sent'));
    await open('o1');
    vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => {
        order.set(orderIn('partly_received'));
        return of(true);
      },
    } as never);

    q('document-action-receive')!.click();
    await settle();

    await vi.waitFor(() => expect(successToasts()).toEqual(['purchases.received_part']));
  });

  it('records the receipt through the dialog, and hands it the refusal and its field', async () => {
    order.set(orderIn('sent'));
    await open('o1');
    let data: ReceiveDialogData | undefined;
    vi.spyOn(TestBed.inject(MatDialog), 'open').mockImplementation((_, config) => {
      data = config?.data as ReceiveDialogData;
      return { afterClosed: () => of(undefined) } as never;
    });
    q('document-action-receive')!.click();
    await settle();
    await vi.waitFor(() => expect(data).toBeDefined());

    const input = { receivedOn: '2026-10-18', supplierReference: null, apply: null, lines: [] };
    expect(await data!.receive(input)).toBeNull();
    expect(facade.receive).toHaveBeenCalledWith('c1', 'o1', input);

    facade.receive.mockResolvedValue(null);
    error.set('invalid');
    refused.set('lines[0].lotCode');
    expect(await data!.receive(input)).toEqual({ code: 'invalid', field: 'lines[0].lotCode' });
    expect(facade.clearError).toHaveBeenCalled();
    expect(successToasts()).toEqual([]);
  });

  it('keeps a saved line where it is, showing what the API kept, so nothing typed into is redrawn', async () => {
    order.set(orderIn('draft'));
    await open('o1');
    const field = q('purchase-line-0-quantity') as HTMLInputElement;
    type('purchase-line-0-quantity', '120,5');
    await settle();
    const kept = orderIn('draft');
    const saved: PurchaseOrderRow = {
      ...kept,
      version: kept.version + 1,
      lines: kept.lines.map((line) => ({ ...line, quantity: '120.500' })),
    };
    facade.revise.mockImplementationOnce(async () => {
      order.set(saved);
      return saved;
    });
    q('document-action-save')!.click();
    await settle();

    expect(facade.revise).toHaveBeenCalled();
    expect(q('purchase-line-0-quantity')).toBe(field);
    expect(field.value.replace(',', '.')).toMatch(/^120\.50*$/);
  });

  it('shows a save refused on a line on that line', async () => {
    order.set(orderIn('draft'));
    await open('o1');
    refused.set('lines[0].quantity');
    await settle();

    expect(q('purchase-line-0-refused')?.textContent?.trim()).toBe(
      'Refusé par le serveur : Quantité',
    );
  });

  it('says each refusal of a save under its field, and no longer on the line as a whole', async () => {
    order.set(orderIn('draft'));
    await open('o1');
    facade.revise.mockImplementationOnce(async () => {
      error.set('invalid');
      refused.set('lines[0].quantity');
      refusals.set([
        { field: 'lines[0].quantity', reason: 'quantity_above_zero', params: {} },
        { field: 'vendorId', reason: 'vendor_deactivated', params: { number: 'F-0007' } },
      ]);
      return null;
    });
    type('purchase-line-0-quantity', '0');
    await settle();
    q('document-action-save')!.click();
    await settle();

    await vi.waitFor(() =>
      expect(q('purchase-line-0-quantity-error')?.textContent).toContain(
        'Attendu : une quantité au-dessus de zéro.',
      ),
    );
    expect(q('field-error-vendorId')?.textContent).toContain(
      'Le fournisseur « F-0007 » est désactivé.',
    );
    expect(q('purchase-line-0-refused')).toBeNull();
  });

  describe('the products « Ajouter à… » sends', () => {
    const paint: ProductOption = {
      ...screws,
      id: 'p2',
      reference: 'PEINT-1',
      name: 'Peinture blanche',
      unitId: 'u2',
      unitName: 'litre',
      costPrice: '3.5000',
    };
    const fitting: ProductOption = { ...screws, id: 'p3', name: 'Pose', goods: false };
    const retired: ProductOption = { ...screws, id: 'p4', name: 'Vis 4 mm', active: false };
    const value = (testId: string) => (q(testId) as HTMLInputElement | null)?.value ?? null;
    /** The products read by id outside the saved order's own, which is how what was sent here is read. */
    const readSent = () =>
      facade.pickProducts.mock.calls
        .map(([, asked]) => asked as { ids?: string[] })
        .filter((asked) => asked.ids !== undefined && asked.ids.some((id) => id !== 'p1'));
    let navigate: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
      navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      facade.pickProducts.mockImplementation(
        async (_companyId: string, asked: { ids?: string[] }) =>
          [screws, paint, fitting, retired].filter((each) => asked.ids?.includes(each.id)),
      );
    });

    async function openWith(id: string | undefined, add: string | string[]): Promise<void> {
      fixture = TestBed.createComponent(PurchaseOrderPage);
      if (id !== undefined) fixture.componentRef.setInput('purchaseOrderId', id);
      fixture.componentRef.setInput('add', add);
      await settle();
      await vi.waitFor(() => expect(noticeToasts()).not.toEqual([]));
      await settle();
    }

    it('puts them on an open draft as a scan would, unsaved, and the address forgets them', async () => {
      order.set(orderIn('draft'));
      await openWith('o1', ['p1', 'p2']);

      // The product already on a line counts one more; another starts its own line, named and priced at its cost.
      expect(value('purchase-line-0-quantity')).toBe('101');
      expect(value('purchase-line-1-description')).toBe('Peinture blanche');
      expect(value('purchase-line-1-quantity')).toBe('1');
      expect(value('purchase-line-1-price')).toBe('3,5');
      expect(q('purchase-line-1-unit')?.textContent).toContain('litre');
      expect(q('purchase-line-2')).toBeNull();
      expect(facade.revise).not.toHaveBeenCalled();
      expect(noticeToasts()).toEqual([{ key: 'documents.add_to.taken_in', params: { count: 2 } }]);
      expect(navigate).toHaveBeenCalledWith(
        [],
        expect.objectContaining({ queryParams: { add: null }, replaceUrl: true }),
      );
      await settle();
      expect(readSent()).toHaveLength(1);
    });

    it('starts a new order with them in place of its empty line', async () => {
      await openWith(undefined, 'p2');

      expect(value('purchase-line-0-description')).toBe('Peinture blanche');
      expect(q('purchase-line-1')).toBeNull();
    });

    it('leaves a price for a cost reader to set', async () => {
      granted.delete('product.cost.read');
      await openWith(undefined, 'p2');

      expect(value('purchase-line-0-description')).toBe('Peinture blanche');
      expect(q('purchase-line-0-price')).toBeNull();
      const lines = (
        fixture.componentInstance as unknown as { lines: () => { getRawValue(): unknown } }
      )
        .lines()
        .getRawValue();
      expect(lines).toEqual([expect.objectContaining({ productId: 'p2', unitPriceNet: '' })]);
    });

    it('orders no service and no retired product, and says why apart from the ones not found', async () => {
      order.set(orderIn('draft'));
      await openWith('o1', ['p3', 'gone', 'p2', 'p4']);

      expect(value('purchase-line-1-description')).toBe('Peinture blanche');
      expect(q('purchase-line-2')).toBeNull();
      expect(value('purchase-line-0-quantity')).toBe('100');
      expect(noticeToasts()).toEqual([
        { key: 'documents.add_to.taken_in', params: { count: 1 } },
        { key: 'documents.add_to.left_out', params: { count: 1 } },
        { key: 'documents.add_to.left_out_goods', params: { count: 2 } },
      ]);
    });

    it('adds nothing to an order that is no longer a draft, and says why', async () => {
      order.set(orderIn('sent'));
      await openWith('o1', ['p2']);

      expect(noticeToasts()).toEqual([{ key: 'documents.add_to.not_draft' }]);
      expect(readSent()).toEqual([]);
    });
  });
  describe('deleting a draft', () => {
    async function menuEntry(id: string): Promise<HTMLElement | null> {
      q('document-more')?.click();
      await fixture.whenStable();
      fixture.detectChanges();
      return document.body.querySelector<HTMLElement>(`[data-testid="document-menu-${id}"]`);
    }

    it('deletes the draft without asking, said annulable, and goes back to the list once it is gone', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      order.set(orderIn('draft'));
      await open('o1');

      const entry = await menuEntry('delete');
      expect(entry?.getAttribute('data-kind')).toBe('annulable');
      entry!.click();
      await settle();

      // Not numbered yet, it is named by its vendor.
      expect(deletes.remove).toHaveBeenCalledWith('c1', 'purchase_order', [
        { id: 'o1', label: 'Sotumag' },
      ]);
      expect(navigate).toHaveBeenCalledWith(['/purchases']);
    });

    it('stays on the draft when the delete is refused, and offers none once sent or to whoever does not write orders', async () => {
      const navigate = vi.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
      deletes.remove.mockResolvedValue(false);
      order.set(orderIn('draft'));
      await open('o1');
      (await menuEntry('delete'))!.click();
      await settle();
      expect(navigate).not.toHaveBeenCalled();

      fixture.destroy();
      order.set(orderIn('sent'));
      await open('o1');
      expect(await menuEntry('delete')).toBeNull();

      fixture.destroy();
      granted.delete('purchase.write');
      order.set(orderIn('draft'));
      await open('o1');
      expect(await menuEntry('delete')).toBeNull();
    });
  });
});
