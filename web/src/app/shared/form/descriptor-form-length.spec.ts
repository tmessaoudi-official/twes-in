// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
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
import type { FormDescriptor } from './form-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      form: { optional: 'facultatif', errors: { max_length: 'Au plus {{max}} caractères.' } },
    });
  }
}

const descriptor: FormDescriptor = {
  id: 'product',
  sections: [
    {
      id: 'main',
      title: 'Produit',
      fields: [
        { id: 'name', label: 'Nom', kind: 'text', maxLength: 10 },
        { id: 'notes', label: 'Notes', kind: 'textarea', maxLength: 10 },
      ],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  template: `<app-descriptor-form [descriptor]="descriptor" [form]="form" testId="product-form" />`,
})
class Host {
  readonly descriptor = descriptor;
  readonly form = buildFormGroup(descriptor, { name: '', notes: '' });
}

/**
 * Row 239: a field never cuts what was pasted at its limit, which would save something else unseen; it keeps the value
 * whole and says the limit and the count under it.
 */
describe('DescriptorForm with a value past its limit', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }
  const byTestId = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(Host);
    host = fixture.componentInstance;
    await settle();
  });

  it.each(['name', 'notes'])(
    'keeps the whole of a long %s and says its limit and count',
    async (id) => {
      const field = byTestId(`field-${id}`) as HTMLInputElement;

      expect(field.hasAttribute('maxlength')).toBe(false);
      field.value = 'Perceuse à percussion';
      field.dispatchEvent(new Event('input'));
      field.dispatchEvent(new Event('blur'));
      await settle();

      expect(host.form.controls[id].value).toBe('Perceuse à percussion');
      const error = byTestId(`field-error-${id}`)?.textContent ?? '';
      expect(error).toContain('Au plus 10 caractères.');
      expect(error).toContain('21 / 10');
    },
  );
});
