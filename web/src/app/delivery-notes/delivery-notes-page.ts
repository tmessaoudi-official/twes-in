// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { AmountPipe, DayPipe } from '../shared/i18n/format-pipes';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import {
  DELIVERY_NOTES_LIST,
  type DeliveryNoteListRow,
  deliveryNoteListRows,
} from './delivery-note-forms';
import { DeliveryNotesFacade } from './delivery-notes-facade';

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
    DataListRowActions,
  ],
  templateUrl: './delivery-notes-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DeliveryNotesPage implements OnInit {
  private readonly facade = inject(DeliveryNotesFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = DELIVERY_NOTES_LIST;
  protected readonly rows = computed(() =>
    deliveryNoteListRows(this.facade.notes(), this.facade.options()),
  );
  protected readonly scale = computed(() => this.facade.options()?.currencyScale ?? null);
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('delivery_note.write'));
  protected readonly rowTestId = (row: DeliveryNoteListRow): string => `delivery-note-${row.id}`;

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.loadList(companyId);
    }
  }
}
