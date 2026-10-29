// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Session, type SessionState } from '../session/session';
import { DescriptorForm } from './descriptor-form';
import { buildFormGroup } from './form-builder';
import type { FormDescriptor } from './form-types';
import { PLATFORM_PHONE_COUNTRY } from './phone-country';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      form: { optional: 'facultatif', phone: { country: 'Indicatif du pays' } },
      import: {
        rejections: {
          phone_length:
            'Ce numéro n’a pas le nombre de chiffres de son pays (exemple : {{example}}).',
        },
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
      fields: [{ id: 'phone', label: 'Téléphone', kind: 'tel', maxLength: 40 }],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  template: `<app-descriptor-form
    [descriptor]="descriptor"
    [form]="form"
    [phoneCountryOf]="country()"
    testId="customer-form"
    (submitted)="saved.push($event)"
  />`,
})
class Host {
  readonly descriptor = descriptor;
  readonly country = signal<string | null>(null);
  form = buildFormGroup(descriptor, { phone: '' });
  readonly saved: unknown[] = [];
}

class FrenchCompany extends Session {
  readonly me = signal<SessionState | null>({
    user: { id: 'u1' },
    company: { id: 'c1', countryCode: 'FR', currency: 'EUR' },
  });
  hasPermission(): boolean {
    return true;
  }
  hasModule(): boolean {
    return true;
  }
}

/**
 * Every phone field of a descriptor form is the one phone field (row 252): the company's country picked beside the
 * local number, the number handed on in international form, a kept one read back into the two, and refused for its
 * length with an example of its country.
 */
describe('DescriptorForm with a phone field', () => {
  let fixture: ComponentFixture<Host>;

  function configure(providers: unknown[]): void {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        ...(providers as never[]),
      ],
    });
  }

  async function open(start: string, country: string | null = null): Promise<Host> {
    fixture = TestBed.createComponent(Host);
    fixture.componentInstance.form = buildFormGroup(descriptor, { phone: start });
    fixture.componentInstance.country.set(country);
    await settle();
    return fixture.componentInstance;
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const byTestId = (testId: string): HTMLElement | null =>
    document.querySelector(`[data-testid="${testId}"]`);
  const picked = (): string => byTestId('field-phone-country')?.textContent ?? '';

  async function typeIn(value: string): Promise<void> {
    const field = byTestId('field-phone') as HTMLInputElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));
    field.dispatchEvent(new Event('blur'));
    await settle();
  }

  async function submit(): Promise<void> {
    fixture.debugElement
      .query((node) => node.name === 'app-descriptor-form')
      .componentInstance.submit();
    await settle();
  }

  afterEach(() => document.querySelector('.cdk-overlay-container')?.remove());

  describe('in a company', () => {
    beforeEach(() => configure([{ provide: Session, useClass: FrenchCompany }]));

    it("starts on the company's country and hands on the number in international form", async () => {
      const host = await open('');

      // Two boxes (ruled after 16:53): the country by its flag, its name and its code, then the local number.
      expect(picked()).toContain('France');
      expect(picked()).toContain('+33');
      await typeIn('06 12 34 56 78');
      expect(host.form.controls['phone'].value).toBe('+33 6 12 34 56 78');
    });

    it('reads a kept number into its own country and local part, and saves it untouched as kept', async () => {
      const host = await open('+21671234567');

      expect(picked()).toContain('+216');
      expect((byTestId('field-phone') as HTMLInputElement).value).toBe('71 234 567');
      await typeIn('71 234 567');
      expect(host.form.controls['phone'].value).toBe('+21671234567');
      await submit();
      expect(host.saved).toEqual([{ phone: '+21671234567' }]);
    });

    // A number kept from before the rule is shown as saved, and the record's other fields still save while it stands.
    it('shows a number kept from before the rule as saved, and saves the form around it', async () => {
      const host = await open('71 900 868 poste 3');

      expect((byTestId('field-phone') as HTMLInputElement).value).toBe('71 900 868 poste 3');
      await submit();
      expect(host.saved).toEqual([{ phone: '71 900 868 poste 3' }]);
      expect(byTestId('field-error-phone')).toBeNull();
    });

    it('refuses a number of the wrong length for its country, with an example of that country', async () => {
      const host = await open('');

      await typeIn('06 12 34');

      expect(host.form.controls['phone'].hasError('phone')).toBe(true);
      expect(byTestId('field-error-phone')?.textContent).toMatch(/exemple : \+33 /);
    });

    it('writes the number again under another country picked', async () => {
      const host = await open('');
      await typeIn('71 234 567');

      byTestId('field-phone-country')!.click();
      await settle();
      byTestId('field-phone-country-TN')!.click();
      await settle();

      expect(host.form.controls['phone'].value).toBe('+216 71 234 567');
    });
  });

  describe("on the platform's own forms", () => {
    beforeEach(() => configure([{ provide: PLATFORM_PHONE_COUNTRY, useValue: 'MA' }]));

    it("starts on the platform's country with no company open, or on the one the form names", async () => {
      await open('');
      expect(picked()).toContain('+212');

      fixture.destroy();
      await open('', 'TN');
      expect(picked()).toContain('+216');
    });
  });
});
