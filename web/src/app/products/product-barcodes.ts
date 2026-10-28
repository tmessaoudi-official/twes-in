// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  input,
  linkedSignal,
  type OnInit,
  output,
  signal,
  untracked,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { FormsModule } from '@angular/forms';
import { NgTemplateOutlet } from '@angular/common';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Label } from '../shared/a11y/label';
import { Feedback } from '../shared/feedback/feedback';
import { PartConflict } from '../shared/form/part-conflict';
import { Select } from '../shared/form/select';
import { dirtyCount } from '../shared/form/dirty-count';
import { refusalParams } from '../shared/form/refusal-words';
import { RefusedField } from '../shared/form/refused-field';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import {
  addScanned,
  BARCODE_QUANTITY_MAX,
  CODE_PROBLEMS,
  QUANTITY_PROBLEMS,
  rowProblem,
  sameCodes,
  withRole,
} from './barcode-rows';
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
    FormsModule,
    Select,
    TranslatePipe,
    Label,
    PartConflict,
    RefusedField,
    NgTemplateOutlet,
  ],
  templateUrl: './product-barcodes.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [ProductBarcodes],
})
export class ProductBarcodesSection implements OnInit {
  private readonly facade = inject(ProductBarcodes);
  private readonly auth = inject(AuthFacade);
  private readonly feedback = inject(Feedback);
  private readonly translate = inject(TranslateService);

  readonly productId = input.required<string>();
  /** What the API holds for the product. */
  readonly saved = input.required<readonly ProductBarcode[]>();
  /** The product's version, which a save sends back: the codes are shown beside the product form and move it. */
  readonly version = input.required<number>();
  readonly readOnly = input(false);
  /** A code a scan card sent here to be added (`?add=`), listed as a new row the person saves like any other. */
  readonly adding = input<string | undefined>(undefined);
  /**
   * The product's version moved here (`saved`), or a save was refused as made over one saved since (`changed`): the
   * product page reads the product again, so its form stands on the version the codes left.
   */
  readonly moved = output<'saved' | 'changed'>();

  protected readonly busy = this.facade.busy;
  protected readonly suppliers = this.facade.suppliers;
  protected readonly maxQuantity = BARCODE_QUANTITY_MAX;
  /** What the scan field holds, not yet a row: « Ajouter » and « Enregistrer » both take it. */
  protected readonly pending = signal('');
  /** The row a scan found already listed, pointed at instead of added twice. */
  protected readonly duplicate = signal<number | null>(null);

  /**
   * What the saved list SAYS, so a reload answering the same codes as new objects keeps what is being typed
   * (CLAUDE.md lesson, 2026-09-14).
   */
  private readonly savedKey = computed(() => `${this.productId()}|${JSON.stringify(this.saved())}`);
  private readonly rowsKey = computed(
    () => ({
      productId: this.productId(),
      saved: this.savedKey(),
      list: this.saved(),
      adding: this.adding(),
    }),
    { equal: (a, b) => a.saved === b.saved && a.adding === b.adding },
  );
  /**
   * The rows being edited and the saved list they stand on. Another product starts them again; a list saved since by
   * someone else replaces rows nobody touched, and rows being typed are kept while the conflict says so (a form being
   * typed in keeps what is typed and shows the banner).
   */
  private readonly draft = linkedSignal<
    {
      productId: string;
      saved: string;
      list: readonly ProductBarcode[];
      adding: string | undefined;
    },
    { rows: ProductBarcode[]; base: string }
  >({
    source: this.rowsKey,
    computation: (key, previous) =>
      untracked(() => {
        let draft = { rows: key.list.map((row) => ({ ...row })), base: key.saved };
        if (previous !== undefined && previous.source.productId === key.productId) {
          const kept = previous.value;
          const typed =
            !sameCodes(kept.rows, previous.source.list) && !sameCodes(kept.rows, key.list);
          // A code sent while the same list is being edited (a scan on the product's own page, docs/SPEC.md § 7,
          // 2026-09-25 10:13) joins the rows not saved yet, as do rows typed over a list saved since.
          if (kept.base === key.saved || typed) draft = kept;
        }
        // Read-only, the section shows what is saved and never these rows.
        return key.adding === undefined
          ? draft
          : { ...draft, rows: addScanned(draft.rows, key.adding).rows };
      }),
  });
  protected readonly rows = computed(() => this.draft().rows);
  /** Codes saved by someone else while these were being typed: theirs replace these, or these are kept. */
  protected readonly changedSince = computed(() => this.draft().base !== this.savedKey());

