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
import { DescriptorForm } from './descriptor-form';
import { buildFormGroup } from './form-builder';
import type { FieldConflict } from './form-merge';
import type { FormDescriptor, FormValues } from './form-types';

const product: FormDescriptor = {
  id: 'product',
  sections: [
    {
      id: 'taxes',
      title: 'p.taxes',
      fields: [
        {
          id: 'defaultTaxComponentIds',
          label: 'p.defaultTaxComponentIds',
          kind: 'multiselect',
          options: [
            { value: 't1', label: 'p.vat' },
            { value: 't2', label: 'p.fodec' },
          ],
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
  readonly form = buildFormGroup(product, { defaultTaxComponentIds: ['t1'] });
  readonly conflicts = signal<FieldConflict[]>([]);
  readonly saved: FormValues[] = [];
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      p: {
        taxes: 'Taxes',
        defaultTaxComponentIds: 'Default taxes',
        vat: 'VAT 19 %',
        fodec: 'FODEC',
      },
      live: {
        theirs: 'Their version: {{value}}',
        take_theirs: 'Take theirs',
        keep_mine: 'Keep mine',
      },
      select: {
        placeholder: 'Choose',
        search: 'Search',
        none_found: 'Nothing found',
        select_all: 'Select all',
        clear_all: 'Clear all',
        more: '+{{count}}',
      },
      form: { optional: 'optional' },
    });
  }
}

describe('DescriptorForm, a multiselect field', () => {
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

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('shows what is chosen as chips, named by its label above, and says it is optional', () => {
    const trigger = q('field-defaultTaxComponentIds')!;

    expect(
      Array.from(trigger.querySelectorAll('[data-chip]')).map((c) => c.textContent?.trim()),
    ).toEqual(['VAT 19 %']);
    const name = (trigger.getAttribute('aria-labelledby') ?? '')
      .split(' ')
      .map((id) => document.getElementById(id)?.textContent ?? '')
      .join(' ');
    expect(name).toContain('Default taxes');
    expect(q('field-wrapper-defaultTaxComponentIds')?.textContent).toContain('optional');
  });

  it('keeps the control a list of ids as options are chosen, in the order they are offered', async () => {
    q('field-defaultTaxComponentIds')!.click();
    await settle();
    const options = document.body.querySelectorAll<HTMLElement>('[role="option"]');
    expect(
      Array.from(options).map((o) => o.querySelector('[data-option-label]')?.textContent?.trim()),
    ).toEqual(['VAT 19 %', 'FODEC']);

    options[1]!.click();
    await settle();

    expect(fixture.componentInstance.form.getRawValue()).toEqual({
      defaultTaxComponentIds: ['t1', 't2'],
    });
    expect(fixture.componentInstance.form.dirty).toBe(true);
  });
});
