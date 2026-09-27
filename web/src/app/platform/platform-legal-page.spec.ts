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
import { Feedback } from '../shared/feedback/feedback';
import { formatDay, formatMoment } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import type { LegalLanguage } from '../shared/legal/legal-api';
import { LEGAL_PAGES, type LegalPage } from '../shared/legal/legal-pages';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { PlatformLegalFacade } from './platform-legal-facade';
import { PlatformLegalPage } from './platform-legal-page';
import type { LegalStatusRow, LegalVersionRow } from './platform-legal-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      legal: { pages: { mentions: 'Mentions légales', cookies: 'Cookies', security: 'Sécurité' } },
      platform: {
        legal: {
          title: 'Pages légales',
          none: 'Pas écrite',
          draft: 'Brouillon du {{date}}',
          validated: 'Validée, du {{date}}',
          body: 'Texte (Markdown)',
          preview: 'Aperçu',
          edit: 'Modifier',
          publish: 'Publier une nouvelle version',
          validate: 'Valider la dernière version',
          published: 'Nouvelle version publiée.',
          validated_toast: 'Version validée.',
          history: 'Versions',
          shipped: 'Livrée avec la plateforme',
          by: 'par {{who}}',
          validated_by: 'validée par {{who}}',
          reuse: 'Reprendre ce texte',
          identity: {
            title: 'Éditeur et hébergeur',
            save: 'Enregistrer',
            saved: 'Identité enregistrée.',
            publisher: { name: 'Nom de l’éditeur', address: 'Adresse' },
            host: { name: 'Hébergeur' },
          },
        },
      },
    });
  }
}

const version = (overrides: Partial<LegalVersionRow> = {}): LegalVersionRow => ({
  id: 'v1',
  body: '## Éditeur\n\ntwes',
  publishedOn: '2026-09-20',
  validated: false,
  validatedAt: null,
  validatedBy: null,
  createdAt: '2026-09-20T10:00:00+00:00',
  createdBy: 'op@twes.local',
  ...overrides,
});

function overviewWith(
  written: Partial<Record<`${LegalPage}.${LegalLanguage}`, Partial<LegalStatusRow>>>,
): LegalStatusRow[] {
  return LEGAL_PAGES.flatMap((page) =>
    (['fr', 'en', 'ar'] as const).map((language) => ({
      page,
      language,
      written: false,
      publishedOn: null,
      validated: false,
      ...written[`${page}.${language}`],
    })),
  );
}

