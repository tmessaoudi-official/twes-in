// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, viewChild } from '@angular/core';
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
import type { FieldRefusal } from './field-refusals';
import type { FormDescriptor } from './form-types';
import { RefusalFigures } from './refusal-figures';

const party: FormDescriptor = {
  id: 'party',
  sections: [
    {
      id: 'identity',
      title: 'p.identity',
      fields: [
        { id: 'name', label: 'p.name', kind: 'text', required: true },
        {
          id: 'kind',
          label: 'p.kind',
          kind: 'select',
          defaultValue: 'individual',
          options: [
            { value: 'company', label: 'p.company' },
            { value: 'individual', label: 'p.individual' },
          ],
        },
        {
          id: 'identifier__siren',
          label: 'p.siren',
          kind: 'text',
          visibleWhen: { field: 'kind', oneOf: ['company'] },
        },
        { id: 'email', label: 'p.email', kind: 'email' },
        { id: 'city', label: 'p.city', kind: 'text' },
        { id: 'website', label: 'p.website', kind: 'text' },
        {
          id: 'taxRegime',
          label: 'p.regime',
          kind: 'select',
          defaultValue: 'standard',
          options: [
            { value: 'standard', label: 'p.standard' },
            { value: 'exempt', label: 'p.exempt' },
          ],
        },
        {
          id: 'taxes',
          label: 'p.taxes',
          kind: 'multiselect',
          options: [{ value: 't1', label: 'p.vat' }],
        },
      ],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-descriptor-form [descriptor]="descriptor" [form]="form" testId="party-form" />`,
})
class Host {
  readonly descriptor = party;
  readonly form = buildFormGroup(party);
  readonly shown = viewChild.required(DescriptorForm);
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      p: { identity: 'Identity', name: 'Name', email: 'Email', city: 'City', website: 'Website' },
      form: { optional: 'optional', errors: { required: 'Required.' } },
      import: {
        rejections: {
          invalid_email: 'E-mail refused.',
          invalid_text: 'Text refused: {{max}} characters at most.',
          above_due: 'At most what is still due, {{amount}}.',
          invalid_value: 'Value refused.',
          regime_excludes_tax: 'This regime does not charge that tax.',
          identifier_part: 'Part refused: {{part}} (example: {{example}}).',
          identifier_part_rule: 'Part refused: {{part}}, as {{rule}}.',
        },
        identifier_parts: { key: 'the key letter', category: 'the category code' },
        identifier_rules: { secondary_establishment: 'an establishment other than 000 is E' },
      },
    });
  }
}

describe('DescriptorForm, a save the API refused', () => {
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;
  const text = (testId: string): string | undefined => q(testId)?.textContent?.trim();

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
    fixture.componentInstance.form.controls['name']!.setValue('Carthage');
    fixture.componentInstance.form.controls['email']!.setValue('nobody@nowhere');
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  const refusals: readonly FieldRefusal[] = [
    { field: 'email', reason: 'invalid_email', params: {} },
    { field: 'city', reason: 'invalid_text', params: { max: 120 } },
    { field: 'website', reason: 'not_a_reason_the_screens_know', params: {} },
    { field: 'identifier__siren', reason: 'identifier_shape', params: {} },
    { field: 'nowhere', reason: 'invalid_value', params: {} },
  ];

  it('says each refusal under its own field, in the text a file is refused with', async () => {
    fixture.componentInstance.shown().showRefused(refusals);
    await settle();

    expect(text('field-error-email')).toBe('E-mail refused.');
    expect(text('field-error-city')).toBe('Text refused: 120 characters at most.');
    expect(text('field-error-website')).toBe('Value refused.');
  });

  it('says a refusal’s figures as the reader reads figures', async () => {
    vi.spyOn(TestBed.inject(RefusalFigures), 'written').mockImplementation((params) => ({
      ...params,
      amount: '1 250,500',
    }));

    fixture.componentInstance
      .shown()
      .showRefused([{ field: 'city', reason: 'above_due', params: { amount: '1250.5' } }]);
    await settle();

    expect(text('field-error-city')).toBe('At most what is still due, 1 250,500.');
  });

  it('says the part of a registration number the API names, and the rule it breaks, in the reader’s words', async () => {
    fixture.componentInstance.form.controls['kind']!.setValue('company');
    await settle();
    fixture.componentInstance.shown().showRefused([
      {
        field: 'identifier__siren',
        reason: 'identifier_part',
        params: { part: 'key', example: '1234567A/B/M/000' },
      },
    ]);
    await settle();
    expect(text('field-error-identifier__siren')).toBe(
      'Part refused: the key letter (example: 1234567A/B/M/000).',
    );

    fixture.componentInstance.shown().showRefused([
      {
        field: 'identifier__siren',
        reason: 'identifier_part_rule',
        params: { part: 'category', rule: 'secondary_establishment' },
      },
    ]);
    await settle();
    expect(text('field-error-identifier__siren')).toBe(
      'Part refused: the category code, as an establishment other than 000 is E.',
    );

    // A part the screens have no words for yet is said as the API named it, never left blank.
    fixture.componentInstance.shown().showRefused([
      {
        field: 'identifier__siren',
        reason: 'identifier_part',
        params: { part: 'branch', example: 'X' },
      },
    ]);
    await settle();
    expect(text('field-error-identifier__siren')).toBe('Part refused: branch (example: X).');
  });

  it('hands back what no field shown can carry, and puts the person on the first refused field', async () => {
    const unplaced = fixture.componentInstance.shown().showRefused(refusals);
    await settle();

    expect(unplaced.map((refusal) => refusal.field)).toEqual(['identifier__siren', 'nowhere']);
    expect(document.activeElement).toBe(q('field-email'));
  });

  it('says a refusal under a select and a multiselect too', async () => {
    fixture.componentInstance.shown().showRefused([
      { field: 'taxRegime', reason: 'invalid_value', params: {} },
      { field: 'taxes', reason: 'regime_excludes_tax', params: {} },
    ]);
    await settle();

    expect(text('field-error-taxRegime')).toBe('Value refused.');
    expect(text('field-error-taxes')).toBe('This regime does not charge that tax.');
  });

  // A refusal is often mended in another field (the regime, for taxes it does not charge): holding the save until
  // the refused field itself changed would leave the person no way to send the mended record.
  it('drops every refusal once anything in the form changes, wherever it was mended', async () => {
    const form = fixture.componentInstance.form;
    fixture.componentInstance.shown().showRefused([
      { field: 'taxes', reason: 'regime_excludes_tax', params: {} },
      { field: 'city', reason: 'invalid_text', params: { max: 120 } },
    ]);
    await settle();
    expect(form.invalid).toBe(true);

    form.controls['taxRegime']!.setValue('exempt');
    await settle();

    expect(q('field-error-taxes')).toBeNull();
    expect(q('field-error-city')).toBeNull();
    expect(form.valid).toBe(true);
  });

  it('holds the save until the refused field changes, and drops the refusal once it does', async () => {
    const form = fixture.componentInstance.form;
    fixture.componentInstance.shown().showRefused(refusals.slice(0, 1));
    await settle();
    expect(form.invalid).toBe(true);

    const input = q('field-email') as HTMLInputElement;
    input.value = 'someone@example.tn';
    input.dispatchEvent(new Event('input'));
    await settle();

    expect(q('field-error-email')).toBeNull();
    expect(form.valid).toBe(true);
  });
});
