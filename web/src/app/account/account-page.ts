// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  type OnInit,
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
import { LANGUAGE_NAMES, LanguageFacade } from '../shared/i18n/language-facade';
import {
  type Density,
  type SchemePreference,
  SUPPORTED_LANGUAGES,
} from '../shared/settings/settings-registry';
import { ThemeFacade } from '../shared/theme/theme-facade';

/** The page's tabs, in the order the round-6 account board draws them; the last two are not built yet. */
export const ACCOUNT_TABS = ['security', 'preferences', 'device', 'notifications'] as const;
export type AccountTab = (typeof ACCOUNT_TABS)[number];
const COMING_TABS: readonly AccountTab[] = ['device', 'notifications'];

/**
 * « Mon compte » (docs/SPEC.md § 7, 2026-09-25 17:22; the round-6 account boards): the person's own account, apart
 * from any company. Sécurité leads to the two-step check; Préférences holds the display choices, « Montrer ce qui
 * arrive » and « Société à l'ouverture ». The tab is in the address (`?tab=`), so a link can open the right one.
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

  protected schemeOf(value: string): SchemePreference {
    return value as SchemePreference;
  }

  protected densityOf(value: string): Density {
    return value as Density;
  }
}
