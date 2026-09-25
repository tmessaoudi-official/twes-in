// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { COMING_NAV } from './nav-manifest';

/**
 * « En construction » (docs/SPEC.md § 7, 2026-09-25 17:22): the one page every entry of the vision not built yet opens,
 * saying what it will do, for which version, the § 8 row that builds it and what to use meanwhile. Its texts live
 * under `coming.<key>`; the entry itself, in `COMING_NAV`.
 */
@Component({
  selector: 'app-coming-page',
  imports: [MatButtonModule, MatIconModule, RouterLink, TranslatePipe],
  templateUrl: './coming-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComingPage {
  private readonly theme = inject(ThemeFacade);
  private readonly router = inject(Router);

  /** The entry's key, from the address (`/coming/:key`, `/company/coming/:key`). */
  readonly key = input.required<string>();
  protected readonly entry = computed(
    () => COMING_NAV.find((entry) => entry.key === this.key()) ?? null,
  );

  /** « Masquer ce qui arrive »: the menus show only what works, and the person goes home. */
  protected hide(): void {
    this.theme.setShowComing(false);
    void this.router.navigateByUrl('/');
  }
}
