// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  type OnInit,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { DecimalInput } from '../shared/form/decimal-input';
import { ProductReorderPoints } from './product-reorder-points-facade';
import type { ProductReorderPointRow } from './products-types';

/** A quantity from 0 with at most three decimals, as the API takes it; whether the unit counts that finely is its rule. */
const QUANTITY = /^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/;

/** "12.000" as a person writes it: "12". */
function plain(quantity: string | null): string {
  if (quantity === null) return '';
  return quantity.includes('.') ? quantity.replace(/0+$/, '').replace(/\.$/, '') : quantity;
}

/**
 * The quantity at or under which the product is to be reordered, in each establishment (docs/SPEC.md § 7, 2026-09-24
 * 11:40). Empty means no alert there; the alert itself reads it elsewhere. Clearing is an ordinary button: nothing is
 * lost that one step cannot put back.
 */
@Component({
  selector: 'app-product-reorder-points',
  imports: [
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    TranslatePipe,
    Label,
    DecimalInput,
  ],
  templateUrl: './product-reorder-points.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductReorderPointsSection implements OnInit {
  private readonly points = inject(ProductReorderPoints);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  readonly productId = input.required<string>();
  readonly readOnly = input(false);

  protected readonly rows = this.points.rows;
  protected readonly busy = this.points.busy;
  protected readonly error = this.points.error;
  /** What is typed per establishment, until it is saved. */
  private readonly typed = signal<Record<string, string>>({});

  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);

  async ngOnInit(): Promise<void> {
    const companyId = this.companyId();
    if (companyId !== null) await this.points.load(companyId, this.productId());
  }

  protected valueOf(row: ProductReorderPointRow): string {
    return this.typed()[row.establishmentId] ?? plain(row.quantity);
  }

  protected type(row: ProductReorderPointRow, value: string): void {
    this.typed.update((typed) => ({ ...typed, [row.establishmentId]: value }));
  }

  /** Whether what is typed is a quantity the API would take, and differs from what is kept. */
  protected savable(row: ProductReorderPointRow): boolean {
    const value = this.valueOf(row).trim();
    return QUANTITY.test(value) && value !== plain(row.quantity);
  }

  protected malformed(row: ProductReorderPointRow): boolean {
    const value = this.valueOf(row).trim();
    return value !== '' && !QUANTITY.test(value);
  }

  protected async save(row: ProductReorderPointRow): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null || !this.savable(row)) return;
    const quantity = this.valueOf(row).trim();
    if (await this.points.set(companyId, this.productId(), row.establishmentId, quantity)) {
      this.forget(row);
      this.feedback.success('products.reorder.saved');
    }
  }

  protected async clear(row: ProductReorderPointRow): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    if (await this.points.clear(companyId, this.productId(), row.establishmentId)) {
      this.forget(row);
      this.feedback.success('products.reorder.cleared');
    }
  }

  private forget(row: ProductReorderPointRow): void {
    this.typed.update((typed) => {
      const rest = { ...typed };
      delete rest[row.establishmentId];
      return rest;
    });
  }
}
