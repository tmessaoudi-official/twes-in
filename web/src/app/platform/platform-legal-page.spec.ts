// SPDX-License-Identifier: AGPL-3.0-or-later

import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateCompiler,
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Feedback } from '../shared/feedback/feedback';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { formatDay, formatMoment } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { PluralCompiler } from '../shared/i18n/plural-compiler';
import type { LegalLanguage } from '../shared/legal/legal-api';
import { LEGAL_PAGES, type LegalPage } from '../shared/legal/legal-pages';
import { provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import { PlatformLegalFacade } from './platform-legal-facade';
import { PlatformLegalPage } from './platform-legal-page';
import type { LegalStatusRow, LegalVersionRow } from './platform-legal-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      import: {
        rejections: {
          invalid_email:
            'Une adresse e-mail s’écrit nom@domaine.extension (exemple : compta@exemple.tn).',
          invalid_text: 'Au plus {{max}} caractères.',
          phone_length:
            'Ce numéro n’a pas le nombre de chiffres d’un téléphone de son pays (exemple\u00a0: {{example}}).',
          invalid_value: 'Valeur refusée.',
        },
      },
      legal: { pages: { mentions: 'Mentions légales', cookies: 'Cookies', security: 'Sécurité' } },
      platform: {
        legal: {
          title: 'Pages légales',
          texts: 'Textes',
          language: 'Langue',
          waiting:
            '{{count}} {count, plural, one {texte à faire valider} other {textes à faire valider}}',
          all_validated: 'Tous les textes sont validés',
          to_validate: '{{count}} à valider',
          others: 'Dans les autres langues',
          others_waiting: '{{count}} à valider\u00a0:',
          others_done: 'Validée partout',
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
            publisher: {
              name: 'Nom de l’éditeur',
              address: 'Adresse',
              email: 'E-mail',
              phone: 'Téléphone',
            },
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
    identityRefusals: ReturnType<typeof signal<readonly FieldRefusal[]>>;
    load: ReturnType<typeof vi.fn>;
    saveIdentity: ReturnType<typeof vi.fn>;
    open: ReturnType<typeof vi.fn>;
    write: ReturnType<typeof vi.fn>;
    validate: ReturnType<typeof vi.fn>;
  };
  let histories: Record<string, LegalVersionRow[]>;

  async function render(lang = 'fr'): Promise<void> {
    await TestBed.configureTestingModule({
      imports: [PlatformLegalPage],
      providers: [
        provideRouter([]),
        provideQuietFeedback(),
        provideTranslateService({
          lang,
          fallbackLang: 'fr',
          compiler: provideTranslateCompiler(PluralCompiler),
        }),
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
        'publisher.email': '',
        'publisher.phone': '',
        'host.name': '',
      }),
      identityRefusals: signal<readonly FieldRefusal[]>([]),
      load: vi.fn(async () => undefined),
      saveIdentity: vi.fn(async () => 'saved'),
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
  /** The table's language, chosen in its dropdown, whose list opens in an overlay outside the page. */
  const showIn = async (language: LegalLanguage) => {
    q('legal-overview-language')!.click();
    await settle();
    document
      .querySelector<HTMLElement>(`[data-testid="legal-overview-language-${language}"]`)!
      .click();
    await settle();
  };

  afterEach(() => {
    document.querySelectorAll('.cdk-overlay-container').forEach((node) => node.remove());
  });

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

  // Languages grow: one row per page whatever their number, the chosen language's status and what waits in the others.
  it('lists every page with where it stands in the chosen language, the screen one first', async () => {
    facade.overview.set(
      overviewWith({
        'mentions.fr': { written: true, publishedOn: '2026-09-20' },
        'cookies.en': { written: true, publishedOn: '2026-09-21', validated: true },
      }),
    );
    await render();
    expect(facade.load).toHaveBeenCalled();
    const cells = () => fixture.nativeElement.querySelectorAll('[data-testid^="legal-cell-"]');
    expect(cells().length).toBe(LEGAL_PAGES.length);
    expect(q('legal-overview')?.querySelectorAll('thead th').length).toBe(3);
    expect(q('legal-cell-mentions-fr')?.textContent).toContain('Brouillon du 20/09/2026');

    await showIn('en');
    expect(cells().length).toBe(LEGAL_PAGES.length);
    expect(q('legal-cell-cookies-en')?.textContent).toContain('Validée, du 21/09/2026');
    expect(q('legal-cell-mentions-fr')).toBeNull();

    await showIn('ar');
    expect(q('legal-cell-security-ar')?.textContent).toContain('Pas écrite');
  });

  it('opens the table on the screen language when the legal pages are written in it', async () => {
    await render('en');
    expect(q('legal-cell-mentions-en')).not.toBeNull();
    expect(q('legal-cell-mentions-fr')).toBeNull();
  });

  it('names, for each page, the other languages it waits to be validated in, each in its own language', async () => {
    facade.overview.set(
      overviewWith({
        'cookies.en': { written: true, publishedOn: '2026-09-21', validated: true },
        'cookies.ar': { written: true, publishedOn: '2026-09-21', validated: true },
        'mentions.ar': { written: true, publishedOn: '2026-09-21', validated: true },
      }),
    );
    await render();
    expect(q('legal-others-security')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      '2 à valider : English et العربية',
    );
    expect(q('legal-others-security')?.querySelector('bdi[lang="ar"]')?.textContent).toBe(
      'العربية',
    );
    expect(q('legal-others-mentions')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      '1 à valider : English',
    );
    expect(q('legal-others-cookies')?.textContent?.trim()).toBe('Validée partout');

    await showIn('en');
    expect(q('legal-others-cookies')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      '1 à valider : Français',
    );
  });

  it('counts in the dropdown what waits to be validated in each language, and in all of them', async () => {
    facade.overview.set(
      overviewWith(
        Object.fromEntries(
          LEGAL_PAGES.map((page) => [
            `${page}.ar`,
            { written: true, publishedOn: '2026-09-21', validated: page !== 'cookies' },
          ]),
        ),
      ),
    );
    await render();
    expect(q('legal-waiting')?.textContent?.trim()).toBe(
      `${2 * LEGAL_PAGES.length + 1} textes à faire valider`,
    );
    q('legal-overview-language')!.click();
    await settle();
    const count = (language: string) =>
      document
        .querySelector(
          `[data-testid="legal-overview-language-${language}"] [data-option-count] [role="img"]`,
        )
        ?.getAttribute('aria-label');
    expect(count('fr')).toBe(`${LEGAL_PAGES.length} à valider`);
    expect(count('ar')).toBe('1 à valider');

    facade.overview.set(
      overviewWith(
        Object.fromEntries(
          LEGAL_PAGES.flatMap((page) =>
            (['fr', 'en', 'ar'] as const).map((language) => [
              `${page}.${language}`,
              { written: true, publishedOn: '2026-09-21', validated: true },
            ]),
          ),
        ),
      ),
    );
    await settle();
    expect(q('legal-waiting')?.textContent?.trim()).toBe('Tous les textes sont validés');
    expect(
      document.querySelector(
        '[data-testid="legal-overview-language-fr"] [data-option-count] [role="img"]',
      ),
    ).toBeNull();
  });

  it('opens a page in a language with its latest version in the editor, laid out in that language', async () => {
    histories['cookies.ar'] = [version({ body: 'نص' })];
    await render();
    await showIn('ar');
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

  it('counts the texts typed and not published, and the identity changed, holding the page while they are there', async () => {
    await render();
    const unsaved = TestBed.inject(UnsavedChanges);
    q('legal-cell-mentions-fr')!.click();
    await settle();
    expect(unsaved.count()).toBe(0);
    await type('pas encore publié');
    expect(unsaved.count()).toBe(1);
    q('legal-cell-cookies-fr')!.click();
    await settle();
    // Kept for its page while another is open: still unsaved.
    expect(unsaved.count()).toBe(1);
    const host = q('legal-identity-host.name') as HTMLInputElement;
    host.value = 'OVH SAS';
    host.dispatchEvent(new Event('input'));
    await settle();
    expect(unsaved.count()).toBe(2);
    const leaving = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(leaving);
    expect(leaving.defaultPrevented).toBe(true);
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

  // The API asks who is at the screen before it reads the save, so an address it would refuse is said first.
  it('says an address the one e-mail rule refuses under its field, and asks no save until it is mended', async () => {
    await render();
    const email = q('legal-identity-publisher.email') as HTMLInputElement;
    email.value = 'contact@twes';
    email.dispatchEvent(new Event('input'));
    await settle();
    expect(q('legal-identity-publisher.email-error')).toBeNull();

    q('legal-identity-save')!.click();
    await settle();

    expect(facade.saveIdentity).not.toHaveBeenCalled();
    expect(q('legal-identity-publisher.email-error')?.textContent).toContain(
      'nom@domaine.extension',
    );
    expect(email.getAttribute('aria-invalid')).toBe('true');
    expect(document.activeElement).toBe(email);

    email.value = 'contact@twes.tn';
    email.dispatchEvent(new Event('input'));
    await settle();
    expect(q('legal-identity-publisher.email-error')).toBeNull();
    q('legal-identity-save')!.click();
    await settle();
    expect(facade.saveIdentity).toHaveBeenCalledWith({ 'publisher.email': 'contact@twes.tn' });
  });

  // Row 252: the publisher's and host's phones are the one phone field, read by the platform's country.
  it("asks a phone as its country and local number, says a wrong length with its country's example, and saves it international", async () => {
    await render();
    const country = q('legal-identity-publisher.phone-country')!;
    const phone = q('legal-identity-publisher.phone') as HTMLInputElement;
    expect(country.textContent).toContain('+216');
    expect(phone.getAttribute('type')).toBe('tel');

    phone.value = '71 900';
    phone.dispatchEvent(new Event('input'));
    await settle();
    q('legal-identity-save')!.click();
    await settle();
    expect(facade.saveIdentity).not.toHaveBeenCalled();
    expect(q('legal-identity-publisher.phone-error')?.textContent).toContain('exemple : +216 ');
    expect(phone.getAttribute('aria-invalid')).toBe('true');
    expect(document.activeElement).toBe(phone);

    phone.value = '71 900 868';
    phone.dispatchEvent(new Event('input'));
    await settle();
    expect(q('legal-identity-publisher.phone-error')).toBeNull();
    q('legal-identity-save')!.click();
    await settle();
    expect(facade.saveIdentity).toHaveBeenCalledWith({ 'publisher.phone': '+216 71 900 868' });
  });

  it('reads a kept phone into its country and local part, and counts it unchanged until it is typed otherwise', async () => {
    facade.identity.set({ 'publisher.name': 'twes SAS', 'publisher.phone': '+33 6 12 34 56 78' });
    await render();
    expect(q('legal-identity-publisher.phone-country')!.textContent).toContain('+33');
    expect((q('legal-identity-publisher.phone') as HTMLInputElement).value).toBe('06 12 34 56 78');
    expect(q('legal-identity-save')!.hasAttribute('disabled')).toBe(true);
  });

  it('leaves a phone kept from before the rule as it is, refusing it only once typed again', async () => {
    facade.identity.set({ 'publisher.phone': '71.900.868 poste 12', 'host.name': '' });
    await render();
    expect((q('legal-identity-publisher.phone') as HTMLInputElement).value).toBe(
      '71.900.868 poste 12',
    );
    const host = q('legal-identity-host.name') as HTMLInputElement;
    host.value = 'OVH SAS';
    host.dispatchEvent(new Event('input'));
    await settle();
    q('legal-identity-save')!.click();
    await settle();
    expect(q('legal-identity-publisher.phone-error')).toBeNull();
    expect(facade.saveIdentity).toHaveBeenCalledWith({ 'host.name': 'OVH SAS' });
  });

  it('shows an example under every address field, which the field is described by, and under no other', async () => {
    await render();
    const email = q('legal-identity-publisher.email') as HTMLInputElement;
    const hint = q('legal-identity-publisher.email-hint');
    expect(hint?.textContent?.trim()).toBe('platform.legal.identity.email_hint');
    expect(email.getAttribute('aria-describedby')).toBe(hint?.id);
    expect(q('legal-identity-publisher.name-hint')).toBeNull();
    expect(q('legal-identity-publisher.name')?.getAttribute('aria-describedby')).toBeNull();

    email.value = 'contact@twes';
    email.dispatchEvent(new Event('input'));
    await settle();
    q('legal-identity-save')!.click();
    await settle();
    expect(email.getAttribute('aria-describedby')?.split(' ')).toEqual([
      hint?.id,
      q('legal-identity-publisher.email-error')?.id,
    ]);
  });

  it('says what the API refused under its field, until that field is typed again', async () => {
    facade.saveIdentity.mockImplementation(async () => {
      facade.identityRefusals.set([
        { field: 'host.name', reason: 'invalid_text', params: { max: 200 } },
      ]);
      return 'refused';
    });
    await render();
    const host = q('legal-identity-host.name') as HTMLInputElement;
    host.value = 'OVH';
    host.dispatchEvent(new Event('input'));
    await settle();
    q('legal-identity-save')!.click();
    await settle();

    expect(q('legal-identity-host.name-error')?.textContent).toContain('200');
    host.value = 'OVH SAS';
    host.dispatchEvent(new Event('input'));
    await settle();
    expect(q('legal-identity-host.name-error')).toBeNull();
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
