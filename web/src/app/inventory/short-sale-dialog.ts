// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject, Injectable } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import {
  MAT_DIALOG_DATA,
  MatDialog,
  MatDialogModule,
  MatDialogRef,
} from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { ACTION_KIND_ICONS } from '../shared/actions/screen-action';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { TextsParts } from '../shared/i18n/texts-part';
import { countAddress, movementAddress } from './inventory-types';
import { STOCK_MOVE_TEXTS } from './stock-move-texts';
import { shortSaleIn, type ShortSaleLine } from './short-sales';

export interface ShortSaleDialogData {
  readonly lines: readonly ShortSaleLine[];
  readonly confirmable: boolean;
  /** Whether the person keeps the stock, and so may count it or receive the goods. */
  readonly mayWrite: boolean;
}

/**
 * A sale taking more than the stock recorded, said before it is kept: each product short, what the sale wants and what
 * its places hold, with « Compter » and « Recevoir » at the place where that puts it right. Where the company asks for a
 * confirmation and the person may give it, the sale goes on below zero, which a count corrects later; otherwise it is
 * only said. A link closes the dialog as it opens its page: an overlay leaves on the browser's back and forward alone.
 */
@Component({
  selector: 'app-short-sale-dialog',
  imports: [MatButtonModule, MatDialogModule, MatIconModule, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="short-sale-title">
      {{
        (data.confirmable ? 'stock_moves.short.title_confirm' : 'stock_moves.short.title_refused')
          | translate
      }}
    </h2>
    <mat-dialog-content>
      <p data-testid="short-sale-intro">
        {{
          (data.confirmable ? 'stock_moves.short.intro_confirm' : 'stock_moves.short.intro_refused')
            | translate
        }}
      </p>
      <ul class="mt-3 flex flex-col gap-3">
        @for (line of data.lines; track line.productId) {
          <li class="flex flex-col gap-1" data-testid="short-sale-line">
            <span>{{ 'stock_moves.short.line' | translate: line.params }}</span>
            @if (data.mayWrite) {
              <span class="flex flex-wrap gap-2">
                <a
                  mat-stroked-button
                  [routerLink]="count(line).path"
                  [queryParams]="count(line).query"
                  (click)="ref.close(false)"
                  data-testid="short-sale-count"
                  >{{ 'stock_moves.short.count' | translate }}</a
                >
                <a
                  mat-stroked-button
                  [routerLink]="receive(line).path"
                  [queryParams]="receive(line).query"
                  (click)="ref.close(false)"
                  data-testid="short-sale-receive"
                  >{{ 'stock_moves.short.receive' | translate }}</a
                >
              </span>
            }
          </li>
        }
      </ul>
      @if (!data.mayWrite) {
        <p class="mt-3 text-sm text-on-surface-variant" data-testid="short-sale-ask">
          {{ 'stock_moves.short.ask_writer' | translate }}
        </p>
      }
      @if (data.confirmable) {
        <p
          class="mt-3 flex items-start gap-2 text-sm"
          data-testid="short-sale-kind"
          data-kind="corrigeable"
        >
          <mat-icon aria-hidden="true" class="shrink-0">{{ icons.corrigeable }}</mat-icon>
          <span
            ><strong>{{ 'actions.kind.corrigeable' | translate }}</strong> ·
            {{ 'actions.kind_help.corrigeable' | translate }}</span
          >
        </p>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      @if (data.confirmable) {
        <button mat-button type="button" (click)="ref.close(false)" data-testid="short-sale-keep">
          {{ 'stock_moves.short.keep' | translate }}
        </button>
        <button
          mat-flat-button
          type="button"
          (click)="ref.close(true)"
          data-testid="short-sale-confirm"
        >
          {{ 'stock_moves.short.confirm' | translate }}
        </button>
      } @else {
        <button mat-button type="button" (click)="ref.close(false)" data-testid="short-sale-close">
          {{ 'stock_moves.short.close' | translate }}
        </button>
      }
    </mat-dialog-actions>
  `,
})
export class ShortSaleDialog {
  protected readonly ref = inject<MatDialogRef<ShortSaleDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<ShortSaleDialogData>(MAT_DIALOG_DATA);
  protected readonly icons = ACTION_KIND_ICONS;

  protected count(line: ShortSaleLine): ReturnType<typeof countAddress> {
    return countAddress(line.productId, line.locationId);
  }

  protected receive(line: ShortSaleLine): ReturnType<typeof movementAddress> {
    return movementAddress('receive', line.productId, line.locationId);
  }
}

/**
 * What a delivery note's validation or an invoice's issue does when the API held it back on stock: the dialog above,
 * and whether to send it again confirmed. Anything else the API refused is left to the page, which says it on its field.
 */
@Injectable({ providedIn: 'root' })
export class ShortSales {
  private readonly dialog = inject(MatDialog);
  private readonly texts = inject(TextsParts);
  private readonly figures = inject(RefusalFigures);
  private readonly auth = inject(AuthFacade);

  /** True only when the person confirmed a sale the company lets them confirm. */
  async confirmed(refusals: readonly FieldRefusal[]): Promise<boolean> {
    const sale = shortSaleIn(refusals);
    if (sale === null) return false;
    try {
      await this.texts.open(STOCK_MOVE_TEXTS);
    } catch {
      // Its words could not be fetched (the network gone since the answer): the page then says each line's refusal
      // under it, in the form's own texts, and nothing is sold beyond the stock unasked.
      return false;
    }
    const answer = await firstValueFrom(
      this.dialog
        .open<ShortSaleDialog, ShortSaleDialogData, boolean>(ShortSaleDialog, {
          data: {
            lines: sale.lines.map((line) => ({
              ...line,
              params: this.figures.written(line.params),
            })),
            confirmable: sale.confirmable,
            mayWrite: this.auth.hasModule('inventory') && this.auth.hasPermission('stock.write'),
          },
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
    return sale.confirmable && answer === true;
  }
}
