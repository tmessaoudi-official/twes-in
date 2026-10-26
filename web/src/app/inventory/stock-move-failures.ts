// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  type OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialog } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { runAction } from '../shared/actions/run-action';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { saidAlready } from '../shared/errors/said-already';
import { Feedback } from '../shared/feedback/feedback';
import { MomentPipe } from '../shared/i18n/format-pipes';
import { TextsParts } from '../shared/i18n/texts-part';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { InventoryFacade } from './inventory-facade';
import type { StockMoveFailureRow } from './inventory-types';
import { STOCK_MOVE_TEXTS } from './stock-move-texts';

/**
 * A document's stock move that failed after the document was kept, said on the document's own page with what puts it
 * right (workflow hunt, CON-1): « Remettre le stock à jour » makes the move again, which moves nothing twice; « C'est
 * réglé » forgets it once the stock was set right otherwise. Shown only where a failure is recorded, to whoever reads
 * the stock; only a stock writer is offered the two. It reads itself again on every change of the stock, in this tab
 * or another, so a failure settled elsewhere leaves this page too.
 */
@Component({
  selector: 'app-stock-move-failures',
  imports: [TranslatePipe, MatButtonModule, MomentPipe],
  template: `
    @if (failures().length > 0) {
      <section class="flex flex-col gap-2" data-testid="stock-move-failures">
        @for (failure of failures(); track failure.id) {
          <div class="flex flex-wrap items-center gap-2" data-testid="stock-move-failure">
            <p class="text-error">
              {{
                'stock_moves.failed.' + failure.move
                  | translate: { moment: failure.failedAt | moment }
              }}
            </p>
            @if (mayWrite()) {
              <button
                mat-flat-button
                type="button"
                [disabled]="busy()"
                (click)="replay(failure)"
                data-testid="stock-move-replay"
              >
                {{ 'stock_moves.replay' | translate }}
              </button>
              <button
                mat-stroked-button
                type="button"
                [disabled]="busy()"
                (click)="settle(failure)"
                data-testid="stock-move-settle"
              >
                {{ 'stock_moves.settle' | translate }}
              </button>
            } @else {
              <p class="text-sm text-on-surface-variant" data-testid="stock-move-ask">
                {{ 'stock_moves.ask_writer' | translate }}
              </p>
            }
          </div>
        }
      </section>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StockMoveFailures implements OnInit {
  private readonly facade = inject(InventoryFacade);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly dialog = inject(MatDialog);
  private readonly live = inject(LiveChanges);
  private readonly texts = inject(TextsParts);
  private readonly destroyRef = inject(DestroyRef);

  /** The delivery note, invoice or credit note whose page this is. */
  readonly documentId = input.required<string>();

  protected readonly failures = signal<readonly StockMoveFailureRow[]>([]);
  protected readonly busy = signal(false);
  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  private readonly mayRead = computed(
    () => this.auth.hasModule('inventory') && this.auth.hasPermission('stock.read'),
  );
  protected readonly mayWrite = computed(() => this.auth.hasPermission('stock.write'));
  private request = 0;

  async ngOnInit(): Promise<void> {
    if (!this.mayRead()) return;
    // Read on two pages: opened here rather than by both routes, and drawn once they arrive.
    void this.texts.open(STOCK_MOVE_TEXTS);
    this.live.reloadOn(['stock'], () => this.read(), this.destroyRef);
    await this.read();
  }

  protected async replay(failure: StockMoveFailureRow): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null || this.busy()) return;
    this.busy.set(true);
    try {
      const moved = await this.facade.replayMove(companyId, failure.id);
      if (moved) this.feedback.success('stock_moves.replayed', { number: failure.number });
      else this.feedback.failure('stock_moves.failed_again', { number: failure.number });
    } catch (error) {
      this.refused(error);
    } finally {
      this.busy.set(false);
    }
    await this.read();
  }

  /** Forgetting it moves nothing, and the stock can still be adjusted afterwards: corrected, not undone. */
  protected settle(failure: StockMoveFailureRow): void {
    if (this.busy()) return;
    runAction(
      {
        run: () => void this.settled(failure),
        confirm: {
          kind: 'corrigeable',
          title: 'stock_moves.ask.title',
          message: 'stock_moves.ask.message',
          messageParams: { number: failure.number },
          confirmLabel: 'stock_moves.settle',
          keepLabel: 'stock_moves.ask.keep',
        },
      },
      (confirm) =>
        this.dialog
          .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
            data: confirm,
            autoFocus: 'dialog',
          })
          .afterClosed(),
    );
  }

  private async settled(failure: StockMoveFailureRow): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    this.busy.set(true);
    try {
      await this.facade.settleMove(companyId, failure.id);
      this.feedback.effect('stock_moves.settled', { number: failure.number }, 'corrigeable');
    } catch (error) {
      this.refused(error);
    } finally {
      this.busy.set(false);
    }
    await this.read();
  }

  private refused(error: unknown): void {
    if (!saidAlready(error)) this.feedback.failure('stock_moves.refused');
  }

  /** Only the latest question's answer is kept; one that could not be read leaves what was shown. */
  private async read(): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    const request = ++this.request;
    const failures = await this.facade.failedMoves(companyId, this.documentId());
    if (request === this.request && failures !== null) this.failures.set(failures);
  }
}
