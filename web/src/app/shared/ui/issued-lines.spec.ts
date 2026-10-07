// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FormatFacade } from '../i18n/format-facade';
import { type IssuedLine, IssuedLines } from './issued-lines';
import { WINDOW_CLASS, type WindowClass } from './window-class';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      issued_lines: {
        description: 'Item',
        quantity: 'Quantity',
        unit: 'Unit',
        price: 'Unit price',
        discount: 'Discount',
        taxes: 'Taxes',
        net: 'Net',
        reference: 'Ref. {{code}}',
        lot: 'Lot {{code}}',
        discount_value: 'Discount {{rate}} %',
        discount_amount_value: 'Discount {{amount}}',
      },
      n: { returned: 'Back in stock' },
    });
  }
}

@Component({
  imports: [IssuedLines],
  template: `<app-issued-lines testId="lines" [lines]="lines()" [currencyScale]="3" />`,
})
class Host {
  readonly lines = signal<IssuedLine[]>([]);
}

const screws: IssuedLine = {
  description: 'Vis 4 × 40',
  reference: 'VIS-440',
  lot: 'L-7',
  notes: ['n.returned'],
  quantity: '12.000',
  quantityScale: 0,
  unit: 'Pièce',
  unitPrice: '0.2500',
  discountRate: '10.000',
  discountAmount: null,
  taxes: 'TVA 19 %',
  net: '2.700',
};

const advice: IssuedLine = {
  description: 'Conseil',
  reference: null,
  lot: null,
  notes: [],
  quantity: '1.500',
  quantityScale: 2,
  unit: 'Heure',
  unitPrice: '80.0000',
  discountRate: null,
  discountAmount: null,
  taxes: '',
  net: '120.000',
};

describe('IssuedLines', () => {
  let fixture: ComponentFixture<Host>;
  const width = signal<WindowClass>('expanded');

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;
  const text = (testId: string): string =>
    q(testId)?.textContent?.replace(/\s+/g, ' ').trim() ?? '';

  async function show(lines: IssuedLine[]): Promise<void> {
    fixture.componentInstance.lines.set(lines);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    width.set('expanded');
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({ loader: provideTranslateLoader(StaticLoader), lang: 'en' }),
        { provide: WINDOW_CLASS, useValue: width },
        {
          provide: FormatFacade,
          useValue: {
            amount: (value: string, scale: number | null) =>
              `~${scale === null ? value : Number(value).toFixed(scale)}`,
          },
        },
      ],
    });
    fixture = TestBed.createComponent(Host);
  });

  it('shows issued lines as a table of read values, never as fields', async () => {
    await show([screws, advice]);

    expect(q('lines')?.querySelector('table')).not.toBeNull();
    expect(q('lines')?.querySelector('input, mat-form-field, app-select')).toBeNull();
    const headers = [...(q('lines')?.querySelectorAll('th[scope="col"]') ?? [])].map((th) =>
      th.textContent?.trim(),
    );
    expect(headers).toEqual(['Item', 'Quantity', 'Unit', 'Unit price', 'Discount', 'Taxes', 'Net']);
    expect(text('line-0-description')).toContain('Vis 4 × 40');
    expect(text('line-0-description')).toContain('Ref. VIS-440');
    expect(text('line-0-description')).toContain('Lot L-7');
    expect(text('line-0-description')).toContain('Back in stock');
    expect(text('line-0-quantity')).toBe('~12');
    expect(text('line-0-unit')).toBe('Pièce');
    expect(text('line-0-price')).toBe('~0.250');
    expect(text('line-0-discount')).toBe('~10 %');
    expect(text('line-0-taxes')).toBe('TVA 19 %');
    expect(text('line-0-net')).toBe('~2.700');
    expect(text('line-1-quantity')).toBe('~1.50');
    expect(text('line-1-discount')).toBe('');
  });

  it('shows a discount given as an amount as money, at the currency’s scale', async () => {
    await show([advice, { ...advice, discountAmount: '7.5' }]);

    expect(text('line-0-discount')).toBe('');
    expect(text('line-1-discount')).toBe('~7.500');

    width.set('compact');
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    expect(q('line-0-discount')).toBeNull();
    expect(text('line-1-discount')).toBe('Discount ~7.500');
  });

  it('leaves out the discount and net columns when no line has one', async () => {
    await show([{ ...advice, net: null, discountAmount: '0.000' }]);

    const headers = [...(q('lines')?.querySelectorAll('th[scope="col"]') ?? [])].map((th) =>
      th.textContent?.trim(),
    );
    expect(headers).toEqual(['Item', 'Quantity', 'Unit', 'Unit price', 'Taxes']);
    expect(q('line-0-discount')).toBeNull();
    expect(q('line-0-net')).toBeNull();
  });

  it('lists each line on a phone, its figures on one line under what it is', async () => {
    width.set('compact');
    await show([screws]);

    expect(q('lines')?.querySelector('table')).toBeNull();
    expect(q('line-0')?.tagName).toBe('LI');
    expect(text('line-0-description')).toContain('Vis 4 × 40');
    expect(text('line-0-quantity')).toBe('~12');
    expect(text('line-0-price')).toBe('~0.250');
    expect(text('line-0-discount')).toBe('Discount ~10 %');
    expect(text('line-0')).toContain('~12 Pièce × ~0.250');
    expect(text('line-0-net')).toBe('~2.700');
  });
});
