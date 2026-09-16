// SPDX-License-Identifier: AGPL-3.0-or-later

import { NgComponentOutlet } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  isDevMode,
  signal,
  type Type,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { formatLongDay, todayIn } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { HOME_PANELS } from '../shell/home-manifest';
import { visibleEntries } from '../shell/nav-manifest';

/**
 * The signed-in home page: the company's day, a greeting, and the panels each switched-on module declares for it
 * (shell/home-manifest.ts), under the same gates as its navigation. The shell carries the chrome around it.
 */
@Component({
  selector: 'app-hello-page',
  imports: [TranslatePipe, RouterLink, MatButtonModule, NgComponentOutlet],
  templateUrl: './hello-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class HelloPage {
  private readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);

  protected readonly me = this.auth.me;
  protected readonly today = computed(() => {
    const company = this.me()?.company;
    return company ? formatLongDay(todayIn(company.timezone), this.format.locale()) : null;
  });
  private readonly panels = computed(() =>
    this.me() === null
      ? []
      : visibleEntries(
          HOME_PANELS,
          (permission) => this.auth.hasPermission(permission),
          isDevMode(),
          (module) => this.auth.hasModule(module),
        ),
  );
  /** The shown panels' components, once loaded. */
  protected readonly components = signal<readonly Type<unknown>[]>([]);

  constructor() {
    let asked = 0;
    effect(async () => {
      const panels = this.panels();
      const ask = ++asked;
      const loaded = await Promise.all(panels.map((panel) => panel.load()));
      // A later change of company or permissions may have asked again while these loaded; its answer wins.
      if (ask === asked) this.components.set(loaded);
    });
  }
}
