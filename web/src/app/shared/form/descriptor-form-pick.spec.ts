// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
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
import type { FormDescriptor, FormValues } from './form-types';
import type { PickOption } from './pick-field';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      expenses: { fields: { vendorId: 'Fournisseur' }, sections: { expense: 'Dépense' } },
      form: { optional: 'facultatif' },
    });
  }
}

const SOTUMAG: PickOption = { id: 'v1', code: 'FRN-0001', name: 'Sotumag' };

const descriptor: FormDescriptor = {
  id: 'expense',
  sections: [
    {
      id: 'expense',
      title: 'expenses.sections.expense',
      fields: [
        {
          id: 'vendorId',
          label: 'expenses.fields.vendorId',
          kind: 'pick',
          noneLabel: 'Aucun fournisseur',
          noneFoundLabel: 'Aucun fournisseur trouvé',
        },
      ],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  template: `
    <app-descriptor-form
      [descriptor]="descriptor"
      [form]="form"
      [pickers]="{ vendorId: { search, value: chosen() } }"
      testId="expense-form"
      (submitted)="saved.set($event)"
    >
      <button type="submit" data-testid="save">ok</button>
    </app-descriptor-form>
  `,
})
class Host {
  readonly descriptor = descriptor;
  readonly form = buildFormGroup(descriptor, { vendorId: '' });
  readonly chosen = signal<PickOption | null>(null);
  readonly saved = signal<FormValues | null>(null);
  readonly search = async (): Promise<readonly PickOption[]> => [SOTUMAG];
}

describe('DescriptorForm with a pick field', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    await settle();
    await Promise.resolve();
    await settle();
  });

  /** The whole point of the kind: a picker reads as one of the form's fields, not as something bolted above it. */
  it('wears the form label above its box, like every other field', () => {
    const label = fixture.nativeElement.querySelector('label[for="expense-form-vendorId"]');
    expect(label?.textContent).toContain('Fournisseur');
    expect(label?.textContent).toContain('facultatif');
    expect(q('field-wrapper-vendorId')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('mat-label')).toBeNull();
    // The other half, and the one that matters: a label naming an id nothing carries leaves the box unnamed.
    // Material's own host binding overwrites an attribute binding here, so this is asserted against the DOM.
    expect((q('field-vendorId') as HTMLInputElement).id).toBe('expense-form-vendorId');
    expect(label?.getAttribute('for')).toBe((q('field-vendorId') as HTMLInputElement).id);
  });

  it('puts the picked record into the control, so the form submits its id', async () => {
    (q('field-vendorId') as HTMLInputElement).dispatchEvent(new Event('focusin'));
    await settle();
    const option = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.trim() === 'FRN-0001 · Sotumag',
    );
    expect(option).toBeDefined();
    option!.click();
    await settle();

    expect(host.form.getRawValue()['vendorId']).toBe('v1');

    q('save')!.click();
    await settle();
    expect(host.saved()?.['vendorId']).toBe('v1');
  });

  it('empties the control when the person answers that it names nobody', async () => {
    host.chosen.set(SOTUMAG);
    host.form.controls['vendorId']?.setValue('v1');
    await settle();

    (q('field-vendorId') as HTMLInputElement).dispatchEvent(new Event('focusin'));
    await settle();
    const none = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.trim() === 'Aucun fournisseur',
    );
    expect(none, 'a field that is not required offers naming nobody').toBeDefined();
    none!.click();
    await settle();

    expect(host.form.getRawValue()['vendorId']).toBe('');
  });
});
