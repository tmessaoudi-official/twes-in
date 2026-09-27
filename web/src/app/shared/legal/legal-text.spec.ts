// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of, throwError, type Observable } from 'rxjs';
import { LanguageFacade } from '../i18n/language-facade';
import { LegalApi, type LegalDocument, type LegalLanguage } from './legal-api';
import { LegalText } from './legal-text';

// shared/ reads no feature's files, the translations included: the strings this text shows, inline.
const fr = {
  legal: {
    draft: 'Brouillon — à faire valider',
    drafting: 'Le texte de cette page est en cours de rédaction.',
    language: 'Langue du texte',
    version: 'Version du {{date}}',
    fallback:
      'Cette page n’est pas encore écrite dans cette langue : voici sa version en {{language}}.',
    unavailable: 'Le texte de cette page ne peut pas être lu pour le moment.',
    stored: { title: 'Ce que ce service garde', third_party: 'Aucun script tiers.' },
  },
};

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of(fr);
  }
}

const doc = (overrides: Partial<LegalDocument> = {}): LegalDocument => ({
  page: 'cookies',
  language: 'fr',
  body: '## Ce qu’il n’y a pas\n\nAucun script tiers.',
  publishedOn: '2026-09-20',
  validated: false,
  values: {},
  ...overrides,
});

// docs/SPEC.md § 8 row 148: each legal page shows its latest version from the API, in Markdown, per language.
describe('LegalText', () => {
  let fixture: ComponentFixture<LegalText>;
  let read: ReturnType<
    typeof vi.fn<(page: string, language: LegalLanguage) => Observable<LegalDocument | null>>
  >;

  async function show(
    slug: string,
    answer: (language: LegalLanguage) => Observable<LegalDocument | null>,
  ) {
    read = vi.fn((_page: string, language: LegalLanguage) => answer(language));
    await TestBed.configureTestingModule({
      imports: [LegalText],
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: LanguageFacade, useValue: { current: signal('fr') } },
        { provide: LegalApi, useValue: { read } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(LegalText);
    fixture.componentRef.setInput('slug', slug);
    await settle();
  }

  async function settle() {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const q = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(`[data-testid="${id}"]`);

  it('renders the version the API answers, in the interface language, dated and marked a draft until validated', async () => {
    await show('mentions', () => of(doc({ page: 'mentions' })));
    expect(read).toHaveBeenCalledWith('mentions', 'fr');
    expect(q('legal-body')?.querySelector('h2')?.textContent).toBe('Ce qu’il n’y a pas');
    expect(q('legal-body')?.querySelector('p')?.textContent).toBe('Aucun script tiers.');
    expect(q('legal-version')?.textContent).toContain('20/09/2026');
    expect(q('legal-draft')?.textContent?.trim()).toBe('Brouillon — à faire valider');
  });

  it('shows no draft mark on a validated version', async () => {
    await show('mentions', () => of(doc({ validated: true })));
    expect(q('legal-body')).not.toBeNull();
    expect(q('legal-draft')).toBeNull();
  });

  it('never runs what a text carries: no script, no event handler, no javascript: link', async () => {
    const body = [
      '<script>window.pwned = true</script>',
      '<img src="x" onerror="window.pwned = true">',
      '[clic](javascript:window.pwned=true)',
      '<a href="javascript:window.pwned=true">lien</a>',
    ].join('\n\n');
    await show('mentions', () => of(doc({ body })));
    const rendered = q('legal-body')!;
    expect(rendered.querySelector('script')).toBeNull();
    expect(rendered.querySelector('[onerror]')).toBeNull();
    const links = [...rendered.querySelectorAll('a')].map((a) => a.getAttribute('href') ?? '');
    expect(links.length).toBeGreaterThan(0);
    expect(links.filter((href) => href.trim().toLowerCase().startsWith('javascript:'))).toEqual([]);
    expect((window as { pwned?: boolean }).pwned).toBeUndefined();
  });

  it('switches language, and lays the text out right to left in Arabic', async () => {
    await show('mentions', (language) => of(doc({ language, body: `texte ${language}` })));
    q('legal-language-ar')!.click();
    await settle();
    expect(read).toHaveBeenLastCalledWith('mentions', 'ar');
    expect(q('legal-body')?.getAttribute('lang')).toBe('ar');
    expect(q('legal-body')?.getAttribute('dir')).toBe('rtl');
    expect(q('legal-language-ar')?.getAttribute('aria-pressed')).toBe('true');
    // The line around the date is in the interface's language, so the date is written in it too: an Arabic one would
    // carry right-to-left marks into a left-to-right sentence and scramble it.
    expect(q('legal-version')?.textContent?.trim()).toBe('Version du 20/09/2026');
  });

  it('says so when the page is not written in the language asked for and another answers', async () => {
    await show('mentions', () => of(doc({ language: 'fr' })));
    q('legal-language-ar')!.click();
    await settle();
    expect(q('legal-body')?.getAttribute('dir')).toBe('ltr');
    expect(q('legal-fallback')?.textContent).toContain('Français');
  });

  it('fills in the publisher the operator named, and marks what is not filled in, in the text’s own language', async () => {
    await show('mentions', (language) =>
      of(
        doc({
          language,
          body: 'Éditeur : {{publisher.name}}. Hébergeur : {{ host.name }}.',
          values: { 'publisher.name': 'twes SAS' },
        }),
      ),
    );
    expect(q('legal-body')?.textContent).toContain(
      'Éditeur : twes SAS. Hébergeur : [à compléter].',
    );
    q('legal-language-ar')!.click();
    await settle();
    expect(q('legal-body')?.textContent).toContain('Hébergeur : [يُستكمل].');
  });

  it('says a page never written is being written', async () => {
    await show('mentions', () => of(null));
    expect(q('legal-body')).toBeNull();
    expect(q('legal-drafting')?.textContent?.trim()).toBe(
      'Le texte de cette page est en cours de rédaction.',
    );
  });

  it('says the text cannot be read when the API does not answer', async () => {
    await show('mentions', () => throwError(() => new Error('down')));
    expect(q('legal-body')).toBeNull();
    expect(q('legal-unavailable')).not.toBeNull();
  });

  it('keeps the list of what is stored under the Cookies text', async () => {
    await show('cookies', () => of(doc()));
    expect(q('legal-body')).not.toBeNull();
    expect(q('stored-items')).not.toBeNull();
  });
});
