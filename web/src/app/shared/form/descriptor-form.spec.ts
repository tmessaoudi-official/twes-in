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

const customer: FormDescriptor = {
  id: 'customer',
  sections: [
    {
      id: 'identity',
      title: 'c.identity',
      description: 'c.identity_about',
      fields: [
        { id: 'name', label: 'c.name', kind: 'text', required: true, minLength: 2, span: 2 },
        { id: 'email', label: 'c.email', kind: 'email' },
        { id: 'terms', label: 'c.terms', kind: 'number', min: 0, max: 120, defaultValue: 30 },
      ],
    },
    {
      id: 'billing',
      title: 'c.billing',
      fields: [
        { id: 'notes', label: 'c.notes', kind: 'textarea' },
        { id: 'code', label: 'c.code', kind: 'text', pattern: '[A-Z0-9]+', hint: 'c.code_hint' },
        {
          id: 'currency',
          label: 'c.currency',
          kind: 'select',
          required: true,
          defaultValue: 'TND',
          options: [
            { value: 'TND', label: 'c.tnd' },
            { value: 'EUR', label: 'c.eur' },
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
      testId="customer-form"
      (submitted)="saved.push($event)"
    >
      <button type="submit" data-testid="save">Save</button>
    </app-descriptor-form>
  `,
})
class Host {
  readonly descriptor = customer;
  readonly form = buildFormGroup(customer);
  readonly saved: FormValues[] = [];
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      c: {
        identity: 'Identity',
        identity_about: 'The name printed on every document.',
        billing: 'Billing',
        name: 'Name',
        email: 'Email',
        terms: 'Terms',
        notes: 'Notes',
        currency: 'Currency',
        tnd: 'Tunisian dinar',
        eur: 'Euro',
        code: 'Code',
        code_hint: 'Capital letters and digits.',
      },
      form: {
        optional: 'optional',
        errors: {
          required: 'This field is required.',
          min_length: 'At least {{min}} characters.',
          pattern: 'This value is not in the expected format.',
        },
      },
    });
  }
}

describe('DescriptorForm', () => {
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;
  const text = (testId: string): string | undefined => q(testId)?.textContent?.trim();

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  function type(testId: string, value: string): void {
    const input = q(testId) as HTMLInputElement;
    input.value = value;
    input.dispatchEvent(new Event('input'));
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

  it('renders each section as a titled group, and each field with its translated label', () => {
    const legends = Array.from(
      fixture.nativeElement.querySelectorAll('fieldset legend') as NodeListOf<HTMLElement>,
    ).map((legend) => legend.textContent?.trim());

    expect(legends).toEqual(['Identity', 'Billing']);
    expect(q('field-name')?.tagName).toBe('INPUT');
    expect(q('field-notes')?.tagName).toBe('TEXTAREA');
    expect(text('field-wrapper-name')).toContain('Name');
    expect(q('customer-form')?.tagName).toBe('FORM');
  });

  it('explains a section beside its title, and describes the group with it', () => {
    const [identity, billing] = Array.from(
      fixture.nativeElement.querySelectorAll('fieldset') as NodeListOf<HTMLFieldSetElement>,
    );
    const describedBy = identity.getAttribute('aria-describedby');

    expect(describedBy).toBeTruthy();
    expect(document.getElementById(describedBy!)?.textContent?.trim()).toBe(
      'The name printed on every document.',
    );
    expect(billing.hasAttribute('aria-describedby')).toBe(false);
  });

  it('puts each label above its field and ties it to the control', () => {
    const name = q('field-name') as HTMLInputElement;
    const notes = q('field-notes') as HTMLTextAreaElement;
    const currency = q('field-currency')!;

    expect(name.labels?.[0]?.textContent).toContain('Name');
    expect(notes.labels?.[0]?.textContent).toContain('Notes');
    const currencyName = (currency.getAttribute('aria-labelledby') ?? '')
      .split(' ')
      .map((id) => document.getElementById(id)?.textContent ?? '')
      .join(' ');
    expect(currencyName).toContain('Currency');
    expect(q('field-wrapper-name')?.querySelector('mat-label')).toBeNull();
  });

  it('says which fields are optional', () => {
    expect((q('field-email') as HTMLInputElement).labels?.[0]?.textContent).toContain('optional');
    expect(text('field-wrapper-email')).toContain('optional');
    expect(text('field-wrapper-name')).not.toContain('optional');
  });

  it('lets a field span both columns on a wide screen', () => {
    expect(q('field-wrapper-name')?.classList.contains('sm:col-span-2')).toBe(true);
    expect(q('field-wrapper-email')?.classList.contains('sm:col-span-2')).toBe(false);
  });

  it('offers the translated options of a select', async () => {
    (q('field-currency')?.querySelector('.mat-mdc-select-trigger') as HTMLElement).click();
    await settle();

    const options = Array.from(document.body.querySelectorAll('mat-option')).map((option) =>
      option.textContent?.trim(),
    );
    expect(options).toEqual(['Tunisian dinar', 'Euro']);
  });

  it('shows no error before a field is left, then the message with its limit', async () => {
    type('field-name', 'x');
    await settle();
    expect(q('field-error-name')).toBeNull();

    q('field-name')!.dispatchEvent(new Event('blur'));
    await settle();
    expect(text('field-error-name')).toBe('At least 2 characters.');
  });

  it('states the rule of a format it refuses, since the hint that stated it is hidden while the error shows (MSG-03)', async () => {
    type('field-code', 'ab 1');
    q('field-code')!.dispatchEvent(new Event('blur'));
    await settle();

    expect(text('field-error-code')).toBe(
      'This value is not in the expected format. Capital letters and digits.',
    );
  });

  it('refuses an invalid form: every error shows, the first invalid field takes focus, nothing is emitted', async () => {
    q('save')!.click();
    await settle();

    expect(text('field-error-name')).toBe('This field is required.');
    expect(document.activeElement).toBe(q('field-name'));
    expect(fixture.componentInstance.saved).toEqual([]);
  });

  it('hands back the values of a valid form, numbers as numbers', async () => {
    type('field-name', 'Acme');
    type('field-terms', '45');
    await settle();

    q('save')!.click();
    await settle();

    expect(fixture.componentInstance.saved).toEqual([
      { name: 'Acme', email: '', terms: 45, notes: '', code: '', currency: 'TND' },
    ]);
  });
});
