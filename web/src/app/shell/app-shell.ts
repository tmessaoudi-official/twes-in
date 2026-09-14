// SPDX-License-Identifier: AGPL-3.0-or-later

import { BreakpointObserver } from '@angular/cdk/layout';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  isDevMode,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatDividerModule } from '@angular/material/divider';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { MatMenuModule } from '@angular/material/menu';
import { MatSidenavModule } from '@angular/material/sidenav';
import { MatToolbarModule } from '@angular/material/toolbar';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { map } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { CompanySwitcher } from '../company/company-switcher';
import { NotificationBell } from '../notifications/notification-bell';
import { LanguageFacade, SUPPORTED_LANGUAGES } from '../shared/i18n/language-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { CORE_NAV, MODULE_NAV, navSections, visibleEntries } from './nav-manifest';

/** Below this width the navigation becomes a drawer over the page instead of a column beside it. */
const HANDSET = '(max-width: 959.98px)';

/** "Amel Ben Salah" → "AS": the first and last word, which is how people recognise their own initials. */
export function initialsOf(displayName: string): string {
  const words = displayName.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) {
    return '?';
  }
  const first = words[0][0] ?? '';
  const last = words.length > 1 ? (words[words.length - 1][0] ?? '') : '';
  return (first + last).toUpperCase();
}

/**
 * Every signed-in page sits inside this: the navigation the user may see, where they work, and their account
 * (language, dark mode, sign out). Pages bring content only.
 */
@Component({
  selector: 'app-shell',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatSidenavModule,
    MatToolbarModule,
    MatListModule,
    MatIconModule,
    MatButtonModule,
    MatMenuModule,
    MatDividerModule,
    TranslatePipe,
    CompanySwitcher,
    NotificationBell,
  ],
  templateUrl: './app-shell.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShell {
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);
  protected readonly theme = inject(ThemeFacade);
  protected readonly language = inject(LanguageFacade);

  protected readonly languages = SUPPORTED_LANGUAGES;
  protected readonly me = this.auth.me;
  protected readonly signingOut = signal(false);
  protected readonly handset = toSignal(
    inject(BreakpointObserver)
      .observe(HANDSET)
      .pipe(map((state) => state.matches)),
    { initialValue: false },
  );
  protected readonly sections = computed(() =>
    navSections(
      visibleEntries(
        [...CORE_NAV, ...MODULE_NAV],
        (permission) => this.auth.hasPermission(permission),
        isDevMode(),
        (module) => this.auth.hasModule(module),
      ),
    ),
  );
  protected readonly initials = computed(() => initialsOf(this.me()?.user.displayName ?? ''));

  protected async logout(): Promise<void> {
    this.signingOut.set(true);
    try {
      await this.auth.logout();
    } finally {
      this.signingOut.set(false);
      await this.router.navigateByUrl('/login');
    }
  }

  protected async useLanguage(language: string): Promise<void> {
    await this.language.use(language);
  }
}
