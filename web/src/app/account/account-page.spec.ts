// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { MfaStatus } from '../auth/auth-types';
import { CompanyFacade } from '../company/company-facade';
import type { CompanyOption } from '../company/company-types';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { DEFAULT_SHORTCUTS } from '../shared/actions/shortcuts';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import { AccountPage } from './account-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      shell: {
        soon: 'Bientôt',
        shortcuts: {
          search: 'Rechercher',
          create: 'Ouvrir « Créer »',
          new: 'Nouveau document',
          next: 'Étape suivante',
        },
      },
      account: {
        title: 'Mon compte',
        tabs: {
          security: 'Sécurité',
          preferences: 'Préférences',
          device: 'Cet appareil',
          notifications: 'Notifications',
        },
        display: 'Affichage',
        language: 'Langue',
        scheme: 'Thème',
        density: 'Densité',
        date_format: 'Format des dates',
        number_format: 'Format des nombres',
        format_preview: 'Aperçu : {{ day }} · {{ amount }}',
        coming: 'Ce qui arrive',
        show_coming: 'Montrer ce qui arrive',
        company_at_sign_in: 'Société à l’ouverture',
        company_at_sign_in_on: 'Toujours ouvrir la même société',
        company_at_sign_in_off: 'La dernière société utilisée s’ouvre.',
        company_at_sign_in_pick: 'Société',
        shortcuts: 'Raccourcis clavier',
        shortcuts_intro: 'Une touche, sans modificateur.',
        shortcuts_restore: 'Rétablir',
        shortcut_refused: {
          length: 'Une seule touche.',
          browser: 'Le navigateur utilise cette touche.',
          interface: 'Déjà utilisée partout.',
          screen: 'Une page utilise cette touche.',
          count: 'Réservée au nombre avant un scan.',
          taken: 'Déjà donnée à une autre action.',
        },
        two_factor: 'Vérification en deux étapes',
        two_factor_on: 'Activée',
        two_factor_off: 'Désactivée',
        two_factor_manage: 'Gérer',
        later: 'Arrive dans une prochaine version.',
      },
      settings: {
        choices: {
          presentation: {
            scheme: { auto: 'Automatique', light: 'Clair', dark: 'Sombre' },
            density: { comfortable: 'Confortable', compact: 'Compacte' },
            'date-format': { auto: 'Selon la langue', ymd: '2026-12-31' },
            'number-format': { auto: 'Selon la langue', 'comma-dot': '1,234.56' },
          },
        },
      },
    });
  }
}

const acme: CompanyOption = {
  id: 'c1',
  name: 'Acme',
  status: 'active',
  role: 'owner',
  pinned: false,
};
const globex: CompanyOption = {
  id: 'c2',
  name: 'Globex',
  status: 'active',
  role: 'member',
  pinned: false,
};

