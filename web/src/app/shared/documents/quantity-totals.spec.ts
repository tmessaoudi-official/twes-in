// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { FormArray, FormControl, FormGroup } from '@angular/forms';
import { provideTranslateService } from '@ngx-translate/core';
import { FormatFacade } from '../i18n/format-facade';
import { QuantityTotalsView, quantityTotals } from './quantity-totals';

const units = [
  { id: 'u-kg', name: 'kg', decimals: 3 },
  { id: 'u-pc', name: 'pièce', decimals: 0 },
];

describe('quantityTotals', () => {
  it('adds each unit exactly, in the order the units first appear', () => {
    expect(
      quantityTotals(
        [
          { unitId: 'u-kg', quantity: '0.1' },
          { unitId: 'u-pc', quantity: '4' },
          { unitId: 'u-kg', quantity: '0.2' },
          { unitId: 'u-pc', quantity: '8' },
        ],
        units,
      ),
    ).toEqual([
      { unitName: 'kg', decimals: 3, quantity: '0.300' },
      { unitName: 'pièce', decimals: 0, quantity: '12' },
    ]);
  });

  it('leaves out a line giving a deposit back, and one still being typed', () => {
    expect(
      quantityTotals(
        [
          { unitId: 'u-pc', quantity: '4' },
          { unitId: 'u-pc', quantity: '1', deductsInvoiceId: 'i0' },
          { unitId: 'u-pc', quantity: '' },
          { unitId: '', quantity: '3' },
          { unitId: 'u-pc', quantity: '2' },
        ],
        units,
      ),
    ).toEqual([{ unitName: 'pièce', decimals: 0, quantity: '6' }]);
  });

  it('adds up nothing for a single line, which already says it', () => {
    expect(quantityTotals([{ unitId: 'u-pc', quantity: '4' }], units)).toEqual([]);
  });
});

describe('QuantityTotalsView', () => {
  it('follows the lines as they are typed', () => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr' }),
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
      ],
    });
    const line = (unitId: string, quantity: string) =>
      new FormGroup({ unitId: new FormControl(unitId), quantity: new FormControl(quantity) });
    const lines = new FormArray([line('u-pc', '4'), line('u-pc', '2')]);
    const fixture = TestBed.createComponent(QuantityTotalsView);
    fixture.componentRef.setInput('lines', lines);
    fixture.componentRef.setInput('units', units);
    fixture.componentRef.setInput('testId', 'invoice-quantities');
    fixture.detectChanges();
    const text = () =>
      (fixture.nativeElement as HTMLElement)
        .querySelector('[data-testid="invoice-quantities"]')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim() ?? null;

    expect(text()).toContain('~6 pièce');

    lines.push(line('u-kg', '1.5'));
    fixture.detectChanges();
    expect(text()).toContain('~6 pièce · ~1.500 kg');

    lines.removeAt(2);
    lines.removeAt(1);
    fixture.detectChanges();
    expect(text()).toBeNull();
  });
});
