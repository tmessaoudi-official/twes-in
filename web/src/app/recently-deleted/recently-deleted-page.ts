// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { MomentPipe } from '../shared/i18n/format-pipes';
import { LiveChanges } from '../shared/realtime/live-changes';
import { DELETABLE_SUBJECTS, RecordDeletes } from '../shared/records/record-deletes';
import { RecordRemovalsApi, type RecordRemoval } from '../shared/records/record-removals-api';

/** The record removal's own kind: a delete that became final leaves the list. */
const REMOVAL_KIND = 'record_removal';

/**
 * « Récemment supprimés »: every delete still restorable, of what the person may write, the newest first. A delete is
 * put back whole, as it was, until its day is out; the API keeps the rest from the person, so this only lists.
 */
@Component({
  selector: 'app-recently-deleted-page',
  imports: [MatButtonModule, MatIconModule, MomentPipe, TranslatePipe],
  templateUrl: './recently-deleted-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class RecentlyDeletedPage implements OnInit {
  private readonly api = inject(RecordRemovalsApi);
  private readonly deletes = inject(RecordDeletes);
  private readonly auth = inject(AuthFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  /** Null until the first read answers. */
  protected readonly removals = signal<readonly RecordRemoval[] | null>(null);
  protected readonly failed = signal(false);
  /** The delete being put back: every button waits for it, so no second restore starts meanwhile. */
  protected readonly restoring = signal<string | null>(null);

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.live.reloadOn(
      [REMOVAL_KIND, ...DELETABLE_SUBJECTS],
      () => this.load(companyId),
      this.destroyRef,
    );
    await this.load(companyId);
  }

  protected async restore(removal: RecordRemoval): Promise<void> {
    const companyId = this.auth.me()?.company?.id;
    if (!companyId) return;
    this.restoring.set(removal.id);
    try {
      // The toast says what came back or why not. Read again either way: a refusal may mean its day ran out meanwhile.
      await this.deletes.restore(companyId, removal);
      await this.load(companyId);
    } finally {
      this.restoring.set(null);
    }
  }

  private async load(companyId: string): Promise<void> {
    try {
      this.removals.set(await this.api.pending(companyId));
      this.failed.set(false);
    } catch {
      this.failed.set(true);
    }
  }
}
