// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { UnreadParts } from './unread-parts';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      unread: {
        custom_fields: 'The custom fields could not be read (code: {{code}}).',
        customer_groups: 'The customer groups could not be read (code: {{code}}).',
      },
    });
  }
}

describe('UnreadParts', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ fallbackLang: 'en', lang: 'en' }),
        provideTranslateLoader(StaticLoader),
      ],
    });
  });

  it('says each part a screen could not read in its own line, with its code, offering no retry', async () => {
    const fixture = TestBed.createComponent(UnreadParts);
    fixture.componentRef.setInput('testId', 'customers-unread');
    fixture.componentRef.setInput('parts', [
      { unread: 'customer_groups', code: 'quota_spent' },
      { unread: 'custom_fields', code: 'not_found' },
    ]);
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;

    expect(
      root.querySelector('[data-testid="customers-unread-customer_groups"]')?.textContent,
    ).toContain('The customer groups could not be read (code: quota_spent).');
    expect(
      root.querySelector('[data-testid="customers-unread-custom_fields"]')?.textContent,
    ).toContain('The custom fields could not be read (code: not_found).');
    expect(root.querySelectorAll('button')).toHaveLength(0);
  });

  it('draws nothing when every part was read', async () => {
    const fixture = TestBed.createComponent(UnreadParts);
    fixture.componentRef.setInput('testId', 'customers-unread');
    fixture.componentRef.setInput('parts', []);
    await fixture.whenStable();

    expect((fixture.nativeElement as HTMLElement).querySelectorAll('[role="alert"]')).toHaveLength(
      0,
    );
  });
});
