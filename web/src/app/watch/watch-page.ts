// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
} from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { WatchFacade } from './watch-facade';
import { WATCHED_KINDS } from './watch-types';
import { watchLine } from './watch-view';

/**
 * « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): the conditions true now, each in a sentence with its figure
 * and a link to its list, worked out by the API on every read and never stored, so a condition dealt with leaves by
 * itself. Nothing here is pushed: the list is what is true when it is read.
 */
@Component({
  selector: 'app-watch-page',
  imports: [MatIconModule, RouterLink, TranslatePipe],
  templateUrl: './watch-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WatchPage implements OnInit {
  private readonly facade = inject(WatchFacade);
  private readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly list = this.facade.list;
  protected readonly error = this.facade.error;
  protected readonly lines = computed(() =>
    (this.list()?.items ?? []).map((item) => watchLine(item, this.format.locale())),
  );

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(WATCHED_KINDS, () => this.facade.load(companyId), this.destroyRef);
    await this.facade.load(companyId);
  }
}
