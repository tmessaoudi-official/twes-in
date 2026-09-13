// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { buildFormGroup } from './form-builder';
import { DescriptorForm } from './descriptor-form';
import type { FormDescriptor, FormValues } from './form-types';

const tax: FormDescriptor = {
  id: 'tax',
  sections: [
    {
      id: 'main',
      title: 't.main',
      fields: [
        {
          id: 'family',
          label: 't.family',
          kind: 'select',
          required: true,
          defaultValue: 'vat',
          options: [
            { value: 'vat', label: 't.vat' },
            { value: 'stamp', label: 't.stamp' },
          ],
        },
        {
          id: 'rate',
          label: 't.rate',
          kind: 'text',
          required: true,
          visibleWhen: { field: 'family', oneOf: ['vat'] },
        },
        {
          id: 'amount',
          label: 't.amount',
          kind: 'text',
          required: true,
          visibleWhen: { field: 'family', oneOf: ['stamp'] },
        },
        { id: 'isDefault', label: 't.default', kind: 'checkbox', hint: 't.default_hint' },
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
      testId="tax-form"
      (submitted)="saved.push($event)"
    >
      <button type="submit" data-testid="save">Save</button>
    </app-descriptor-form>
  `,
})
class Host {
  readonly descriptor = tax;
  readonly form = buildFormGroup(tax);
  readonly saved: FormValues[] = [];
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      t: {
        main: 'Tax',
        family: 'Family',
        vat: 'VAT',
        stamp: 'Stamp',
        rate: 'Rate',
        amount: 'Amount',
        default: 'Applied by default',
        default_hint: 'Offered first on a new line.',
      },
      form: { optional: 'optional', errors: { required: 'This field is required.' } },
    });
  }
}

describe('DescriptorForm with conditions and checkboxes', () => {
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
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('renders a checkbox with its label and hint', () => {
    expect(q('field-isDefault')?.tagName).toBe('MAT-CHECKBOX');
    expect(q('field-wrapper-isDefault')?.textContent).toContain('Applied by default');
    expect(q('field-wrapper-isDefault')?.textContent).toContain('Offered first on a new line.');
  });

  it('shows only the fields that apply, and follows a change of the controlling field', async () => {
    expect(q('field-rate')).not.toBeNull();
    expect(q('field-amount')).toBeNull();

    fixture.componentInstance.form.controls['family'].setValue('stamp');
    await settle();

    expect(q('field-rate')).toBeNull();
    expect(q('field-amount')).not.toBeNull();
  });

  it('submits although a hidden required field is empty, and hands back only what applies', async () => {
    const rate = q('field-rate') as HTMLInputElement;
    rate.value = '19';
    rate.dispatchEvent(new Event('input'));
    (q('field-isDefault')?.querySelector('input') as HTMLInputElement).click();
    await settle();

    q('save')!.click();
    await settle();

    expect(fixture.componentInstance.saved).toEqual([
      { family: 'vat', rate: '19', isDefault: true },
    ]);
  });

  it('refuses while a visible required field is empty', async () => {
    fixture.componentInstance.form.controls['family'].setValue('stamp');
    await settle();

    q('save')!.click();
    await settle();

    expect(fixture.componentInstance.saved).toEqual([]);
    expect(q('field-error-amount')?.textContent?.trim()).toBe('This field is required.');
  });
});
