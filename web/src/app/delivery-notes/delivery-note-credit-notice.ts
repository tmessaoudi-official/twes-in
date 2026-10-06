// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import type { DeliveryNoteCredit } from './delivery-notes-types';

/**
 * Says so when delivering a note would take its customer past their credit limit (docs/SPEC.md § 7). It warns and
 * never blocks: the person delivering decides. When the position could not be read it says the limit was not checked,
 * rather than nothing, which would read as « within the limit ». Read again whenever the note's total or status changes, because both
 * change the answer.
 */
@Component({
  selector: 'app-delivery-note-credit-notice',
  imports: [TranslatePipe, AmountPipe],
  template: `
    @if (credit(); as c) {
      @if (c.over) {
        <p role="alert" class="text-error" data-testid="delivery-note-credit-over">
          {{
            'delivery_notes.credit_over'
              | translate
                : {
                    owed: c.owed | amount: scale(),
                    total: c.noteTotal | amount: scale(),
                    limit: c.limit | amount: scale(),
                    currency: currency(),
                  }
          }}
        </p>
      }
    } @else if (credit() === null) {
      <p class="text-sm text-on-surface-variant" data-testid="delivery-note-credit-unchecked">
        {{ 'delivery_notes.credit_unchecked' | translate }}
      </p>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DeliveryNoteCreditNotice {
  private readonly facade = inject(DeliveryNotesFacade);

  readonly companyId = input.required<string>();
  readonly noteId = input.required<string>();
  /** Only here to read again when the note changes. */
  readonly status = input.required<string>();
  readonly total = input.required<string>();
  readonly currency = input.required<string>();
  readonly scale = input.required<number>();

  /** The position; null when it could not be read, which is said, since the warning exists to stop a delivery. */
  protected readonly credit = signal<DeliveryNoteCredit | null | undefined>(undefined);
  private request = 0;

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const noteId = this.noteId();
      this.status();
      this.total();
      untracked(() => void this.read(companyId, noteId));
    });
  }

  /** Only the latest question's answer is kept, as for every read that a change can send twice. */
  private async read(companyId: string, noteId: string): Promise<void> {
    const request = ++this.request;
    const credit = await this.facade.credit(companyId, noteId);
    if (request === this.request) this.credit.set(credit);
  }
}