// docs/SPEC.md § 8 row 148: the operator writes each legal page per language, and validates the latest version.
describe('PlatformLegalPage', () => {
  let fixture: ComponentFixture<PlatformLegalPage>;
  let facade: {
    overview: ReturnType<typeof signal<readonly LegalStatusRow[]>>;
    versions: ReturnType<typeof signal<readonly LegalVersionRow[]>>;
    busy: ReturnType<typeof signal<boolean>>;
    failed: ReturnType<typeof signal<boolean>>;
    identity: ReturnType<typeof signal<Readonly<Record<string, string>>>>;
    load: ReturnType<typeof vi.fn>;
    saveIdentity: ReturnType<typeof vi.fn>;
    open: ReturnType<typeof vi.fn>;
    write: ReturnType<typeof vi.fn>;
    validate: ReturnType<typeof vi.fn>;
  };
  let histories: Record<string, LegalVersionRow[]>;

  async function render(): Promise<void> {
    await TestBed.configureTestingModule({
      imports: [PlatformLegalPage],
      providers: [
        provideRouter([]),
        provideQuietFeedback(),
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: PlatformLegalFacade, useValue: facade },
        {
          provide: FormatFacade,
          useValue: {
            day: (value: string) => formatDay(value, 'fr'),
            moment: (value: string) => formatMoment(value, 'fr', 'Europe/Paris'),
          },
        },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(PlatformLegalPage);
    await settle();
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    histories = { 'mentions.fr': [version()] };
    facade = {
      overview: signal<readonly LegalStatusRow[]>(
        overviewWith({ 'mentions.fr': { written: true, publishedOn: '2026-09-20' } }),
      ),
      versions: signal<readonly LegalVersionRow[]>([]),
      busy: signal(false),
      failed: signal(false),
      // Every legal.* setting, in the API's order, the empty ones included: the form's fields.
      identity: signal<Readonly<Record<string, string>>>({
        'publisher.name': 'twes SAS',
        'host.name': '',
      }),
      load: vi.fn(async () => undefined),
      saveIdentity: vi.fn(async () => true),
      open: vi.fn(async (page: string, language: string) => {
        facade.versions.set(histories[`${page}.${language}`] ?? []);
      }),
      write: vi.fn(async () => true),
      validate: vi.fn(async () => true),
    };
  });

  const q = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(`[data-testid="${id}"]`);
  const body = () => q('legal-editor-body') as HTMLTextAreaElement;
  const type = async (text: string) => {
    body().value = text;
    body().dispatchEvent(new Event('input'));
    await settle();
  };
  const said = () => (TestBed.inject(Feedback) as RecordedFeedback).said;

  // A text typed before the page's history arrived was replaced by the latest version when it did (CI, 2026-09-27).
  it("keeps the editor closed to typing until the page's history has arrived", async () => {
    let arrive: () => void = () => undefined;
    facade.open = vi.fn(
      (page: string, language: string) =>
        new Promise<void>((resolve) => {
          arrive = () => {
            facade.versions.set(histories[`${page}.${language}`] ?? []);
            resolve();
          };
        }),
    );
    await render();

    q('legal-cell-mentions-fr')!.click();
    await settle();
    expect(body().disabled).toBe(true);

    arrive();
    await settle();
    expect(body().disabled).toBe(false);
    expect(body().value).toBe('## Éditeur\n\ntwes');
  });

  it('lists every page in every language with where it stands', async () => {
    facade.overview.set(
      overviewWith({
        'mentions.fr': { written: true, publishedOn: '2026-09-20' },
        'cookies.en': { written: true, publishedOn: '2026-09-21', validated: true },
      }),
    );
    await render();
    expect(facade.load).toHaveBeenCalled();
    expect(fixture.nativeElement.querySelectorAll('[data-testid^="legal-cell-"]').length).toBe(27);
    expect(q('legal-cell-mentions-fr')?.textContent).toContain('Brouillon du 20/09/2026');
    expect(q('legal-cell-cookies-en')?.textContent).toContain('Validée, du 21/09/2026');
    expect(q('legal-cell-security-ar')?.textContent).toContain('Pas écrite');
  });

  it('opens a page in a language with its latest version in the editor, laid out in that language', async () => {
    histories['cookies.ar'] = [version({ body: 'نص' })];
    await render();
    q('legal-cell-cookies-ar')!.click();
    await settle();
    expect(facade.open).toHaveBeenCalledWith('cookies', 'ar');
    expect(body().value).toBe('نص');
    expect(body().getAttribute('dir')).toBe('rtl');
    expect(body().getAttribute('lang')).toBe('ar');
  });

  it('publishes what was typed as a new version, and only once something changed', async () => {
    await render();
    q('legal-cell-mentions-fr')!.click();
    await settle();
    expect((q('legal-publish') as HTMLButtonElement).disabled).toBe(true);
    await type('## Éditeur\n\ntwes SAS');
    expect((q('legal-publish') as HTMLButtonElement).disabled).toBe(false);
    q('legal-publish')!.click();
    await settle();
    expect(facade.write).toHaveBeenCalledWith('## Éditeur\n\ntwes SAS');
    expect(said()).toContainEqual(expect.objectContaining({ key: 'platform.legal.published' }));
  });

  it('validates the latest version while it is a draft, and says so', async () => {
    await render();
    q('legal-cell-mentions-fr')!.click();
    await settle();
    q('legal-validate')!.click();
    await settle();
    expect(facade.validate).toHaveBeenCalled();
    expect(said()).toContainEqual(
      expect.objectContaining({ key: 'platform.legal.validated_toast' }),
    );

    histories['mentions.fr'] = [version({ validated: true, validatedBy: 'op@twes.local' })];
    q('legal-cell-cookies-fr')!.click();
    await settle();
    q('legal-cell-mentions-fr')!.click();
    await settle();
    expect(q('legal-validate')).toBeNull();
  });

  it('previews the text as the public page renders it, never running what it carries', async () => {
    await render();
    q('legal-cell-mentions-fr')!.click();
    await settle();
    await type('## Titre\n\n<script>window.pwned = true</script>');
    q('legal-preview-toggle')!.click();
    await settle();
    expect(q('legal-body')?.querySelector('h2')?.textContent).toBe('Titre');
    expect(q('legal-body')?.querySelector('script')).toBeNull();
  });

  it('keeps what was typed for a page when another is opened and it is opened again', async () => {
    await render();
    q('legal-cell-mentions-fr')!.click();
    await settle();
    await type('pas encore publié');
    q('legal-cell-cookies-fr')!.click();
    await settle();
    expect(body().value).toBe('');
    q('legal-cell-mentions-fr')!.click();
    await settle();
    expect(body().value).toBe('pas encore publié');
  });

  it('fills in the publisher and host the legal texts name, and saves only what changed', async () => {
    await render();
    const name = q('legal-identity-publisher.name') as HTMLInputElement;
    const host = q('legal-identity-host.name') as HTMLInputElement;
    expect(name.value).toBe('twes SAS');
    expect(host.value).toBe('');
    host.value = 'OVH SAS';
    host.dispatchEvent(new Event('input'));
    await settle();
    q('legal-identity-save')!.click();
    await settle();
    expect(facade.saveIdentity).toHaveBeenCalledWith({ 'host.name': 'OVH SAS' });
    expect(said()).toContainEqual(
      expect.objectContaining({ key: 'platform.legal.identity.saved' }),
    );
  });

  it('previews a text with the publisher filled in, as the public page will show it', async () => {
    await render();
    q('legal-cell-mentions-fr')!.click();
    await settle();
    await type('Éditeur : {{publisher.name}}. Hébergeur : {{host.name}}.');
    q('legal-preview-toggle')!.click();
    await settle();
    expect(q('legal-body')?.textContent).toContain(
      'Éditeur : twes SAS. Hébergeur : [à compléter].',
    );
  });

  it('lists the versions, the latest first, and takes an older one back into the editor', async () => {
    histories['mentions.fr'] = [
      version({ id: 'v2', body: 'second', createdBy: null }),
      version({ id: 'v1', body: 'first', validated: true, validatedBy: 'op@twes.local' }),
    ];
    await render();
    q('legal-cell-mentions-fr')!.click();
    await settle();
    const rows = [
      ...fixture.nativeElement.querySelectorAll('[data-testid="legal-version"]'),
    ] as HTMLElement[];
    expect(rows.length).toBe(2);
    expect(rows[0].textContent).toContain('Livrée avec la plateforme');
    expect(rows[1].textContent).toContain('validée par op@twes.local');
    (rows[1].querySelector('[data-testid="legal-reuse"]') as HTMLElement).click();
    await settle();
    expect(body().value).toBe('first');
  });
});
