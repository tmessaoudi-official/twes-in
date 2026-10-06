// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
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

const document: FormDescriptor = {
  id: 'document',
  sections: [
    {
      id: 'parties',
      title: 'd.parties',
      fields: [{ id: 'reference', label: 'd.reference', kind: 'text' }],
    },
    {
      id: 'dates',
      title: 'd.dates',
      fields: [{ id: 'due', label: 'd.due', kind: 'text' }],
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-descriptor-form [descriptor]="descriptor" [form]="form" leadIn="parties">
      <div formLead data-testid="lead">Who it is for</div>
      <button type="submit" data-testid="save">Save</button>
    </app-descriptor-form>
  `,
})
class Host {
  readonly descriptor = document;
  readonly form = buildFormGroup(document);
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ d: { parties: 'Customer and place', dates: 'Dates' } });
  }
}

describe('DescriptorForm, a field the screen keeps itself', () => {
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

  it('sits under the heading of the section it belongs to, before that section’s own fields', async () => {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;
    const lead = root.querySelector('[data-testid="lead"]');
    const sections = [...root.querySelectorAll('fieldset')];

    expect(lead).not.toBeNull();
    expect(lead?.closest('fieldset')).toBe(sections[0]);
    const title = sections[0]?.querySelector('.twes-form-section-title');
    const firstField = sections[0]?.querySelector('input');
    expect(title && lead ? title.compareDocumentPosition(lead) : 0).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
    expect(lead && firstField ? lead.compareDocumentPosition(firstField) : 0).toBe(
      Node.DOCUMENT_POSITION_FOLLOWING,
    );
  });

  it('leaves the screen’s buttons after the sections, where they were', async () => {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const save = (fixture.nativeElement as HTMLElement).querySelector('[data-testid="save"]');

    expect(save?.closest('fieldset')).toBeNull();
    expect(save?.closest('form')).not.toBeNull();
  });
});
