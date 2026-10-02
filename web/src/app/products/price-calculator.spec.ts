// SPDX-License-Identifier: AGPL-3.0-or-later

import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, FormGroup } from '@angular/forms';
import { provideTranslateService } from '@ngx-translate/core';
import { beforeEach, describe, expect, it } from 'vitest';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import { PriceCalculator } from './price-calculator';

describe('PriceCalculator', () => {
  let fixture: ComponentFixture<PriceCalculator>;
  let form: DescriptorFormGroup;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const text = (testId: string): string =>
    (q(testId)?.textContent ?? '').replace(/\s+/g, ' ').trim();

  async function open(cost: string, price: string, readOnly = false): Promise<void> {
    form = new FormGroup({
      costPrice: new FormControl<string | boolean | null>(cost),
      unitPriceNet: new FormControl<string | boolean | null>(price),
    }) as unknown as DescriptorFormGroup;
    fixture = TestBed.createComponent(PriceCalculator);
    fixture.componentRef.setInput('form', form);
    fixture.componentRef.setInput('scale', 3);
    fixture.componentRef.setInput('currency', 'TND');
    fixture.componentRef.setInput('readOnly', readOnly);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function want(value: string): Promise<void> {
    const input = q('price-calculator-wanted') as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [PriceCalculator],
      providers: [provideTranslateService()],
    });
  });

  it('says what a unit earns from the cost and the price in the form, and follows them as they are typed', async () => {
    await open('60.000', '100.000');

    expect(text('price-calculator-profit')).toBe('40.000 TND');
    expect(text('price-calculator-margin')).toBe('40.00 %');
    expect(text('price-calculator-markup')).toBe('66.67 %');

    form.get('unitPriceNet')!.setValue('120.000');
    fixture.detectChanges();
    expect(text('price-calculator-profit')).toBe('60.000 TND');
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
    expect(text('price-calculator-target')).toBe('100.000 TND');

    (q('price-calculator-basis-markup') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(text('price-calculator-target')).toBe('84.000 TND');

    // A comma is a decimal separator too, as it is in the fields beside.
    await want('12,5');
    expect(text('price-calculator-target')).toBe('67.500 TND');

    (q('price-calculator-apply') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(form.get('unitPriceNet')!.value).toBe('67.500');
    expect(form.get('unitPriceNet')!.dirty).toBe(true);
    expect(text('price-calculator-markup')).toBe('12.50 %');
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
});
