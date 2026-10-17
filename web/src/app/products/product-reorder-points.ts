// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  type OnInit,
  output,
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
import { PartConflict } from '../shared/form/part-conflict';
import { LiveChanges } from '../shared/realtime/live-changes';
import { ProductReorderPoints, type ReorderPointWrite } from './product-reorder-points-facade';
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
 * lost that one step cannot put back. Another person's change is read again as it happens; a point being typed is kept
 * over it, and the conflict says so (docs/SPEC.md § 7, 2026-09-17).
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
    PartConflict,
  ],
  templateUrl: './product-reorder-points.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProductReorderPointsSection implements OnInit {
  private readonly points = inject(ProductReorderPoints);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  readonly productId = input.required<string>();
  /** The product's version, which a write sends back: the points are shown beside the product form and move it. */
  readonly version = input.required<number>();
  readonly readOnly = input(false);
  /**
   * The product's version moved here (`saved`), or a write was refused as made over one saved since (`changed`): the
   * product page reads the product again, so its form stands on the version the points left.
   */
  readonly moved = output<'saved' | 'changed'>();

  protected readonly rows = this.points.rows;
  protected readonly busy = this.points.busy;
  protected readonly error = this.points.error;
  /** What is typed per establishment, until it is saved. */
  private readonly typed = signal<Record<string, string>>({});
  /** The point each typed value was typed over: one saved since by someone else is a conflict to resolve. */
  private readonly bases = signal<Record<string, string | null>>({});

  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);

  constructor() {
    inject(LiveChanges).reloadOn(['product_reorder_point'], () => this.load(), inject(DestroyRef));
  }

  async ngOnInit(): Promise<void> {
    await this.load();
  }

  private async load(): Promise<void> {
    const companyId = this.companyId();
    if (companyId !== null) await this.points.load(companyId, this.productId());
  }

  protected valueOf(row: ProductReorderPointRow): string {
    return this.typed()[row.establishmentId] ?? plain(row.quantity);
  }

  protected type(row: ProductReorderPointRow, value: string): void {
    if (!(row.establishmentId in this.bases())) {
      this.bases.update((bases) => ({ ...bases, [row.establishmentId]: row.quantity }));
    }
    this.typed.update((typed) => ({ ...typed, [row.establishmentId]: value }));
  }

  /** A point saved by someone else while this one was being typed. */
  protected changedSince(row: ProductReorderPointRow): boolean {
    const bases = this.bases();
    return row.establishmentId in bases && bases[row.establishmentId] !== row.quantity;
  }

  /** Theirs replaces what was typed; mine keeps it, to be saved over theirs. */
  protected resolve(row: ProductReorderPointRow, choice: 'theirs' | 'mine'): void {
    if (choice === 'theirs') this.forget(row);
    else this.bases.update((bases) => ({ ...bases, [row.establishmentId]: row.quantity }));
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
    const outcome = await this.points.set(
      companyId,
      this.productId(),
      row.establishmentId,
      quantity,
      this.version(),
    );
    if (outcome === 'saved') {
      this.forget(row);
      this.feedback.success('products.reorder.saved');
    }
    this.told(outcome);
  }

  protected async clear(row: ProductReorderPointRow): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null) return;
    const outcome = await this.points.clear(companyId, this.productId(), row.establishmentId);
    if (outcome === 'saved') {
      this.forget(row);
      this.feedback.success('products.reorder.cleared');
    }
    this.told(outcome);
  }

  private told(outcome: ReorderPointWrite): void {
    if (outcome !== 'refused') this.moved.emit(outcome);
  }

  private forget(row: ProductReorderPointRow): void {
    const without = <T>(record: Record<string, T>): Record<string, T> => {
      const rest = { ...record };
      delete rest[row.establishmentId];
      return rest;
    };
    this.typed.update(without);
    this.bases.update(without);
  }
}
