// SPDX-License-Identifier: AGPL-3.0-or-later

import { toSignal } from '@angular/core/rxjs-interop';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  type OnInit,
} from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { ActivatedRoute, RouterLink, RouterLinkActive } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { map } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { FormatFacade } from '../shared/i18n/format-facade';
import { DataList } from '../shared/list/data-list';
import type { ListQuery } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { WatchFacade } from './watch-facade';
import { subjectView } from './watch-subjects';
import { WATCHED_KINDS } from './watch-types';
import { watchRowView } from './watch-view';

/**
 * One subject of « À surveiller » (docs/SPEC.md § 7, the subject pages): what to act on in it, a page at a time, each
 * row with its way in. The other subjects sit above it as a switcher with their counts, so a person goes from late
 * customers to expired lots without going back.
 */
@Component({
  selector: 'app-watch-subject-page',
  imports: [DataList, MatIconModule, RouterLink, RouterLinkActive, TranslatePipe],
  templateUrl: './watch-subject-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class WatchSubjectPage implements OnInit {
  private readonly facade = inject(WatchFacade);
  private readonly auth = inject(AuthFacade);
  private readonly format = inject(FormatFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly route = inject(ActivatedRoute);

  protected readonly kind = toSignal(
    this.route.paramMap.pipe(map((params) => params.get('kind') ?? '')),
    {
      initialValue: '',
    },
  );
  protected readonly view = computed(() => subjectView(this.kind()));
  protected readonly summary = this.facade.summary;
  protected readonly state = this.facade.rows;
  /** This subject's count as the summary says it, or null while the summary is unread or the subject has left it. */
  protected readonly count = computed(
    () => this.summary()?.subjects.find((subject) => subject.kind === this.kind())?.count ?? null,
  );
  protected readonly switcher = computed(() =>
    (this.summary()?.subjects ?? []).filter((subject) => subjectView(subject.kind) !== undefined),
  );
  protected readonly rows = computed(() => {
    const state = this.state();
    return state.status === 'ready'
      ? state.page.rows.map((row, index) => watchRowView(row, index, this.format))
      : [];
  });
  protected readonly total = computed(() => {
    const state = this.state();
    return state.status === 'ready' ? state.page.total : 0;
  });

  /** The page the table last asked for; a change elsewhere reads it again. */
  private last: ListQuery | null = null;

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(WATCHED_KINDS, () => this.reload(companyId), this.destroyRef);
    await this.facade.load(companyId);
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.last = query;
    void this.facade.loadRows(companyId, this.kind(), query.pageIndex, query.pageSize);
  }

  private async reload(companyId: string): Promise<void> {
    const last = this.last;
    await Promise.all([
      this.facade.load(companyId),
      last === null
        ? Promise.resolve()
        : this.facade.loadRows(companyId, this.kind(), last.pageIndex, last.pageSize),
    ]);
  }
}
