// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  type OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { MatTabsModule } from '@angular/material/tabs';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { CompanyFacade } from '../company/company-facade';
import {
  DEFAULT_SHORTCUTS,
  SHELL_SHORTCUTS,
  type ShellKeyRefusal,
  type ShellShortcut,
  shellKeyRefusal,
} from '../shared/actions/shortcuts';
import { keyName } from '../shared/actions/shortcuts-sheet';
import {
  DATE_FORMATS,
  type DateFormat,
  NUMBER_FORMATS,
  type NumberStyle,
  todayIn,
} from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LANGUAGE_NAMES, LanguageFacade } from '../shared/i18n/language-facade';
import { SettingsFacade } from '../shared/settings/settings-facade';
import {
  type Density,
  PRESENTATION,
  type SchemePreference,
  SUPPORTED_LANGUAGES,
} from '../shared/settings/settings-registry';
import { ThemeFacade } from '../shared/theme/theme-facade';

/** Why a key typed in Préférences was not kept: the shell's own reasons, or another action has it already. */
type KeyRefusal = ShellKeyRefusal | 'taken';

/** The page's tabs, in the order the round-6 account board draws them; the last two are not built yet. */
export const ACCOUNT_TABS = ['security', 'preferences', 'device', 'notifications'] as const;
export type AccountTab = (typeof ACCOUNT_TABS)[number];
const COMING_TABS: readonly AccountTab[] = ['device', 'notifications'];

/**
 * « Mon compte » (docs/SPEC.md § 7, 2026-09-25 17:22; the round-6 account boards): the person's own account, apart
 * from any company. Sécurité leads to the two-step check; Préférences holds the display choices, « Montrer ce qui
 * arrive », « Société à l'ouverture » and the keyboard shortcuts. The tab is in the address (`?tab=`), so a link can open the right one.
 */
@Component({
  selector: 'app-account-page',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSlideToggleModule,
    MatTabsModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './account-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AccountPage implements OnInit {
  protected readonly theme = inject(ThemeFacade);
  protected readonly language = inject(LanguageFacade);
  private readonly company = inject(CompanyFacade);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  /** The tab from the address; an unknown one opens the first. */
  readonly tab = input<string | undefined>(undefined);

  protected readonly tabs = ACCOUNT_TABS;
  protected readonly comingTabs = COMING_TABS;
  protected readonly languages = SUPPORTED_LANGUAGES;
  protected readonly languageNames = LANGUAGE_NAMES;
  protected readonly schemes: readonly SchemePreference[] = ['auto', 'light', 'dark'];
  protected readonly densities: readonly Density[] = ['comfortable', 'compact'];

  protected readonly selected = computed(() =>
    Math.max(0, ACCOUNT_TABS.indexOf(this.tab() as AccountTab)),
  );
  protected readonly twoFactorOn = computed(() => this.auth.me()?.mfa.enrolled === true);

  private readonly settings = inject(SettingsFacade);
  /** The shell's single keys as this person has them (docs/SPEC.md § 7, 2026-09-24 22:51, row 125). */
  protected readonly keys = this.settings.value(PRESENTATION.shortcuts);
  protected readonly shellShortcuts = SHELL_SHORTCUTS;
  /** How days and figures are written, the language's until the person chooses (docs/SPEC.md § 7, row 130). */
  protected readonly dateFormat = this.settings.value(PRESENTATION.dateFormat);
  protected readonly numberFormat = this.settings.value(PRESENTATION.numberFormat);
  protected readonly dateFormats = DATE_FORMATS;
  protected readonly numberFormats = NUMBER_FORMATS;
  private readonly format = inject(FormatFacade);
  /** Today and an amount as they will read under the two choices, « Selon la langue » included. */
  protected readonly formatPreview = computed(() => ({
    day: this.format.day(todayIn(this.auth.me()?.company?.timezone)),
    amount: this.format.amount('1234.56', 2),
  }));
  protected readonly keyName = keyName;
  /** Why the key last typed in a field was not kept; forgotten once one is. */
  protected readonly keyRefusals = signal<Partial<Record<ShellShortcut, KeyRefusal>>>({});
  protected readonly customisedKeys = computed(() =>
    SHELL_SHORTCUTS.some((name) => this.keys()[name] !== DEFAULT_SHORTCUTS[name]),
  );

  protected readonly companies = this.company.companies;
  /** Pinning means something only to somebody in several companies. */
  protected readonly canPin = computed(() => this.companies().length > 1);
  protected readonly pinned = computed(
    () => this.companies().find((company) => company.pinned) ?? null,
  );

  ngOnInit(): void {
    void this.company.load();
  }

  protected openTab(index: number): void {
    void this.router.navigate([], {
      queryParams: { tab: ACCOUNT_TABS[index] },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  protected useLanguage(language: string): void {
    void this.language.use(language);
  }

  /** Switched on, every sign-in opens the company worked in now; the list then offers the others. */
  protected pinAtSignIn(on: boolean): void {
    void this.company.pinAtSignIn(on ? (this.company.current()?.id ?? null) : null);
  }

  protected pick(companyId: string): void {
    void this.company.pinAtSignIn(companyId);
  }

  /**
   * Keeps a typed key at once, as the other preferences here are kept, or says why not: one the browser, the
   * interface, a screen or a till count answers, or one another of the four already has. The field takes the last
   * character typed and then shows the key actually kept, so it never holds one that does nothing; an emptied field
   * keeps the key it had.
   */
  protected chooseKey(name: ShellShortcut, field: HTMLInputElement): void {
    const key = ([...field.value].at(-1) ?? '').toLowerCase();
    this.keep(name, key);
    field.value = keyName(this.keys()[name]);
  }

  private keep(name: ShellShortcut, key: string): void {
    if (key === '') return;
    const keys = this.keys();
    const refusal: KeyRefusal | null =
      shellKeyRefusal(key) ??
      (SHELL_SHORTCUTS.some((other) => other !== name && keys[other] === key) ? 'taken' : null);
    this.keyRefusals.update((refusals) => ({ ...refusals, [name]: refusal ?? undefined }));
    if (refusal === null) this.settings.set(PRESENTATION.shortcuts, { ...keys, [name]: key });
  }

  /** « Rétablir »: the ruled keys again, C, N, E and /. */
  protected restoreKeys(): void {
    this.keyRefusals.set({});
    this.settings.reset(PRESENTATION.shortcuts);
  }

  protected useDateFormat(value: string): void {
    this.settings.set(PRESENTATION.dateFormat, value as DateFormat);
  }

  protected useNumberFormat(value: string): void {
    this.settings.set(PRESENTATION.numberFormat, value as NumberStyle);
  }

  protected schemeOf(value: string): SchemePreference {
    return value as SchemePreference;
  }

  protected densityOf(value: string): Density {
    return value as Density;
  }
}
