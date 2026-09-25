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
  /** A code a scan card sent here to be added (`?add=`), listed as a new row the person saves like any other. */
  readonly adding = input<string | undefined>(undefined);
  /**
   * Customer view (docs/SPEC.md § 7, 2026-09-23 slice 5): the suppliers' codes stay off the screen, and in the list a
   * save sends, so hiding them never erases them.
   */
  readonly hideSupplierCodes = input(false);

  protected readonly busy = this.facade.busy;
  protected readonly suppliers = this.facade.suppliers;
  protected readonly maxQuantity = BARCODE_QUANTITY_MAX;
  /** What the scan field holds, not yet a row: « Ajouter » and « Enregistrer » both take it. */
  protected readonly pending = signal('');
  /** The row a scan found already listed, pointed at instead of added twice. */
  protected readonly duplicate = signal<number | null>(null);

  /**
   * What the saved list SAYS, so a reload answering the same codes as new objects keeps what is being typed
   * (CLAUDE.md lesson, 2026-09-14): only another product or a different saved list starts the rows again.
   */
  private readonly savedKey = computed(() => `${this.productId()}|${JSON.stringify(this.saved())}`);
  private readonly rowsKey = computed(() => ({ saved: this.savedKey(), adding: this.adding() }), {
    equal: (a, b) => a.saved === b.saved && a.adding === b.adding,
  });
  protected readonly rows = linkedSignal<
    { saved: string; adding: string | undefined },
    ProductBarcode[]
  >({
    source: this.rowsKey,
    computation: (key, previous) =>
      untracked(() => {
        // A code sent while the same list is being edited (a scan on the product's own page, docs/SPEC.md § 7,
        // 2026-09-25 10:13) joins the rows not saved yet; another product or another saved list starts them again.
        const rows =
          previous !== undefined && previous.source.saved === key.saved
            ? previous.value
            : this.saved().map((row) => ({ ...row }));
        // Read-only, the section shows what is saved and never these rows.
        return key.adding === undefined ? rows : addScanned(rows, key.adding).rows;
      }),
  });

  /** The supplier's role is offered only where there is somebody to name, and outside customer view. */
  protected readonly roles = computed<readonly BarcodeRole[]>(() =>
    this.mayName() && !this.hideSupplierCodes()
      ? BARCODE_ROLES
      : BARCODE_ROLES.filter((role) => role !== 'supplier'),
  );
  protected readonly problems = computed(() => this.rows().map(rowProblem));
  protected readonly changed = computed(() => !sameCodes(this.rows(), this.saved()));
  protected readonly savable = computed(
    () =>
      (this.changed() || this.pending().trim() !== '') &&
      !this.busy() &&
      this.problems().every((problem) => problem === null),
  );
  /** The API's refusal, under the row it named. */
  protected readonly refusal = this.facade.refusal;

  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  /** A supplier's code is read and written with product.cost.read (docs/SPEC.md § 7, 2026-09-23 slice 5). */
  private readonly mayName = computed(
    () =>
      this.auth.hasModule('vendors') &&
      this.auth.hasPermission('vendor.read') &&
      this.auth.hasPermission('product.cost.read'),
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
    this.pending.set('');
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

  /**
   * A code typed and not yet added is part of what is saved (developer, 2026-09-25: « Enregistrer » stayed greyed over
   * a typed code and only Enter, pressed by chance, added it). A row it makes that is wrong stops the save under it.
   */
  protected async save(field: HTMLInputElement): Promise<void> {
    const companyId = this.companyId();
    if (companyId === null || !this.savable()) return;
    if (field.value.trim() !== '') {
      this.add(field);
      if (!this.savable()) return;
    }
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
