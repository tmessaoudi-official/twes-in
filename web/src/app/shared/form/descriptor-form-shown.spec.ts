// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { buildFormGroup } from './form-builder';
import { DescriptorForm } from './descriptor-form';
import type { FormDescriptor } from './form-types';

const named = (language: string) => ({
  id: `name-${language}`,
  label: 'd.name_in',
  labelParams: { language },
  removeLabel: 'd.remove_in',
  kind: 'text' as const,
});

const unit: FormDescriptor = {
  id: 'unit',
  sections: [
    { id: 'unit', title: 'd.unit', fields: [{ id: 'name', label: 'd.name', kind: 'text' }] },
    { id: 'names', title: 'd.names', fields: [named('fr'), named('en'), named('ar')] },
  ],
};

@Component({
  imports: [DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-descriptor-form
      [descriptor]="descriptor"
      [form]="form"
      [withheld]="withheld()"
      [removable]="removable()"
      [readOnly]="readOnly()"
      [notes]="notes"
      trailIn="names"
      (removed)="removed.push($event)"
    >
      <button type="button" formTrail data-testid="add">Add a language</button>
      <button type="submit" data-testid="save">Save</button>
    </app-descriptor-form>
  `,
})
class Host {
  readonly descriptor = unit;
  readonly form = buildFormGroup(unit, { 'name-fr': 'Pièce', 'name-ar': 'قطعة' });
  readonly withheld = signal<ReadonlySet<string>>(new Set(['name-en']));
  readonly removable = signal<ReadonlySet<string>>(new Set(['name-ar']));
  readonly readOnly = signal(false);
  readonly notes = { 'name-fr': 'd.documents' };
  readonly removed: string[] = [];
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      d: {
        name_in: 'Name — {{language}}',
        remove_in: 'Remove the name in {{language}}',
        documents: 'documents language',
      },
    });
  }
}

async function render() {
  const fixture = TestBed.createComponent(Host);
  await fixture.whenStable();
  const root = fixture.nativeElement as HTMLElement;
  return { fixture, root, host: fixture.componentInstance };
}

describe('DescriptorForm, the fields a screen shows of a section', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        provideTranslateService({ fallbackLang: 'en', lang: 'en' }),
        provideTranslateLoader(StaticLoader),
      ],
    });
  });

  it('leaves a withheld field off the screen and in the form, so nothing typed is lost', async () => {
    const { root, host } = await render();

    expect(root.querySelector('[data-testid="field-name-en"]')).toBeNull();
    expect(root.querySelector('[data-testid="field-name-fr"]')).not.toBeNull();
    expect(host.form.controls['name-en']).toBeDefined();
  });

  it('shows a withheld field once it holds a value: a value is never hidden', async () => {
    const { fixture, root, host } = await render();

    host.form.controls['name-en']?.setValue('Piece');
    await fixture.whenStable();

    expect(root.querySelector('[data-testid="field-name-en"]')).not.toBeNull();
  });

  it('offers to remove a removable field only, which empties it and says which', async () => {
    const { fixture, root, host } = await render();
    const remove = root.querySelector<HTMLButtonElement>('[data-testid="field-remove-name-ar"]');

    expect(root.querySelector('[data-testid="field-remove-name-fr"]')).toBeNull();
    expect(remove?.getAttribute('aria-label')).toBe('Remove the name in ar');
    remove?.click();
    await fixture.whenStable();

    expect(host.form.controls['name-ar']?.value).toBe('');
    expect(host.form.controls['name-ar']?.dirty).toBe(true);
    expect(host.removed).toEqual(['name-ar']);
  });

  it('offers to remove nothing on a form shown read-only', async () => {
    const { fixture, root, host } = await render();

    host.readOnly.set(true);
    await fixture.whenStable();

    expect(root.querySelector('[data-testid="field-remove-name-ar"]')).toBeNull();
  });

  it('closes the section with what the screen projects as formTrail', async () => {
    const { root } = await render();
    const add = root.querySelector('[data-testid="add"]');
    const sections = [...root.querySelectorAll('fieldset')];
    const last = sections[1]?.querySelector('[data-testid="field-name-ar"]');

    expect(add?.closest('fieldset')).toBe(sections[1]);
    expect(last && add ? last.compareDocumentPosition(add) : 0).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
    expect(root.querySelector('[data-testid="save"]')?.closest('fieldset')).toBeNull();
  });

  it('writes a field’s note after its label', async () => {
    const { root } = await render();
    const label = root.querySelector('label[for="descriptor-form-name-fr"]');

    expect(label?.textContent?.replace(/\s+/g, ' ').trim()).toContain(
      'Name — fr · documents language',
    );
  });
});
