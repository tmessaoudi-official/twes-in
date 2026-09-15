// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, isDevMode } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatListModule } from '@angular/material/list';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { navSections, SETTINGS_NAV, SETTINGS_SECTIONS, visibleEntries } from './nav-manifest';

/**
 * The company settings, reached from the gear in the top bar: their grouped navigation beside the settings page. It
 * is a layout route with no path of its own, so every settings page keeps its address.
 */
@Component({
  selector: 'app-settings-area',
  imports: [
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatListModule,
    MatIconModule,
    TranslatePipe,
  ],
  templateUrl: './settings-area.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsArea {
  private readonly auth = inject(AuthFacade);

  protected readonly sections = computed(() =>
    navSections(
      visibleEntries(
        SETTINGS_NAV,
        (permission) => this.auth.hasPermission(permission),
        isDevMode(),
        (module) => this.auth.hasModule(module),
      ),
      SETTINGS_SECTIONS,
    ),
  );
}
