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
import { Brand } from '../brand/brand';
import { LegalFooter } from './legal-footer';
import { LEGAL_PAGES } from './legal-pages';

// shared/ reads no feature's files, the translations included: the few strings this line shows, inline.
const fr = {
  legal: { navigation: 'Informations légales', pages: { mentions: 'Mentions légales' } },
};

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of(fr);
  }
}

// docs/SPEC.md § 7, 2026-09-26 08:52 (row 147): « © <year> <brand> · AGPL-3.0 · Mentions légales · … », a slim line.
describe('LegalFooter', () => {
  let fixture: ComponentFixture<LegalFooter>;
  const name = signal('Maison Kallel');

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [LegalFooter],
      providers: [
        provideRouter([]),
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: Brand, useValue: { name, tagline: signal('') } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(LegalFooter);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  });

  const el = () => fixture.nativeElement as HTMLElement;

  it('names this year and the installation’s own brand, never a literal', () => {
    const line = el().querySelector('[data-testid="legal-copyright"]');
    expect(line?.textContent?.trim()).toBe(`© ${new Date().getFullYear()} Maison Kallel`);

    name.set('Autre marque');
    fixture.detectChanges();
    expect(line?.textContent).toContain('Autre marque');
  });

  it('links the licence to the source page, as the AGPL asks of a network service', () => {
    const licence = el().querySelector('[data-testid="legal-licence"]');
    expect(licence?.textContent?.trim()).toBe('AGPL-3.0');
    expect(licence?.getAttribute('href')).toBe('/legal/source');
  });

  it('links every legal page by its title, in a navigation of its own', () => {
    const nav = el().querySelector('nav');
    expect(nav?.getAttribute('aria-label')).toBe('Informations légales');
    const links = [...el().querySelectorAll<HTMLAnchorElement>('[data-testid^="legal-link-"]')];
    expect(links.map((link) => link.getAttribute('href'))).toEqual(
      LEGAL_PAGES.map((slug) => `/legal/${slug}`),
    );
    expect(links[0].textContent?.trim()).toBe('Mentions légales');
  });
});
