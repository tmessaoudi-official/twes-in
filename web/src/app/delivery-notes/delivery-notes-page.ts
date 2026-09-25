// SPDX-License-Identifier: AGPL-3.0-or-later

import { withdrawn } from '../shared/theme/lifecycle-tones';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell } from '../shared/list/data-list';
import type { StatusTone } from '../shared/theme/accent-theme';
import { StatusBadge } from '../shared/ui/status-badge';
import type { ListQuery } from '../shared/list/list-types';
import {
  DELIVERY_NOTES_LIST,
  type DeliveryNoteListRow,
  deliveryNoteListRows,
  deliveryNoteSearch,
} from './delivery-note-forms';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import {
  DELIVERY_NOTE_STATUS_TONES,
  DELIVERY_NOTE_STATUS_STAGES,
  type DeliveryNoteSearch,
  type DeliveryNoteStatus,
} from './delivery-notes-types';

/** The delivery notes of the company being worked in. */
@Component({
  selector: 'app-delivery-notes-page',
  imports: [
    MatButtonModule,
    RouterLink,
    TranslatePipe,
    AmountPipe,
    DayPipe,
    DataList,
    DataListCell,
    StatusBadge,
  ],
  templateUrl: './delivery-notes-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DeliveryNotesPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly facade = inject(DeliveryNotesFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = DELIVERY_NOTES_LIST;
  /** A list cell's row is untyped, so the tone is looked up through a typed function. */
  protected readonly struckOf = (status: DeliveryNoteStatus): boolean =>
    withdrawn(DELIVERY_NOTE_STATUS_STAGES[status]);
  protected readonly toneOf = (status: DeliveryNoteStatus): StatusTone =>
    DELIVERY_NOTE_STATUS_TONES[status];
  protected readonly rows = computed(() => deliveryNoteListRows(this.facade.notes()));
  protected readonly scale = computed(() => this.facade.options()?.currencyScale ?? null);
  protected readonly total = this.facade.total;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('delivery_note.write'));
  protected readonly rowTestId = (row: DeliveryNoteListRow): string => `delivery-note-${row.id}`;

  /** What the list last asked the API for; the page is not read until the list has said what it wants. */
  private search: DeliveryNoteSearch | null = null;

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(
        ['delivery_note', 'customer', 'invoice'],
        () => this.reload(companyId),
        this.destroyRef,
      );
      await this.facade.loadListContext(companyId);
    }
  }

  protected onQuery(query: ListQuery): void {
    const companyId = this.company()?.id;
    if (!companyId) return;
    this.search = deliveryNoteSearch(query);
    void this.facade.loadPage(companyId, this.search);
  }

  private async reload(companyId: string): Promise<void> {
    await Promise.all([
      this.facade.loadListContext(companyId),
      this.search === null ? Promise.resolve() : this.facade.loadPage(companyId, this.search),
    ]);
  }
}
