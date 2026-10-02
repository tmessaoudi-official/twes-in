// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { ProductsApi } from '../products/products-api';
import type { ProductSubstituteRow } from '../products/products-types';

interface Known {
  own: number;
  rows: readonly ProductSubstituteRow[];
}

/** "12.000" as a person writes it: "12". */
function plain(quantity: string): string {
  return quantity.includes('.') ? quantity.replace(/0+$/, '').replace(/\.$/, '') : quantity;
}

/**
 * The substitutes a line could take when its product is short: shown only when the quantity asked is more than is on
 * hand of the line's product AND some substitute has that much, so a product whose stock is not kept (which reads
 * zero everywhere) never raises it. A person without the right to read stock sees nothing, since "short" cannot be known.
 */
@Component({
  selector: 'app-line-substitutes',
  imports: [MatButtonModule, TranslatePipe],
  template: `
    @if (offered().length > 0) {
      <div class="flex flex-col gap-2 text-sm" data-testid="line-substitutes">
        <span>{{
          'delivery_notes.lines.substitutes.short' | translate: { quantity: ownText() }
        }}</span>
        <ul class="flex flex-wrap gap-2">
          @for (row of offered(); track row.id) {
            <li>
              <button
                mat-stroked-button
                type="button"
                (click)="swap.emit(row.id)"
                [attr.data-testid]="'line-substitute-' + row.reference"
              >
                {{
                  'delivery_notes.lines.substitutes.use'
                    | translate: { reference: row.reference, quantity: stockText(row) }
                }}
              </button>
            </li>
          }
        </ul>
      </div>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LineSubstitutes {
  private readonly api = inject(ProductsApi);
  private request = 0;

  readonly companyId = input.required<string>();
  readonly productId = input.required<string>();
  readonly quantity = input.required<string>();
  /** The id of the substitute the person chose to put on the line instead. */
  readonly swap = output<string>();

  private readonly known = signal<Known | null>(null);

  protected readonly offered = computed((): readonly ProductSubstituteRow[] => {
    const known = this.known();
    const need = Number(this.quantity());
    if (known === null || !(need > 0) || known.own >= need) return [];
    return known.rows.filter((row) => row.isActive && Number(row.onHand ?? 0) >= need);
  });

  protected readonly ownText = computed(() => plain(String(this.known()?.own ?? 0)));

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const productId = this.productId();
      void this.load(companyId, productId);
    });
  }

  protected stockText(row: ProductSubstituteRow): string {
    return row.onHand === null ? '' : plain(row.onHand);
  }

  private async load(companyId: string, productId: string): Promise<void> {
    const request = ++this.request;
    this.known.set(null);
    if (productId === '') return;
    try {
      const substitutes = await this.api.substitutes(companyId, productId);
      if (substitutes.length === 0) return;
      const totals = await this.api.stockTotals(companyId, [
        productId,
        ...substitutes.map((row) => row.id),
      ]);
      if (request !== this.request) return;
      this.known.set({
        own: Number(totals.get(productId) ?? 0),
        rows: substitutes.map((row) => ({ ...row, onHand: totals.get(row.id) ?? null })),
      });
    } catch {
      // Stock or the substitutes cannot be read here: nothing is offered rather than a guess.
    }
  }
}
