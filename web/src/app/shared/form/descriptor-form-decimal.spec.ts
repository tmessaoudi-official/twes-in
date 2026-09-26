// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { decimalShown, formatAmount } from '../i18n/format';
import { FormatFacade } from '../i18n/format-facade';
import { buildFormGroup } from './form-builder';
import { DescriptorForm } from './descriptor-form';
import type { FieldConflict } from './form-merge';
import type { FormDescriptor, FormValues } from './form-types';

const product: FormDescriptor = {
  id: 'product',
  sections: [
    {
      id: 'price',
      title: 'p.price',
      fields: [
        {
          id: 'unitPriceNet',
          label: 'p.unitPriceNet',
          kind: 'decimal',
          required: true,
          pattern: '(0|[1-9][0-9]{0,9})([.][0-9]{1,4})?',
        },
      ],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-descriptor-form
      [descriptor]="descriptor"
      [form]="form"
      [conflicts]="conflicts()"
      testId="product-form"
      (submitted)="saved.push($event)"
    >
      <button type="submit" data-testid="save">Save</button>
    </app-descriptor-form>
  `,
})
class Host {
  readonly descriptor = product;
  readonly form = buildFormGroup(product, { unitPriceNet: '890.000' });
  readonly conflicts = signal<FieldConflict[]>([]);
  readonly saved: FormValues[] = [];
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      p: { price: 'Price', unitPriceNet: 'Unit price' },
      live: {
        theirs: 'Their version: {{value}}',
        keep_theirs: 'Take theirs',
        keep_mine: 'Keep mine',
      },
      form: { optional: 'optional', errors: { pattern: 'Not the expected format.' } },
    });
  }
}

/** docs/SPEC.md § 7, 2026-09-19 21:55: a decimal field reads and takes the screen's separator; values stay the API's. */
describe('DescriptorForm, a decimal field', () => {
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        {
          provide: FormatFacade,
          useValue: {
            locale: signal('fr-TN'),
            amount: (value: string, scale: number | null) => formatAmount(value, scale, 'fr-TN'),
            decimal: (value: string) => decimalShown(value, 'fr-TN'),
          },
        },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('shows the value with the locale’s decimal separator, in a text field keyboards open on digits for', () => {
    const input = q('field-unitPriceNet') as HTMLInputElement;

    expect(input.value).toBe('890,000');
    expect(input.type).toBe('text');
    expect(input.getAttribute('inputmode')).toBe('decimal');
  });

  it('hands back the API’s point for a typed comma', async () => {
    const input = q('field-unitPriceNet') as HTMLInputElement;
    input.value = '12,5';
    input.dispatchEvent(new Event('input'));
    await settle();

    q('save')!.click();
    await settle();

    expect(fixture.componentInstance.saved).toEqual([{ unitPriceNet: '12.5' }]);
  });

  it('shows another person’s saved value the locale’s way', async () => {
    fixture.componentInstance.conflicts.set([
      { field: 'unitPriceNet', mine: '12.5', theirs: '1300.000' },
    ]);
    await settle();

    expect(q('field-conflict-unitPriceNet')?.textContent?.replace(/\s/g, ' ')).toContain(
      'Their version: 1 300,000',
    );
  });
});
