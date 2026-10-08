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
import type { FormDescriptor } from './form-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ form: { optional: 'facultatif' } });
  }
}

const descriptor: FormDescriptor = {
  id: 'product',
  sections: [
    {
      id: 'description',
      title: 'Description',
      fields: [
        { id: 'substitutionGroup', label: 'Groupe', kind: 'text' },
        { id: 'name', label: 'Nom', kind: 'text' },
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
      [suggestions]="{ substitutionGroup: groups() }"
      testId="product-form"
    />
  `,
})
class Host {
  readonly descriptor = descriptor;
  readonly form = buildFormGroup(descriptor, { substitutionGroup: '', name: '' });
  readonly groups = signal<readonly string[]>(['Vis 6 mm', 'Vis 8 mm', 'Chevilles']);
}

/**
 * A text field may offer what is already in use, so a person joins a group as it is written rather than starting a
 * second one spelled another way; anything else can still be typed.
 */
describe('DescriptorForm with suggestions on a text field', () => {
  let fixture: ComponentFixture<Host>;
  let host: Host;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const input = (testId: string): HTMLInputElement =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`) as HTMLInputElement;
  const offered = (): string[] =>
    Array.from(document.body.querySelectorAll('mat-option')).map(
      (option) => option.textContent?.trim() ?? '',
    );

  async function typeIn(testId: string, value: string): Promise<void> {
    const field = input(testId);
    field.dispatchEvent(new Event('focusin'));
    field.value = value;
    field.dispatchEvent(new Event('input'));
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

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('offers the values in use that hold what is typed, whatever its case', async () => {
    await typeIn('field-substitutionGroup', 'vis');

    expect(offered()).toEqual(['Vis 6 mm', 'Vis 8 mm']);
  });

  it('writes the value picked into the field', async () => {
    await typeIn('field-substitutionGroup', 'chev');
    (document.body.querySelector('mat-option') as HTMLElement).click();
    await settle();

    expect(host.form.get('substitutionGroup')?.value).toBe('Chevilles');
  });

  it('keeps a value nobody uses yet, as typed', async () => {
    await typeIn('field-substitutionGroup', 'Écrous M6');

    expect(offered()).toEqual([]);
    expect(host.form.get('substitutionGroup')?.value).toBe('Écrous M6');
  });

  it('offers nothing on a field it has no suggestions for', async () => {
    await typeIn('field-name', 'vis');

    expect(offered()).toEqual([]);
  });

  it('offers a value no longer when it is the one already typed', async () => {
    await typeIn('field-substitutionGroup', 'Vis 6 mm');

    expect(offered()).toEqual([]);
  });
});
