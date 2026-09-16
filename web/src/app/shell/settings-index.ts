// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * The settings area's own address, `/company`: on a phone the list of settings stands alone there, and on a wider
 * window, where the list is always beside the page, this says to pick one.
 */
@Component({
  selector: 'app-settings-index',
  imports: [TranslatePipe],
  template: `<p class="text-on-surface-variant" data-testid="settings-index">
    {{ 'shell.settings_pick' | translate }}
  </p>`,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsIndex {}
