// SPDX-License-Identifier: AGPL-3.0-or-later

import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { LegalDialog } from './legal-dialog';
import { STORED_ITEMS } from './stored-items';

// shared/ reads no feature's files, the translations included: the strings this panel shows, inline.
const fr = {
  legal: {
    pages: { cookies: 'Cookies', mentions: 'Mentions légales' },
    draft: 'Brouillon — à faire valider',
    drafting: 'Le texte de cette page est en cours de rédaction.',
    close: 'Fermer',
    as_page: 'Ouvrir en pleine page',
    stored: { title: 'Ce que ce service garde', third_party: 'Aucun script tiers.' },
  },
};

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of(fr);
  }
}

describe('LegalDialog', () => {
  let fixture: ComponentFixture<LegalDialog>;
  const close = vi.fn();

  async function open(slug: string): Promise<void> {
    await TestBed.configureTestingModule({
      imports: [LegalDialog],
      providers: [
        provideRouter([]),
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: MAT_DIALOG_DATA, useValue: { slug } },
        { provide: MatDialogRef, useValue: { close } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(LegalDialog);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const q = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(`[data-testid="${id}"]`);

  it('titles the panel with the page and shows its text, the stored list on Cookies', async () => {
    await open('cookies');
    expect(q('legal-title')?.textContent?.trim()).toBe('Cookies');
    expect(q('legal-draft')?.textContent?.trim()).toBe('Brouillon — à faire valider');
    expect(fixture.nativeElement.querySelectorAll('[data-testid="stored-row"]').length).toBe(
      STORED_ITEMS.length,
    );
  });

  it('closes back onto the screen it was opened over, and offers the page itself for a tab of its own', async () => {
    await open('mentions');
    expect(q('stored-items')).toBeNull();
    expect(q('legal-as-page')?.getAttribute('href')).toBe('/legal/mentions');
    q('legal-close')?.click();
    expect(close).toHaveBeenCalled();
  });
});
