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

const customer: FormDescriptor = {
  id: 'customer',
  sections: [
    {
      id: 'identity',
      title: 'c.identity',
      fields: [
        { id: 'name', label: 'c.name', kind: 'text' },
        {
          id: 'group',
          label: 'c.group',
          kind: 'select',
          options: [{ value: '', label: 'c.no_group' }],
          unread: { unread: 'customer_groups', code: 'quota_spent' },
        },
      ],
    },
    {
      id: 'custom',
      title: 'c.custom',
      fields: [],
      unread: { unread: 'custom_fields', code: 'not_found' },
    },
  ],
};

@Component({
  imports: [DescriptorForm],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-descriptor-form [descriptor]="descriptor" [form]="form" />`,
})
class Host {
  readonly descriptor = customer;
  readonly form = buildFormGroup(customer);
}

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      c: { identity: 'Identity', custom: 'Custom fields', name: 'Name', group: 'Group' },
      unread: {
        custom_fields: 'The custom fields could not be read (code: {{code}}).',
        customer_groups: 'The customer groups could not be read (code: {{code}}).',
      },
    });
  }
}

describe('DescriptorForm, a part another feature could not read', () => {
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

  async function render(): Promise<HTMLElement> {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    return fixture.nativeElement as HTMLElement;
  }

  it('says a section it could not read in place of its fields, under its own heading', async () => {
    const root = await render();
    const line = root.querySelector('[data-testid="section-unread-custom"]');
    const sections = [...root.querySelectorAll('fieldset')];

    expect(line?.textContent).toContain('The custom fields could not be read (code: not_found).');
    expect(line?.closest('fieldset')).toBe(sections[1]);
    expect(line?.getAttribute('role')).toBe('alert');
  });

  it('says a field whose choices could not be read under that field, inside its own place in the grid', async () => {
    const root = await render();
    const line = root.querySelector('[data-testid="field-unread-group"]');

    expect(line?.textContent).toContain(
      'The customer groups could not be read (code: quota_spent).',
    );
    expect(line?.closest('[data-testid="field-wrapper-group"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="field-unread-name"]')).toBeNull();
  });

  it('offers no « Réessayer » there: the sentence says to reload, which keeps what was typed held by the unsaved count', async () => {
    const root = await render();

    expect(root.querySelector('[data-testid="section-unread-custom-retry"]')).toBeNull();
    expect(root.querySelector('[data-testid="field-unread-group-retry"]')).toBeNull();
  });
});