  /** The supplier's role is offered only where there is somebody to name. */
  protected readonly roles = computed<readonly BarcodeRole[]>(() =>
    this.mayName() ? BARCODE_ROLES : BARCODE_ROLES.filter((role) => role !== 'supplier'),
  );
  protected readonly roleOptions = computed(() =>
    this.roles().map((role) => ({ value: role, label: `products.barcodes.roles.${role}` })),
  );
  protected readonly supplierOptions = computed(() =>
    this.suppliers().map((supplier) => ({ value: supplier.id, label: supplier.label })),
  );
  protected readonly problems = computed(() => this.rows().map(rowProblem));
  protected readonly changed = computed(() => !sameCodes(this.rows(), this.saved()));
  /** A code scanned and not added, and every field of the rows that differs from what is saved. */
  private readonly unsaved = computed(
    () =>
      (this.pending().trim() === '' ? 0 : 1) +
      (this.changed() ? dirtyCount(this.rows(), this.saved()) : 0),
  );
  protected readonly savable = computed(
    () =>
      (this.changed() || this.pending().trim() !== '') &&
      !this.busy() &&
      this.problems().every((problem) => problem === null),
  );
  /** The API's refusal, under the row it named. */
  protected readonly refusal = this.facade.refusal;
  /**
   * Where a row's problem is said and outlined: under the field it concerns, the code or the quantity, or under the
   * row for one no field there holds (a supplier's code naming no supplier, the role or supplier the API refused). The
   * row's own rule first, then the API's refusal of that row, which names its field (none naming the code).
   */
  protected problemAt(index: number): 'code' | 'quantity' | 'row' | null {
    const problem = this.problems()[index];
    if (problem !== null) {
      if (CODE_PROBLEMS.includes(problem)) return 'code';
      return QUANTITY_PROBLEMS.includes(problem) ? 'quantity' : 'row';
    }
    const refused = this.refusal();
    if (refused === null || refused.index !== index) return null;
    if (refused.field === 'quantity') return 'quantity';
    return refused.field === null || refused.field === 'code' ? 'code' : 'row';
  }
  /** What the refusal's reason says, as every form words it; null for a reason no text words. */
  protected readonly refusalText = computed(() => {
    const reason = this.refusal()?.reason ?? null;
    if (reason === null) return null;
    const key = `import.rejections.${reason}`;
    return this.translate.instant(key) === key
      ? null
      : { key, params: refusalParams(this.translate, this.refusal()?.params ?? {}) };
  });

  private readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  /** A supplier's code is read and written with product.cost.read (docs/SPEC.md § 7, 2026-09-23 slice 5). */
  private readonly mayName = computed(
    () =>
      this.auth.hasModule('vendors') &&
      this.auth.hasPermission('vendor.read') &&
      this.auth.hasPermission('product.cost.read'),
  );

  constructor() {
    inject(UnsavedChanges).declare(this.unsaved);
    // A vendor added or renamed elsewhere is offered as the supplier of a code at once.
    inject(LiveChanges).reloadOn(
      ['vendor'],
      async () => {
        const companyId = this.companyId();
        if (companyId !== null && !this.readOnly() && this.mayName()) {
          await this.facade.loadSuppliers(companyId);
        }
      },
      inject(DestroyRef),
    );
  }

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
    this.setRows(rows);
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
    this.setRows(this.rows().filter((_, at) => at !== index));
    this.duplicate.set(null);
    this.facade.forget();
  }

  protected revert(): void {
    this.draft.set({ rows: this.saved().map((row) => ({ ...row })), base: this.savedKey() });
    this.duplicate.set(null);
    this.facade.forget();
  }

  /** The choice made over codes saved by someone else: theirs, or the rows typed here, saved over theirs. */
  protected resolve(choice: 'theirs' | 'mine'): void {
    if (choice === 'theirs') this.revert();
    else this.draft.update((draft) => ({ ...draft, base: this.savedKey() }));
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
    const outcome = await this.facade.save(
      companyId,
      this.productId(),
      this.rows(),
      this.version(),
    );
    if (outcome === 'saved') this.feedback.success('products.barcodes.saved');
    if (outcome !== 'refused') this.moved.emit(outcome);
  }

  private setRows(rows: ProductBarcode[]): void {
    this.draft.update((draft) => ({ ...draft, rows }));
  }

  private edit(index: number, change: (row: ProductBarcode) => ProductBarcode): void {
    this.setRows(this.rows().map((row, at) => (at === index ? change(row) : row)));
    this.duplicate.set(null);
    this.facade.forget();
  }
}
