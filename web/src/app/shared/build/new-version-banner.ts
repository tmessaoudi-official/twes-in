// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  DOCUMENT,
  inject,
  InjectionToken,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { BuildInfo } from './build-info';

/** Loads the page again, from the server: a new build's bundles are only reached that way. */
export const RELOAD_PAGE = new InjectionToken<() => void>('RELOAD_PAGE', {
  providedIn: 'root',
  factory: () => {
    const location = inject(DOCUMENT).location;
    return () => location.reload();
  },
});

/**
 * « Nouvelle version disponible — Recharger » (docs/SPEC.md § 7, the build line), on every page, signed out too, once
 * the server holds another web build than the one this page runs. It never reloads by itself: a form being typed
 * would be lost. It names a state of the page, so it is a status, not a toast that leaves.
 */
@Component({
  selector: 'app-new-version-banner',
  imports: [MatButtonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (info.newWeb()) {
      <div
        role="status"
        class="fixed inset-x-0 top-2 z-[1100] mx-auto flex w-fit max-w-[calc(100%-2rem)] flex-wrap items-center gap-3 rounded-xl border border-outline-variant bg-surface-container-highest px-4 py-2 text-on-surface shadow-lg"
        data-testid="new-version"
      >
        <span>{{ 'build.new_version' | translate }}</span>
        <button mat-flat-button type="button" (click)="reload()" data-testid="new-version-reload">
          {{ 'build.reload' | translate }}
        </button>
      </div>
    }
  `,
})
export class NewVersionBanner {
  protected readonly info = inject(BuildInfo);
  protected readonly reload = inject(RELOAD_PAGE);

  constructor() {
    this.info.start();
  }
}
