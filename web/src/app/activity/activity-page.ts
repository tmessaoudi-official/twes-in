// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { MembersFacade } from '../company/members-facade';
import { MomentPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import type { ListPickSource, ListQuery } from '../shared/list/list-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ActivityFacade } from './activity-facade';
import { ActivityFieldsPipe } from './activity-fields-pipe';
import {
  ACTIVITY_KINDS,
  ACTIVITY_LIST,
  actionKey,
  activitySearch,
  kindKey,
  recordLink,
} from './activity-list';
import type { ActivitySearch } from './activity-types';

/**
 * « Journal d'activité » (docs/SPEC.md § 7, 2026-09-26 23:04): what was done in the company, by whom and to which
 * record, newest first, within what the company keeps. A record that has a page of its own opens from its line.
 */
@Component({
  selector: 'app-activity-page',
  imports: [
    ActivityFieldsPipe,
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    ListExport,
    MomentPipe,
  ],
  templateUrl: './activity-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ActivityPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(ActivityFacade);
  private readonly members = inject(MembersFacade);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  protected readonly list = ACTIVITY_LIST;
  protected readonly rows = this.facade.rows;
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly actionKey = actionKey;
  protected readonly kindKey = kindKey;
  protected readonly recordLink = recordLink;
  protected readonly rowTestId = (row: { id: string }): string => `activity-${row.id}`;

  /** Who did it is picked among the members, for a reader allowed to see them. */
  protected readonly pickSources: Readonly<Record<string, ListPickSource>> = {
    actor: {
      search: async (words) => {
        const wanted = words.trim().toLocaleLowerCase();
        return this.people().filter((person) =>
          `${person.name} ${person.code}`.toLocaleLowerCase().includes(wanted),
        );
      },
      byIds: async (ids) => this.people().filter((person) => ids.includes(person.id)),
    },
  };

  /** One record's history, when its « Historique » sent the reader here: `?record=<id>`. */
  protected readonly record = signal(
    recordOf(inject(ActivatedRoute).snapshot.queryParamMap.get('record')),
  );
  private query: ListQuery | null = null;
  private search: ActivitySearch | null = null;
  private readonly searched = signal<ActivitySearch | null>(null);
  protected readonly exporter = computed(() => {
    const companyId = this.company()?.id;
    const search = this.searched();
    return companyId && search !== null
      ? (format: ExportFormat) => this.facade.exportUrl(companyId, search, format)
      : null;
  });

  ngOnInit(): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    if (this.auth.hasPermission('user.read')) void this.members.load(companyId);
    // Every change is written in the journal under the kind of what it changed.
    this.live.reloadOn(
      ACTIVITY_KINDS,
      async () => {
        if (this.search !== null) await this.facade.loadPage(companyId, this.search);
      },
      this.destroyRef,
    );
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.query = query;
    this.search = activitySearch(query, this.record());
    this.searched.set(this.search);
    void this.facade.loadPage(companyId, this.search);
  }

  /** Back to the whole journal, under the filters already chosen. */
  protected everyRecord(): void {
    this.record.set(null);
    void this.router.navigate([], {
      queryParams: { record: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
    if (this.query !== null) this.onQuery(this.query);
  }

  private people() {
    return this.members
      .members()
      .filter((member) => member.status === 'joined')
      .map((member) => ({ id: member.userId, code: member.email, name: member.displayName }));
  }
}

const ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/** A record id an address names, or null for none or for anything that is not one. */
function recordOf(value: string | null): string | null {
  return value !== null && ID.test(value) ? value : null;
}
