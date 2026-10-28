// SPDX-License-Identifier: AGPL-3.0-or-later

import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
  TranslateService,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { ProductBarcodes } from './product-barcodes-facade';
import { ProductBarcodesSection } from './product-barcodes';
import type { BarcodesRefusal, ProductBarcode } from './products-types';
import { announceSaved } from '../shared/testing/live';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const unit: ProductBarcode = { role: 'unit', code: '3017620422003', quantity: 1, supplierId: null };

describe('ProductBarcodesSection', () => {
  const refusal = signal<BarcodesRefusal | null>(null);
  const facade = {
    busy: signal(false).asReadonly(),
    refusal: refusal.asReadonly(),
    suppliers: signal([{ id: 'v1', label: 'F-001 · Sotupa' }]).asReadonly(),
    loadSuppliers: vi.fn(),
    save: vi.fn(),
    forget: vi.fn(),
  };
  const modules = new Set(['vendors']);
  const denied = new Set<string>();
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasModule: (module: string) => modules.has(module),
    hasPermission: (permission: string) => !denied.has(permission),
  };
  let fixture: ComponentFixture<ProductBarcodesSection>;
  /** What the section told the product page about the product's version. */
  const moved: string[] = [];

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const all = (prefix: string): HTMLElement[] => [
    ...fixture.nativeElement.querySelectorAll(`[data-testid^="${prefix}"]`),
  ];

  async function open(saved: ProductBarcode[] = [unit], readOnly = false): Promise<void> {
    fixture = TestBed.createComponent(ProductBarcodesSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('saved', saved);
    fixture.componentRef.setInput('version', 3);
    fixture.componentRef.setInput('readOnly', readOnly);
    moved.length = 0;
    fixture.componentInstance.moved.subscribe((how) => moved.push(how));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  /** What a scanner does: types the code into the focused field and presses Enter, in a few milliseconds. */
  async function scan(code: string): Promise<void> {
    const field = q('product-barcode-scan') as HTMLInputElement;
    field.value = code;
    field.dispatchEvent(new Event('input'));
    field.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const codes = (): string[] =>
    all('product-barcode-code-').map((input) => (input as HTMLInputElement).value);

  beforeEach(() => {
    refusal.set(null);
    modules.clear();
    modules.add('vendors');
    denied.clear();
    facade.loadSuppliers.mockReset().mockResolvedValue(undefined);
    facade.save.mockReset().mockResolvedValue('saved');
    facade.forget.mockReset();
    TestBed.configureTestingModule({
      imports: [ProductBarcodesSection],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        provideQuietFeedback(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: AuthFacade, useValue: auth },
      ],
    }).overrideComponent(ProductBarcodesSection, {
      set: { providers: [{ provide: ProductBarcodes, useValue: facade }] },
    });
  });

  it('lists the saved codes and saves nothing while nothing changed', async () => {
    await open();

    expect(codes()).toEqual(['3017620422003']);
    expect((q('product-barcodes-save') as HTMLButtonElement).disabled).toBe(true);
    expect(facade.loadSuppliers).toHaveBeenCalledWith('c1');
  });

  it('reads the suppliers again when a vendor changes elsewhere', async () => {
    await open();
    facade.loadSuppliers.mockClear().mockResolvedValue(undefined);

    await announceSaved('vendor', 'x1');

    expect(facade.loadSuppliers).toHaveBeenCalledWith('c1');
  });

  it('adds a scanned code, keeps the scan field ready for the next, and saves the list', async () => {
    await open();

    await scan('10012345678902');

    expect(codes()).toEqual(['3017620422003', '10012345678902']);
    expect((q('product-barcode-scan') as HTMLInputElement).value).toBe('');
    // The product already has its unit code, so the new one starts as an internal code, one choice from a pack.
    expect(q('product-barcode-role-1')?.textContent).toContain('products.barcodes.roles.internal');

    (q('product-barcodes-save') as HTMLButtonElement).click();
    await fixture.whenStable();

    // The product's version the list was read at: the codes are shown beside the product form.
    expect(facade.save).toHaveBeenCalledWith(
      'c1',
      'p1',
      [unit, { role: 'internal', code: '10012345678902', quantity: 1, supplierId: null }],
      3,
    );
    expect(successToasts()).toContain('products.barcodes.saved');
    expect(moved).toEqual(['saved']);
  });

  it('says a save refused as made over a version saved since, keeping the rows typed', async () => {
    facade.save.mockResolvedValue('changed');
    await open();
    await scan('10012345678902');

    (q('product-barcodes-save') as HTMLButtonElement).click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(moved).toEqual(['changed']);
    expect(successToasts()).not.toContain('products.barcodes.saved');
    expect(codes()).toEqual(['3017620422003', '10012345678902']);
  });

  const pack: ProductBarcode = {
    role: 'pack',
    code: '13017620422000',
    quantity: 12,
    supplierId: null,
  };

  /** Another person's save of the codes arriving, as the product page reads it again. */
  async function savedElsewhere(saved: ProductBarcode[], version: number): Promise<void> {
    fixture.componentRef.setInput('saved', saved);
    fixture.componentRef.setInput('version', version);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  it("takes another person's codes over rows nobody touched, saying nothing", async () => {
    await open();

    await savedElsewhere([unit, pack], 4);

    expect(codes()).toEqual(['3017620422003', '13017620422000']);
    expect(q('field-conflict-barcodes')).toBeNull();
  });

  // A form being typed in keeps what is typed and shows the banner.
  it("keeps rows being typed when another person's codes arrive, and takes theirs on request", async () => {
    await open();
    await scan('10012345678902');

    await savedElsewhere([pack], 4);

    expect(codes()).toEqual(['3017620422003', '10012345678902']);
    expect(q('field-conflict-barcodes')).not.toBeNull();

    (q('field-take-theirs-barcodes') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(codes()).toEqual(['13017620422000']);
    expect(q('field-conflict-barcodes')).toBeNull();
  });

  it("keeps rows being typed over another person's codes on request, and saves them on the version read", async () => {
    await open();
    await scan('10012345678902');
    await savedElsewhere([pack], 4);

    (q('field-keep-mine-barcodes') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(q('field-conflict-barcodes')).toBeNull();

    (q('product-barcodes-save') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(facade.save).toHaveBeenCalledWith(
      'c1',
      'p1',
      [unit, { role: 'internal', code: '10012345678902', quantity: 1, supplierId: null }],
      4,
    );
  });

  // Developer, 2026-09-25: a code typed by hand left « Enregistrer » greyed with nothing saying Enter adds it.
  it('offers « Ajouter » once a code is typed, and adds it as Enter does', async () => {
    await open();
    const add = q('product-barcode-add') as HTMLButtonElement;
    expect(add.disabled).toBe(true);

    const field = q('product-barcode-scan') as HTMLInputElement;
    field.value = '10012345678902';
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(add.disabled).toBe(false);

    add.click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(codes()).toEqual(['3017620422003', '10012345678902']);
    expect(field.value).toBe('');
    expect(add.disabled).toBe(true);
  });

  it('saves a typed code not yet added with the rest, rather than greying « Enregistrer »', async () => {
    await open();
    const field = q('product-barcode-scan') as HTMLInputElement;
    field.value = '10012345678902';
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    const save = q('product-barcodes-save') as HTMLButtonElement;
    expect(save.disabled).toBe(false);
    // A code scanned and not saved holds the page until it is.
    expect(TestBed.inject(UnsavedChanges).count()).toBe(1);
    save.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(facade.save).toHaveBeenCalledWith(
      'c1',
      'p1',
      [unit, { role: 'internal', code: '10012345678902', quantity: 1, supplierId: null }],
      3,
    );
    expect(field.value).toBe('');
  });

  it('says a code is already in the list rather than adding it twice', async () => {
    await open();

    await scan('03017620422003');

    expect(codes()).toEqual(['3017620422003']);
    expect(q('product-barcodes-duplicate')?.textContent).toContain('products.barcodes.duplicate');
  });

  it('refuses to save a row that is wrong, naming what is wrong under it', async () => {
    await open([]);

    await scan('3017620422004');

    expect(q('product-barcode-problem-0')?.textContent).toContain(
      'products.barcodes.problems.check_digit',
    );
    expect((q('product-barcodes-save') as HTMLButtonElement).disabled).toBe(true);
    // The code's own field is outlined, as every refused field is; the quantity, which is right, is not.
    const outlined = (id: string): boolean | undefined =>
      q(id)?.closest('mat-form-field')?.classList.contains('mat-form-field-invalid');
    expect(outlined('product-barcode-code-0')).toBe(true);
    expect(outlined('product-barcode-quantity-0')).toBe(false);
    // Said under the field it concerns, not under the whole row.
    expect(q('product-barcode-problem-0')?.closest('mat-form-field')).toBe(
      q('product-barcode-code-0')?.closest('mat-form-field'),
    );
    expect(q('product-barcode-code-0')?.getAttribute('aria-invalid')).toBe('true');
  });

  it('says a quantity out of its range under the quantity, which alone is outlined', async () => {
    await open([]);
    await scan('X-1');
    const quantity = q('product-barcode-quantity-0') as HTMLInputElement;
    quantity.value = '0';
    quantity.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(q('product-barcode-problem-0')?.textContent).toContain(
      'products.barcodes.problems.quantity_range',
    );
    expect(q('product-barcode-problem-0')?.closest('mat-form-field')).toBe(
      quantity.closest('mat-form-field'),
    );
    expect(quantity.getAttribute('aria-invalid')).toBe('true');
    expect(q('product-barcode-code-0')?.getAttribute('aria-invalid')).toBe('false');
  });

  it('says the API refusal of a quantity under the quantity it names', async () => {
    await open([]);
    await scan('X-1');
    refusal.set({
      code: 'invalid',
      index: 0,
      field: 'quantity',
      heldBy: null,
      reason: null,
      params: {},
    });
    fixture.detectChanges();

    const quantity = q('product-barcode-quantity-0') as HTMLInputElement;
    expect(q('product-barcode-problem-0')?.closest('mat-form-field')).toBe(
      quantity.closest('mat-form-field'),
    );
    expect(quantity.getAttribute('aria-invalid')).toBe('true');
    expect(q('product-barcode-code-0')?.getAttribute('aria-invalid')).toBe('false');
  });

  it('puts the refusal of a code another product holds under its row, naming that product', async () => {
    await open([]);
    await scan('X-1');
    refusal.set({
      code: 'barcode_taken',
      index: 0,
      field: 'code',
      heldBy: 'ART-001',
      reason: null,
      params: {},
    });
    fixture.detectChanges();

    expect(q('product-barcode-problem-0')?.textContent).toContain('products.barcodes.taken');
  });

  // docs/SPEC.md § 7, row 57: the row says what its reason says, as a form does, never « Ce code a été refusé ».
  it('says the reason the API gave for a row, and the plain refusal only for a reason no text words', async () => {
    const translate = TestBed.inject(TranslateService);
    translate.setTranslation('fr', {
      import: { rejections: { whole_number_between: 'De {{min}} à {{max}}.' } },
    });
    translate.use('fr');
    await open([]);
    await scan('X-1');
    const at = (reason: string | null) => {
      refusal.set({
        code: 'invalid',
        index: 0,
        field: 'quantity',
        heldBy: null,
        reason,
        params: { min: 1, max: 1000000 },
      });
      fixture.detectChanges();
      return q('product-barcode-problem-0')?.textContent?.trim();
    };

    expect(at('whole_number_between')).toBe('De 1 à 1000000.');
    expect(at('no_such_reason')).toBe('products.barcodes.refused');
    expect(at(null)).toBe('products.barcodes.refused');
  });

  it('removes a row and puts the saved list back', async () => {
    await open();

    (q('product-barcode-remove-0') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(codes()).toEqual([]);

    (q('product-barcodes-revert') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(codes()).toEqual(['3017620422003']);
  });

  it('offers a supplier role only where there are suppliers to name', async () => {
    modules.clear();
    await open();

    expect(facade.loadSuppliers).not.toHaveBeenCalled();
  });

  // docs/SPEC.md § 7, 2026-09-23 slice 5: a supplier's code is read and written with product.cost.read.
  it('names no supplier to someone who may not read costs', async () => {
    denied.add('product.cost.read');
    await open();

    expect(facade.loadSuppliers).not.toHaveBeenCalled();
  });

  it('shows the codes without a way to change them to a reader', async () => {
    await open([unit], true);

    expect(q('product-barcode-scan')).toBeNull();
    expect(q('product-barcodes-save')).toBeNull();
    expect(q('product-barcodes-readonly')?.textContent).toContain('3017620422003');
  });

  // docs/SPEC.md § 7, 2026-09-25 10:13: a scan on the product's own page adds its code to the rows being edited.
  it('adds a code a scan card sent here to the rows not saved yet, rather than starting them again', async () => {
    await open();
    await scan('10012345678902');

    fixture.componentRef.setInput('adding', '5449000000996');
    fixture.detectChanges();

    expect(codes()).toEqual(['3017620422003', '10012345678902', '5449000000996']);
  });

  it('lists a code a scan card sent here as a row waiting to be saved, and only once', async () => {
    fixture = TestBed.createComponent(ProductBarcodesSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('saved', [unit]);
    fixture.componentRef.setInput('version', 3);
    fixture.componentRef.setInput('adding', '5449000000996');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(codes()).toEqual(['3017620422003', '5449000000996']);
    expect(facade.save).not.toHaveBeenCalled();
    expect((q('product-barcodes-save') as HTMLButtonElement).disabled).toBe(false);

    // A code the product already holds is not listed twice, and the row waiting to be saved stays.
    fixture.componentRef.setInput('adding', '03017620422003');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(codes()).toEqual(['3017620422003', '5449000000996']);
  });

  it('adds nothing sent here for somebody who may only read the codes', async () => {
    fixture = TestBed.createComponent(ProductBarcodesSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('saved', [unit]);
    fixture.componentRef.setInput('version', 3);
    fixture.componentRef.setInput('readOnly', true);
    fixture.componentRef.setInput('adding', '5449000000996');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    const shown = fixture.nativeElement.textContent as string;
    expect(shown).toContain('3017620422003');
    expect(shown).not.toContain('5449000000996');
  });
});
