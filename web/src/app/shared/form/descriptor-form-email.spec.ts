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
      form: {
        optional: 'facultatif',
        email_typo: 'Vouliez-vous dire :',
        errors: { email: 'Une adresse e-mail s’écrit nom@domaine.extension.' },
      },
    });
  }
}

const descriptor: FormDescriptor = {
  id: 'customer',
  sections: [
    {
      id: 'contact',
      title: 'Contact',
      fields: [{ id: 'email', label: 'E-mail', kind: 'email', maxLength: 254 }],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  template: `<app-descriptor-form
    [descriptor]="descriptor"
    [form]="form"
    testId="customer-form"
  />`,
})
class Host {
  readonly descriptor = descriptor;
  readonly form = buildFormGroup(descriptor, { email: '' });
}

/**
 * Every address field of a descriptor form is held to the one e-mail rule the API holds it to, and offers, never
 * writes, the common domain a slip of a key most likely meant.
 */
describe('DescriptorForm with an e-mail field', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }
  const byTestId = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function typeIn(value: string): Promise<void> {
    const field = byTestId('field-email') as HTMLInputElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));
    field.dispatchEvent(new Event('blur'));
    await settle();
  }

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

  it('refuses an address whose domain has no dot, as the API does, saying its format', async () => {
    await typeIn('compta@exemple');

    expect(host.form.controls['email'].hasError('email')).toBe(true);
    expect(byTestId('field-error-email')?.textContent).toContain('nom@domaine.extension');
  });

  it('offers the address a slip of a key most likely meant, and leaves what was typed', async () => {
    await typeIn('ali@gmial.com');

    expect(byTestId('field-typo-email')?.textContent).toContain('Vouliez-vous dire :');
    expect(byTestId('field-typo-email')?.querySelector('bdi')?.textContent).toBe('ali@gmail.com');
    expect(host.form.controls['email'].value).toBe('ali@gmial.com');
    expect(host.form.controls['email'].valid).toBe(true);
  });

  it('offers nothing for an address at a domain of its own', async () => {
    await typeIn('compta@quincaillerie-sfax.tn');

    expect(byTestId('field-typo-email')).toBeNull();
  });
});
