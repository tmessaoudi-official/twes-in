// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
} from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { WatchFacade } from './watch-facade';
import { subjectView } from './watch-subjects';
import { WATCHED_KINDS } from './watch-types';

/**
 * « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10, the subject pages): one card per subject with its count, each
 * opening the table of what to act on in it. Worked out by the API on every read and never stored, so a subject dealt
 * with leaves by itself. Nothing here is pushed: it is what is true when it is read.
 */
@Component({
  selector: 'app-watch-page',
  imports: [MatCardModule, MatIconModule, RouterLink, TranslatePipe],
  templateUrl: './watch-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WatchPage implements OnInit {
  private readonly facade = inject(WatchFacade);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly summary = this.facade.summary;
  protected readonly error = this.facade.error;
  /** The subjects this screen knows how to draw: a kind the API names before the web learns it is not hidden from the count. */
  protected readonly cards = computed(() =>
    (this.summary()?.subjects ?? []).flatMap((subject) => {
      const view = subjectView(subject.kind);
      return view === undefined
        ? []
        : [{ kind: subject.kind, count: subject.count, icon: view.icon }];
    }),
  );

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(WATCHED_KINDS, () => this.facade.load(companyId), this.destroyRef);
    await this.facade.load(companyId);
  }
}
