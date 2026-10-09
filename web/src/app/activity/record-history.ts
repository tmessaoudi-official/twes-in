// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  inject,
  input,
  OnInit,
  signal,
  untracked,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { MomentPipe } from '../shared/i18n/format-pipes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ActivityApi } from './activity-api';
import { ActivityFieldsPipe } from './activity-fields-pipe';
import { actionKey } from './activity-list';
import type { ActivityRow, ActivitySearch } from './activity-types';

/** How many of a record's latest entries its « Historique » shows; the journal holds the rest. */
export const HISTORY_SIZE = 20;

/**
 * A record's « Historique » (docs/SPEC.md § 7, 2026-09-26 23:04): its latest entries in the company's journal, newest
 * first, for a reader holding audit.read; the journal, narrowed to the record, holds the rest.
 */
@Component({
  selector: 'app-record-history',
  imports: [ActivityFieldsPipe, RouterLink, TranslatePipe, MomentPipe],
  template: `
    @if (error()) {
      <p role="alert" class="text-error" data-testid="record-history-error">
        {{ 'activity.errors.network' | translate }}
      </p>
    } @else if (rows().length === 0) {
      <p class="text-on-surface-variant" data-testid="record-history-empty">
        {{ 'activity.history.none' | translate }}
      </p>
    } @else {
      <ol class="flex flex-col gap-2" data-testid="record-history">
        @for (row of rows(); track row.id) {
          <li class="flex flex-wrap gap-x-2" data-testid="record-history-entry">
            <span class="text-on-surface-variant tabular-nums">{{ row.at | moment }}</span>
            <span class="font-medium">{{ row.actorName ?? ('activity.nobody' | translate) }}</span>
            @if (actionKey(row.action); as key) {
              <span>{{ key | translate }}</span>
            } @else {
              <code>{{ row.action }}</code>
            }
            @if (row.fields.length > 0) {
              <span class="text-on-surface-variant"
                >({{ row.fields | activityFields: kind() }})</span
              >
            }
          </li>
        }
      </ol>
    }
    <a
      class="underline"
      routerLink="/company/activity"
      [queryParams]="{ kind: kind(), record: recordId() }"
      data-testid="record-history-all"
    >
      {{ 'activity.history.all' | translate }}
    </a>
  `,
  host: { class: 'flex flex-col gap-4' },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecordHistory implements OnInit {
  private readonly api = inject(ActivityApi);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  /** The kind of record, as the journal names it: `customer`, `invoice`. */
  readonly kind = input.required<string>();
  readonly recordId = input.required<string>();

  protected readonly rows = signal<readonly ActivityRow[]>([]);
  protected readonly error = signal(false);
  protected readonly actionKey = actionKey;
  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  private request = 0;

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const kind = this.kind();
      const recordId = this.recordId();
      untracked(() => void this.load(companyId, kind, recordId));
    });
  }

  /** A change to the record, by someone else, is a new line of its history. */
  ngOnInit(): void {
    this.live.reloadOn(
      [this.kind()],
      () => this.load(this.companyId(), this.kind(), this.recordId()),
      this.destroyRef,
    );
  }

  private async load(companyId: string | null, kind: string, recordId: string): Promise<void> {
    if (companyId === null) return;
    const request = ++this.request;
    const search: ActivitySearch = {
      page: 1,
      itemsPerPage: HISTORY_SIZE,
      q: '',
      actorIds: [],
      entityTypes: [kind],
      entityId: recordId,
      intervals: {},
      direction: 'desc',
    };
    try {
      const page = await this.api.entries(companyId, search);
      if (request !== this.request) return;
      this.rows.set(page.rows);
      this.error.set(false);
    } catch {
      if (request === this.request) this.error.set(true);
    }
  }
}
