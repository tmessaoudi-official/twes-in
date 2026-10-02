// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  type OnInit,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { ProductSubstitutes } from './product-substitutes-facade';
import type { ProductSubstituteRow } from './products-types';

/** "12.000" as a person writes it: "12". */
function plain(quantity: string): string {
  return quantity.includes('.') ? quantity.replace(/0+$/, '').replace(/\.$/, '') : quantity;
}

/**
 * The other products of this product's substitution group, with what is on hand of each: what a person reaches for
 * when this one is out. It only reads; the group itself is the name set on the product's form.
 */
@Component({
  selector: 'app-product-substitutes',
  imports: [RouterLink, TranslatePipe],
  templateUrl: './product-substitutes.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductSubstitutesSection implements OnInit {
  private readonly substitutes = inject(ProductSubstitutes);
  private readonly auth = inject(AuthFacade);

  readonly productId = input.required<string>();
  /** The group the product was saved with; empty when it has none. */
  readonly group = input<string | null>(null);

  protected readonly rows = this.substitutes.rows;
  protected readonly busy = this.substitutes.busy;
  protected readonly error = this.substitutes.error;
  protected readonly grouped = computed(() => (this.group() ?? '') !== '');

  async ngOnInit(): Promise<void> {
    const companyId = this.auth.me()?.company?.id ?? null;
    if (companyId !== null) await this.substitutes.load(companyId, this.productId());
  }

  protected stockOf(row: ProductSubstituteRow): string | null {
    return row.onHand === null ? null : plain(row.onHand);
  }
}
