// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateLoader, provideTranslateService } from '@ngx-translate/core';
import type { TranslateLoader } from '@ngx-translate/core';
import { of } from 'rxjs';
import { describe, expect, it, vi } from 'vitest';
import { CompanyEntry } from './company-entry';
import type { EntryAsked } from './company-entry-proof';
import { CompanyProofPanel } from './company-proof-panel';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ company_proof: { title: 'Entrer dans {{company}}' } });
  }
}

describe('CompanyProofPanel', () => {
  it('asks at once as the company opens, and again from its button', async () => {
    const asked = signal<EntryAsked | null>({
      companyId: 'c2',
      companyName: 'Globex',
      passkey: false,
      code: false,
    });
    const entry = { asked, prove: vi.fn(async () => false) };
    TestBed.configureTestingModule({
      imports: [CompanyProofPanel],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: CompanyEntry, useValue: entry },
      ],
    });
    const fixture = TestBed.createComponent(CompanyProofPanel);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;

    expect(entry.prove).toHaveBeenCalledExactlyOnceWith();
    expect(element.textContent).toContain('Entrer dans Globex');

    (element.querySelector('[data-testid="company-proof-prove"]') as HTMLButtonElement).click();
    expect(entry.prove).toHaveBeenLastCalledWith(true);
  });
});
