// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, DestroyRef, inject, type OnInit } from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { WatchFacade } from './watch-facade';
import { WATCHED_KINDS } from './watch-types';

/** The home's count of « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10), linked to the list itself. */
@Component({
  selector: 'app-watch-home',
  imports: [MatCardModule, MatIconModule, RouterLink, TranslatePipe],
  templateUrl: './watch-home.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WatchHome implements OnInit {
  private readonly facade = inject(WatchFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly list = this.facade.list;

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(WATCHED_KINDS, () => this.facade.load(companyId), this.destroyRef);
    await this.facade.load(companyId);
  }
}
