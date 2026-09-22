// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
  type OnInit,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { addScanned, BARCODE_QUANTITY_MAX, rowProblem, sameCodes, withRole } from './barcode-rows';
import { ProductBarcodes } from './product-barcodes-facade';
import { BARCODE_ROLES, type BarcodeRole, type ProductBarcode } from './products-types';

/**
 * The codes a product answers to (docs/SPEC.md § 7, 2026-09-22 11:05): its unit code, the packs that enter several at
 * once, a supplier's own carton, a code the company printed. Built for a scanner first: the field at the top keeps
 * the focus, each code read and Enter adds a row, and the next scan goes straight in.
 */
@Component({
  selector: 'app-product-barcodes',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    MatSelectModule,
    TranslatePipe,
    Label,
  ],
  templateUrl: './product-barcodes.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [ProductBarcodes],
})
export class ProductBarcodesSection implements OnInit {
  private readonly facade = inject(ProductBarcodes);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);

  readonly productId = input.required<string>();
  /** What the API holds for the product. */
  readonly saved = input.required<readonly ProductBarcode[]>();
  readonly readOnly = input(false);

  protected readonly busy = this.facade.busy;
  protected readonly suppliers = this.facade.suppliers;
  protected readonly maxQuantity = BARCODE_QUANTITY_MAX;
  /** The row a scan found already listed, pointed at instead of added twice. */
  protected readonly duplicate = signal<number | null>(null);

  /**
   * What the saved list SAYS, so a reload answering the same codes as new objects keeps what is being typed
   * (CLAUDE.md lesson, 2026-09-14): only another product or a different saved list starts the rows again.
   */
  private readonly savedKey = computed(() => `${this.productId()}|${JSON.stringify(this.saved())}`);
  protected readonly rows = linkedSignal<string, ProductBarcode[]>({
    source: this.savedKey,
    computation: () => untracked(() => this.saved().map((row) => ({ ...row }))),
  });

  /** The supplier's role is offered only where there is somebody to name. */
  protected readonly roles = computed<readonly BarcodeRole[]>(() =>
    this.mayName() ? BARCODE_ROLES : BARCODE_ROLES.filter((role) => role !== 'supplier'),
  );
  protected readonly problems = computed(() => this.rows().map(rowProblem));
  protected readonly changed = computed(() => !sameCodes(this.rows(), this.saved()));
  protected readonly savable = computed(
    () => this.changed() && !this.busy() && this.problems().every((problem) => problem === null),
  );
  /** The API's refusal, under the row it named. */
  protected readonly refusal = this.facade.refusal;

  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  private readonly mayName = computed(
    () => this.auth.hasModule('vendors') && this.auth.hasPermission('vendor.read'),
  );

  async ngOnInit(): Promise<void> {
    const companyId = this.companyId();
    if (companyId !== null && !this.readOnly() && this.mayName()) {
      await this.facade.loadSuppliers(companyId);
    }
  }

  /**
   * What the scan field holds when Enter comes, read from the field itself: a scanner types a whole code and Enter in
   * a few milliseconds, and nothing between the keystrokes and this read may lag behind them. The field is emptied for
   * the next scan and keeps the focus.
   */
  protected add(field: HTMLInputElement): void {
    const { rows, duplicate } = addScanned(this.rows(), field.value);
    this.duplicate.set(duplicate);
    this.rows.set(rows);
    field.value = '';
    this.facade.forget();
  }

  protected setCode(index: number, code: string): void {
    this.edit(index, (row) => ({ ...row, code }));
  }

  protected setRole(index: number, role: BarcodeRole): void {
    this.edit(index, (row) => withRole(row, role));
  }

  protected setQuantity(index: number, quantity: number): void {
    this.edit(index, (row) => ({ ...row, quantity: Number.isNaN(quantity) ? 0 : quantity }));
  }

  protected setSupplier(index: number, supplierId: string | null): void {
    this.edit(index, (row) => ({ ...row, supplierId }));
  }

  protected remove(index: number): void {
    this.rows.update((rows) => rows.filter((_, at) => at !== index));
    this.duplicate.set(null);
    this.facade.forget();
  }

  protected revert(): void {
    this.rows.set(this.saved().map((row) => ({ ...row })));
    this.duplicate.set(null);
    this.facade.forget();
  }

  protected async save(): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null || !this.savable()) return;
    if (await this.facade.save(companyId, this.productId(), this.rows())) {
      this.feedback.success('products.barcodes.saved');
    }
  }

  private edit(index: number, change: (row: ProductBarcode) => ProductBarcode): void {
    this.rows.update((rows) => rows.map((row, at) => (at === index ? change(row) : row)));
    this.duplicate.set(null);
    this.facade.forget();
  }
}
