// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import { Brand } from '../shared/brand/brand';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { LEGAL_PAGES } from '../shared/legal/legal-pages';
import { STORED_ITEMS } from '../shared/legal/stored-items';
import { LegalPage } from './legal-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of(fr);
  }
}

// docs/SPEC.md § 7, 2026-09-26 08:52 (rows 147 and 148): each legal page at /legal/<slug>, signed in or not.
describe('LegalPage', () => {
  let fixture: ComponentFixture<LegalPage>;

  async function open(slug: string): Promise<void> {
    await TestBed.configureTestingModule({
      imports: [LegalPage],
      providers: [
        provideRouter([]),
        provideQuietFeedback(),
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: Brand, useValue: { name: signal('twes-in'), tagline: signal('') } },
        { provide: ThemeFacade, useValue: { preference: signal('auto'), setScheme: vi.fn() } },
        { provide: LanguageFacade, useValue: { current: signal('fr'), use: vi.fn() } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(LegalPage);
    fixture.componentRef.setInput('slug', slug);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const q = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(`[data-testid="${id}"]`);

  it('titles the page and says its text is still a draft', async () => {
    await open('mentions');
    expect(q('legal-title')?.textContent?.trim()).toBe('Mentions légales');
    expect(q('legal-draft')?.textContent?.trim()).toBe('Brouillon — à faire valider');
    // The line of legal links closes the page, as on every other.
    expect(q('legal-footer')).not.toBeNull();
  });

  it('lists on the Cookies page everything stored on the device, from the one declaration the gate checks (row 149)', async () => {
    await open('cookies');
    const rows = [
      ...fixture.nativeElement.querySelectorAll('[data-testid="stored-row"]'),
    ] as HTMLElement[];
    expect(rows.map((row) => row.querySelector('code')?.textContent?.trim())).toEqual(
      STORED_ITEMS.map((item) => item.name),
    );
    expect(rows[0].textContent).toContain('Cookie');
    expect(rows[0].textContent).toContain('Strictement nécessaire');
    expect(q('stored-third-party')?.textContent).toContain('Aucun script tiers');
  });

  it('keeps that list to the Cookies page', async () => {
    await open('mentions');
    expect(q('stored-items')).toBeNull();
  });

  it('names every stored item, where it is kept and how long, in both languages', () => {
    for (const json of [fr, en]) {
      const stored = (
        json as unknown as {
          legal: { stored: Record<'purposes' | 'kinds' | 'durations', Record<string, string>> };
        }
      ).legal.stored;
      for (const item of STORED_ITEMS) {
        expect(stored.purposes[item.id], item.id).toBeTruthy();
        expect(stored.kinds[item.kind], item.kind).toBeTruthy();
        expect(stored.durations[item.lasts], item.lasts).toBeTruthy();
      }
    }
  });

  it('has every page title in both languages', () => {
    for (const json of [fr, en]) {
      const pages = (json as unknown as { legal: { pages: Record<string, string> } }).legal.pages;
      for (const slug of LEGAL_PAGES) expect(pages[slug], slug).toBeTruthy();
    }
  });

  it('says a page that is not one of the nine does not exist', async () => {
    await open('nowhere');
    expect(q('legal-title')).toBeNull();
    expect(q('legal-unknown')?.textContent).toContain('Cette page n’existe pas.');
    expect(q('legal-unknown')?.querySelector('a')?.getAttribute('href')).toBe('/');
  });
});
