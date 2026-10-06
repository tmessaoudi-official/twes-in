// SPDX-License-Identifier: AGPL-3.0-or-later

import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, FormGroup } from '@angular/forms';
import { provideTranslateService } from '@ngx-translate/core';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import { FormatFacade } from '../shared/i18n/format-facade';
import { PRICE_PREVIEW_DELAY, PriceCalculator } from './price-calculator';
import { ProductsFacade } from './products-facade';
import type { PricePreviewLine } from './products-types';

describe('PriceCalculator', () => {
  let fixture: ComponentFixture<PriceCalculator>;
  let form: DescriptorFormGroup;
  // What the API's calculator answers here: 19 % on the net, as many units as the line holds.
  const counted = (price: string, quantity: string): PricePreviewLine => {
    const net = (Number(price) * Number(quantity)).toFixed(3);
    const tax = (Number(net) * 0.19).toFixed(3);
    return { quantity, net, tax, total: (Number(net) + Number(tax)).toFixed(3) };
  };
  const pricePreview = vi.fn(
    async (
      _companyId: string,
      price: string,
      _taxes: readonly string[],
      quantities: readonly string[],
    ): Promise<PricePreviewLine[] | null> => quantities.map((quantity) => counted(price, quantity)),
  );

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const text = (testId: string): string =>
    (q(testId)?.textContent ?? '').replace(/\s+/g, ' ').trim();

  async function open(
    cost: string,
    price: string,
    readOnly = false,
    packs: readonly number[] = [],
  ): Promise<void> {
    form = new FormGroup({
      costPrice: new FormControl<string | boolean | null>(cost),
      unitPriceNet: new FormControl<string | boolean | null>(price),
      defaultTaxComponentIds: new FormControl<string[]>(['tva19']),
    }) as unknown as DescriptorFormGroup;
    fixture = TestBed.createComponent(PriceCalculator);
    fixture.componentRef.setInput('form', form);
    fixture.componentRef.setInput('scale', 3);
    fixture.componentRef.setInput('currency', 'TND');
    fixture.componentRef.setInput('readOnly', readOnly);
    fixture.componentRef.setInput('companyId', 'c-1');
    fixture.componentRef.setInput('packs', packs);
    fixture.detectChanges();
    await settled();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function want(value: string): Promise<void> {
    const input = q('price-calculator-wanted') as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await fixture.whenStable();
    fixture.detectChanges();
    await settled();
  }

  /** The previews answered and drawn: the delay is nothing here, the answers a few microtasks away. */
  async function settled(): Promise<void> {
    for (let turn = 0; turn < 3; turn++) {
      await new Promise((resolve) => setTimeout(resolve, 0));
      await fixture.whenStable();
      fixture.detectChanges();
    }
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [PriceCalculator],
      providers: [
        provideTranslateService(),
        { provide: ProductsFacade, useValue: { pricePreview } },
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
        { provide: PRICE_PREVIEW_DELAY, useValue: 0 },
      ],
    });
    pricePreview.mockClear();
  });

  it('says what a unit earns from the cost and the price in the form, and follows them as they are typed', async () => {
    await open('60.000', '100.000');

    expect(text('price-calculator-profit')).toBe('~40.000 TND');
    expect(text('price-calculator-margin')).toBe('40.00 %');
    expect(text('price-calculator-markup')).toBe('66.67 %');

    form.get('unitPriceNet')!.setValue('120.000');
    fixture.detectChanges();
    expect(text('price-calculator-profit')).toBe('~60.000 TND');
    expect(text('price-calculator-margin')).toBe('50.00 %');
  });

  it('asks for both amounts before it says anything, and offers no price without a cost', async () => {
    await open('', '100.000');
    expect(q('price-calculator-missing')).not.toBeNull();
    expect(q('price-calculator-profit')).toBeNull();
    expect(q('price-calculator-wanted')).toBeNull();
  });

  it('finds the price for the margin wanted, or the markup, and puts it in the form on request', async () => {
    await open('60.000', '70.000');

    await want('40');
    expect(text('price-calculator-target')).toBe('~100.000 TND');

    (q('price-calculator-basis-markup') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(text('price-calculator-target')).toBe('~84.000 TND');

    // A comma is a decimal separator too, as it is in the fields beside.
    await want('12,5');
    expect(text('price-calculator-target')).toBe('~67.500 TND');

    (q('price-calculator-apply') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(form.get('unitPriceNet')!.value).toBe('67.500');
    expect(form.get('unitPriceNet')!.dirty).toBe(true);
    expect(text('price-calculator-markup')).toBe('12.50 %');
  });

  it('also says the price the same percentage gives on the other basis, so 30 % is not read as the wrong one', async () => {
    await open('45.000', '60.000');

    await want('30');
    expect(text('price-calculator-target')).toBe('~64.286 TND');
    expect(text('price-calculator-other')).toContain('products.calculator.other_margin');

    (q('price-calculator-basis-markup') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(text('price-calculator-target')).toBe('~58.500 TND');
    expect(text('price-calculator-other')).toContain('products.calculator.other_markup');
  });

  it('shows, under each figure and under the price to ask, the sum it comes from', async () => {
    await open('45.000', '64.286');
    await want('30');

    expect(text('price-calculator-margin-how')).toContain('products.calculator.margin_how');
    expect(text('price-calculator-markup-how')).toContain('products.calculator.markup_how');
    expect(text('price-calculator-target-how')).toContain('products.calculator.target_margin');
    (q('price-calculator-basis-markup') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(text('price-calculator-target-how')).toContain('products.calculator.target_markup');
  });

  it('says no price gives a margin of a hundred percent, and offers no button to a reader', async () => {
    await open('60.000', '70.000');
    await want('100');
    expect(q('price-calculator-target')).toBeNull();
    expect(q('price-calculator-no-price')).not.toBeNull();

    await want('30');
    expect(q('price-calculator-apply')).not.toBeNull();

    await open('60.000', '70.000', true);
    await want('30');
    expect(q('price-calculator-target')).not.toBeNull();
    expect(q('price-calculator-apply')).toBeNull();
  });

  it('says what the price in the form comes to with its taxes, per unit and per pack, as the API counts it', async () => {
    await open('60.000', '100.000', false, [12, 6, 12]);

    expect(pricePreview).toHaveBeenLastCalledWith('c-1', '100.000', ['tva19'], ['1', '6', '12']);
    expect(text('price-calculator-with-tax-1')).toContain('~100.000');
    expect(text('price-calculator-with-tax-1')).toContain('~19.000');
    expect(text('price-calculator-with-tax-1')).toContain('~119.000');
    expect(text('price-calculator-with-tax-12')).toContain('products.calculator.per_pack');
    expect(text('price-calculator-with-tax-12')).toContain('~1428.000');

    form.get('defaultTaxComponentIds')!.setValue([]);
    form.get('unitPriceNet')!.setValue('110.000');
    await settled();
    expect(pricePreview).toHaveBeenLastCalledWith('c-1', '110.000', [], ['1', '6', '12']);
  });

  it('says the price to ask with its taxes too, per unit and per pack', async () => {
    await open('60.000', '70.000', false, [12]);
    await want('40');

    expect(pricePreview).toHaveBeenCalledWith('c-1', '100.000', ['tva19'], ['1', '12']);
    expect(text('price-calculator-target-with-tax-1')).toContain('~119.000');
    expect(text('price-calculator-target-with-tax-12')).toContain('~1428.000');
  });

  it('says when the taxes cannot be counted, and asks nothing of a price that is not one', async () => {
    pricePreview.mockResolvedValueOnce(null);
    await open('60.000', '100.000');
    expect(q('price-calculator-with-tax-unavailable')).not.toBeNull();
    expect(q('price-calculator-with-tax-1')).toBeNull();

    pricePreview.mockClear();
    form.get('unitPriceNet')!.setValue('cent');
    await settled();
    expect(pricePreview).not.toHaveBeenCalled();
    expect(q('price-calculator-with-tax-unavailable')).toBeNull();
  });
});
