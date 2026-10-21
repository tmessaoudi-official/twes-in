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
import { DescriptorForm, type PickSource } from './descriptor-form';
import { buildFormGroup } from './form-builder';
import type { FormDescriptor, FormValues } from './form-types';
import type { PickOption } from './pick-field';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: { fields: { vendorIds: 'Fournisseurs' }, sections: { buying: 'Achat' } },
      form: { optional: 'facultatif', remove_pick: 'Retirer {{name}}' },
    });
  }
}

const SFAX: PickOption = { id: 'v1', code: 'FRN-0001', name: 'Comptoir Sfax' };
const SOTUMAG: PickOption = { id: 'v2', code: 'FRN-0002', name: 'Sotumag' };

const descriptor: FormDescriptor = {
  id: 'product',
  sections: [
    {
      id: 'buying',
      title: 'products.sections.buying',
      fields: [{ id: 'vendorIds', label: 'products.fields.vendorIds', kind: 'picks', span: 2 }],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  template: `
    <app-descriptor-form
      [descriptor]="descriptor"
      [form]="form"
      [pickers]="{ vendorIds: source() }"
      testId="product-form"
      (submitted)="saved.set($event)"
    >
      <button type="submit" data-testid="save">ok</button>
    </app-descriptor-form>
  `,
})
class Host {
  readonly descriptor = descriptor;
  readonly form = buildFormGroup(descriptor, { vendorIds: ['v1'] });
  readonly saved = signal<FormValues | null>(null);
  readonly source = signal<PickSource>({
    search: async () => [SFAX, SOTUMAG],
    value: null,
    values: [SFAX],
  });
}

describe('DescriptorForm with a field holding several records', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const held = (): string[] =>
    Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>(
        '[data-testid^="field-vendorIds-held-"]',
      ),
    ).map((each) => each.textContent?.trim() ?? '');

  async function pick(text: string): Promise<void> {
    (q('field-vendorIds') as HTMLInputElement).dispatchEvent(new Event('focusin'));
    await settle();
    const option = Array.from(document.body.querySelectorAll<HTMLElement>('mat-option')).find(
      (each) => each.textContent?.trim() === text,
    );
    expect(option, text).toBeDefined();
    option!.click();
    await settle();
  }

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

  it('shows the records it holds by their code and name, under the form label above its box', () => {
    expect(held()).toEqual(['FRN-0001 · Comptoir Sfax']);
    const label = fixture.nativeElement.querySelector('label[for="product-form-vendorIds"]');
    expect(label?.textContent).toContain('Fournisseurs');
    expect(label?.getAttribute('for')).toBe((q('field-vendorIds') as HTMLInputElement).id);
  });

  it('adds a picked record after the others, once, and empties the box for the next', async () => {
    await pick('FRN-0002 · Sotumag');

    expect(host.form.getRawValue()['vendorIds']).toEqual(['v1', 'v2']);
    expect(held()).toEqual(['FRN-0001 · Comptoir Sfax', 'FRN-0002 · Sotumag']);
    expect((q('field-vendorIds') as HTMLInputElement).value).toBe('');

    await pick('FRN-0001 · Comptoir Sfax');
    expect(host.form.getRawValue()['vendorIds']).toEqual(['v1', 'v2']);
  });

  it('takes a record off through a button named after it, and submits what is left', async () => {
    const remove = q('field-vendorIds-remove-v1') as HTMLButtonElement;
    expect(remove.getAttribute('aria-label')).toBe('Retirer FRN-0001 · Comptoir Sfax');
    remove.click();
    await settle();

    expect(held()).toEqual([]);
    q('save')!.click();
    await settle();
    expect(host.saved()?.['vendorIds']).toEqual([]);
  });

  it('offers no box to add to whoever may not search the book, and still shows what is held', async () => {
    host.source.set({
      search: async () => [SOTUMAG],
      searchable: false,
      value: null,
      values: [SFAX],
    });
    await settle();

    expect(q('field-vendorIds')).toBeNull();
    expect(held()).toEqual(['FRN-0001 · Comptoir Sfax']);
  });

  it('says it holds none where nothing can be added, rather than leaving its label over nothing', async () => {
    host.form.controls['vendorIds']!.setValue([]);
    host.source.set({ search: async () => [], searchable: false, value: null, values: [] });
    await settle();

    expect(q('field-vendorIds-none')?.textContent?.trim()).toBe('—');
  });
});