// docs/SPEC.md § 7, 2026-09-25 17:22 and the round-6 account boards: one page for the person's own account, where
// the display choices, « Montrer ce qui arrive » and « Société à l'ouverture » are set.
describe('AccountPage', () => {
  const preference = signal<'auto' | 'light' | 'dark'>('auto');
  const density = signal<'comfortable' | 'compact'>('comfortable');
  const showComing = signal(true);
  const theme = {
    preference,
    density,
    showComing,
    setScheme: vi.fn(),
    setDensity: vi.fn(),
    setShowComing: vi.fn(),
  };
  const language = { current: signal<'fr' | 'en'>('fr'), use: vi.fn() };
  const companies = signal<readonly CompanyOption[]>([acme, globex]);
  const company = {
    companies,
    current: signal({ id: 'c1', name: 'Acme', role: 'owner' }),
    error: signal(null),
    load: vi.fn(),
    pinAtSignIn: vi.fn(),
  };
  const me = signal<{ mfa: MfaStatus }>({
    mfa: { enrolled: true, required: false, totp: true, passkeys: 0 },
  });
  const auth = { me };
  let fixture: ComponentFixture<AccountPage>;

  async function render(tab?: string): Promise<HTMLElement> {
    await TestBed.configureTestingModule({
      imports: [AccountPage],
      providers: [
        provideRouter([]),
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        { provide: ThemeFacade, useValue: theme },
        { provide: LanguageFacade, useValue: language },
        { provide: CompanyFacade, useValue: company },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useValue: { me: signal(null) } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(AccountPage);
    if (tab !== undefined) fixture.componentRef.setInput('tab', tab);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const byTestId = (root: HTMLElement, id: string) =>
    root.querySelector<HTMLElement>(`[data-testid="${id}"]`);

  function choose(root: HTMLElement, id: string, value: string): void {
    const select = byTestId(root, id) as HTMLSelectElement;
    select.value = value;
    select.dispatchEvent(new Event('change'));
  }

  beforeEach(() => {
    preference.set('auto');
    density.set('comfortable');
    showComing.set(true);
    companies.set([acme, globex]);
    me.set({ mfa: { enrolled: true, required: false, totp: true, passkeys: 0 } });
    for (const fn of [
      theme.setScheme,
      theme.setDensity,
      theme.setShowComing,
      language.use,
      company.load,
      company.pinAtSignIn,
    ]) {
      fn.mockReset();
    }
    company.pinAtSignIn.mockResolvedValue(true);
  });

  it('names its four tabs, the two not built yet marked « Bientôt »', async () => {
    const root = await render();
    const labels = [...root.querySelectorAll('[role="tab"]')].map((tab) =>
      tab.textContent?.replace(/\s+/g, ' ').trim(),
    );
    expect(labels).toEqual([
      'Sécurité',
      'Préférences',
      'Cet appareil Bientôt',
      'Notifications Bientôt',
    ]);
  });

  it('says whether the two-step check is on, and leads to where it is managed', async () => {
    const root = await render('security');
    expect(byTestId(root, 'account-two-factor')?.textContent).toContain('Activée');
    expect(byTestId(root, 'account-two-factor-manage')?.getAttribute('href')).toBe('/two-factor');

    me.set({ mfa: { enrolled: false, required: false, totp: false, passkeys: 0 } });
    fixture.detectChanges();
    expect(byTestId(root, 'account-two-factor')?.textContent).toContain('Désactivée');
  });

  it('sets the language, the scheme and the density', async () => {
    const root = await render('preferences');
    expect((byTestId(root, 'account-language') as HTMLSelectElement).value).toBe('fr');

    choose(root, 'account-language', 'en');
    choose(root, 'account-scheme', 'dark');
    choose(root, 'account-density', 'compact');

    expect(language.use).toHaveBeenCalledWith('en');
    expect(theme.setScheme).toHaveBeenCalledWith('dark');
    expect(theme.setDensity).toHaveBeenCalledWith('compact');
  });

  // docs/SPEC.md § 7, 2026-09-25 12:45, row 130.
  it('sets a date and a number format of one’s own, the language’s until then', async () => {
    const root = await render('preferences');
    const settings = TestBed.inject(SettingsFacade);
    const dates = byTestId(root, 'account-date-format') as HTMLSelectElement;
    expect(dates.value).toBe('auto');
    expect(dates.selectedOptions[0]?.textContent?.trim()).toBe('Selon la langue');
    expect([...dates.options].map((option) => option.value)).toEqual([
      'auto',
      'dmy',
      'mdy',
      'ymd',
      'dmy-dots',
    ]);

    choose(root, 'account-date-format', 'ymd');
    choose(root, 'account-number-format', 'comma-dot');

    expect(settings.value(PRESENTATION.dateFormat)()).toBe('ymd');
    expect(settings.value(PRESENTATION.numberFormat)()).toBe('comma-dot');
    fixture.detectChanges();
    expect(
      (
        byTestId(root, 'account-number-format') as HTMLSelectElement
      ).selectedOptions[0]?.textContent?.trim(),
    ).toBe('1,234.56');
    // What they will read, today and an amount, under the two choices.
    expect(byTestId(root, 'account-format-preview')?.textContent).toMatch(/\d{4}-\d{2}-\d{2}/);
    expect(byTestId(root, 'account-format-preview')?.textContent).toContain('1,234.56');
  });

  it('turns « Montrer ce qui arrive » off and on', async () => {
    const root = await render('preferences');
    const toggle = byTestId(root, 'account-show-coming')?.querySelector('button');
    expect(toggle?.getAttribute('aria-checked')).toBe('true');

    toggle?.click();

    expect(theme.setShowComing).toHaveBeenCalledWith(false);
  });

  it('pins the company every sign-in opens, the working one first, and unpins it', async () => {
    const root = await render('preferences');
    expect(company.load).toHaveBeenCalled();
    expect(byTestId(root, 'account-company-at-sign-in-pick')).toBeNull();

    byTestId(root, 'account-company-at-sign-in')?.querySelector('button')?.click();
    expect(company.pinAtSignIn).toHaveBeenCalledWith('c1');

    companies.set([{ ...acme, pinned: true }, globex]);
    fixture.detectChanges();
    const pick = byTestId(root, 'account-company-at-sign-in-pick') as HTMLSelectElement;
    expect(pick.value).toBe('c1');
    choose(root, 'account-company-at-sign-in-pick', 'c2');
    expect(company.pinAtSignIn).toHaveBeenLastCalledWith('c2');

    byTestId(root, 'account-company-at-sign-in')?.querySelector('button')?.click();
    expect(company.pinAtSignIn).toHaveBeenLastCalledWith(null);
  });

  it('offers no company to pin to somebody in one company only', async () => {
    companies.set([acme]);
    const root = await render('preferences');
    expect(byTestId(root, 'account-company-at-sign-in')).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-24 22:51, row 125: each person changes the shell's keys, or restores the ruled ones.
  describe('keyboard shortcuts', () => {
    const keys = () => TestBed.inject(SettingsFacade).value(PRESENTATION.shortcuts)();

    function press(root: HTMLElement, name: string, key: string): void {
      const input = byTestId(root, `account-key-${name}`) as HTMLInputElement;
      input.value = key;
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
    }

    it('shows the four keys as they are now, no longer « Bientôt »', async () => {
      const root = await render('preferences');
      const shown = ['search', 'create', 'new', 'next'].map(
        (name) => (byTestId(root, `account-key-${name}`) as HTMLInputElement).value,
      );
      expect(shown).toEqual(['/', 'C', 'N', 'E']);
      expect(byTestId(root, 'account-shortcuts')?.textContent).not.toContain('Bientôt');
      expect(byTestId(root, 'account-keys-restore')).toBeNull();
    });

    it('keeps a key that is free, and says why it refuses one that is not', async () => {
      const root = await render('preferences');

      press(root, 'create', 'K');
      expect(keys()).toEqual({ ...DEFAULT_SHORTCUTS, create: 'k' });
      expect(byTestId(root, 'account-key-create-error')).toBeNull();

      press(root, 'new', 's');
      expect(byTestId(root, 'account-key-new-error')?.textContent).toContain('Une page');
      // The field shows the key actually kept, never one that would do nothing.
      expect((byTestId(root, 'account-key-new') as HTMLInputElement).value).toBe('N');
      // Typed over the old key without selecting it first, the last character is the one meant.
      press(root, 'search', '/;');
      expect(keys().search).toBe(';');
      press(root, 'search', '/');
      press(root, 'new', 'k');
      expect(byTestId(root, 'account-key-new-error')?.textContent).toContain('autre action');
      press(root, 'next', '5');
      expect(byTestId(root, 'account-key-next-error')?.textContent).toContain('nombre');
      expect(keys()).toEqual({ ...DEFAULT_SHORTCUTS, create: 'k' });

      // A refused key is forgotten once a free one is typed in its place.
      press(root, 'new', 'j');
      expect(byTestId(root, 'account-key-new-error')).toBeNull();
      expect(keys()).toEqual({ ...DEFAULT_SHORTCUTS, create: 'k', new: 'j' });
    });

    it('restores the ruled keys at once', async () => {
      const root = await render('preferences');
      press(root, 'create', 'k');
      press(root, 'next', '5');

      byTestId(root, 'account-keys-restore')?.click();
      fixture.detectChanges();

      expect(keys()).toEqual(DEFAULT_SHORTCUTS);
      expect((byTestId(root, 'account-key-create') as HTMLInputElement).value).toBe('C');
      expect(byTestId(root, 'account-key-next-error')).toBeNull();
      expect(byTestId(root, 'account-keys-restore')).toBeNull();
    });
  });
});
