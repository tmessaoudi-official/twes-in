// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { locationLabels } from '../inventory/inventory-forms';
import { DayCalendarButton } from '../shared/form/day-calendar-button';
import { DayInput } from '../shared/form/day-input';
import { DecimalInput } from '../shared/form/decimal-input';
import type { FieldRefusal } from '../shared/form/field-refusals';
import type { FieldError } from '../shared/form/form-builder';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { refusedError, worded } from '../shared/form/refusal-words';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { todayIn } from '../shared/i18n/format';
import { plainDecimal } from './purchase-order-forms';
import type {
  CostBasis,
  PurchaseOrderLine,
  PurchasesError,
  ReceiptInput,
  Receiving,
} from './purchases-types';
import {
  LOT_CODE_MAX,
  type ReceiveRow,
  receiptInput,
  receiptRefusal,
  receiveRows,
  rowProblem,
  serialsOf,
  SUPPLIER_REFERENCE_MAX,
} from './receipt-forms';

export interface ReceiveDialogData {
  readonly lines: readonly PurchaseOrderLine[];
  readonly receiving: Receiving;
  /** Whether a cost is asked: a receipt's cost is a cost, read and written only by whoever may read costs. */
  readonly seesCost: boolean;
  /** The company's clock, whose today is the latest day goods can have come. */
  readonly timeZone: string | null;
  /** The day the order was sent, the earliest day its goods can have come. */
  readonly issueDate: string | null;
  /** Records the receipt: nothing when it was kept, otherwise why not, the field the API named and its refusal. */
  readonly receive: (input: ReceiptInput) => Promise<{
    code: PurchasesError;
    field: string | null;
    refusal: FieldRefusal | null;
  } | null>;
}

/** The label each field the API may refuse is shown by. */
const FIELD_LABELS: Readonly<Record<string, string>> = {
  quantity: 'inventory.stock.fields.quantity',
  locationId: 'inventory.stock.fields.location',
  lotCode: 'inventory.stock.fields.lot',
  lotExpiresOn: 'purchases.receive.expires',
  unitCost: 'inventory.stock.fields.unitCost',
  receivedOn: 'purchases.receive.received_on',
  supplierReference: 'inventory.stock.fields.supplierReference',
};

/**
 * The goods that came against an order: the day, the vendor's delivery reference, and for each line still expected how
 * much came, where it goes and the lot it came in. It records the receipt itself and stays open on a refusal, so what
 * was typed — a column of scanned serial numbers — is never lost to one wrong line; it closes on `true` once kept.
 */
@Component({
  selector: 'app-receive-dialog',
  imports: [
    FormsModule,
    DayInput,
    DayCalendarButton,
    DecimalInput,
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    TranslatePipe,
    AmountPipe,
  ],
  templateUrl: './receive-dialog.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReceiveDialog {
  protected readonly ref = inject<MatDialogRef<ReceiveDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<ReceiveDialogData>(MAT_DIALOG_DATA);

  protected readonly referenceMax = SUPPLIER_REFERENCE_MAX;
  protected readonly lotMax = LOT_CODE_MAX;
  protected readonly plain = plainDecimal;
  private readonly today = todayIn(this.data.timeZone);
  protected readonly receivedOn = signal(this.today);
  protected readonly supplierReference = signal('');
  protected readonly apply = signal<CostBasis | ''>('');
  protected readonly rows = signal<ReceiveRow[]>(receiveRows(this.data.lines, this.data.receiving));
  protected readonly places = [...locationLabels(this.data.receiving.places)].map(
    ([id, label]) => ({ id, label }),
  );
  protected readonly asksCostBasis = this.data.seesCost && this.data.receiving.asksCostBasis;
  protected readonly busy = signal(false);
  /** Why the last attempt was refused: the API's code, the row it named, the field there and its reason's words. */
  protected readonly refused = signal<{
    code: PurchasesError;
    row: number | null;
    field: string | null;
    words: FieldError | null;
  } | null>(null);
  private readonly translate = inject(TranslateService);
  private readonly figures = inject(RefusalFigures);

  protected readonly problems = computed(() =>
    this.rows().map((row) => rowProblem(row, this.data.seesCost)),
  );
  /** Why the day cannot be the day the goods came: not a day, before the order was sent, or after today. */
  protected readonly dayProblem = computed((): 'day' | 'before' | 'after' | null => {
    const day = this.receivedOn();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) return 'day';
    if (this.data.issueDate !== null && day < this.data.issueDate) return 'before';
    return day > this.today ? 'after' : null;
  });
  private readonly built = computed(() =>
    receiptInput(
      this.receivedOn(),
      this.supplierReference(),
      this.apply() === '' ? null : (this.apply() as CostBasis),
      this.rows(),
      this.data.seesCost,
    ),
  );
  /** How many receipt lines would be sent, a piece per serial number. */
  protected readonly bringing = computed(() => this.built().input.lines.length);
  protected readonly valid = computed(
    () =>
      this.bringing() > 0 &&
      this.dayProblem() === null &&
      this.supplierReference().trim().length <= SUPPLIER_REFERENCE_MAX &&
      this.problems().every((problem) => problem === null),
  );

  protected update(index: number, patch: Partial<Omit<ReceiveRow, 'line'>>): void {
    this.rows.update((rows) => rows.map((row, at) => (at === index ? { ...row, ...patch } : row)));
    if (this.refused()?.row === index) this.refused.set(null);
  }

  protected serialCount(row: ReceiveRow): number {
    return serialsOf(row).length;
  }

  /** Why the API refused a row, in its reason's words, when it gave one. */
  protected refusedWords(index: number): FieldError | null {
    const refused = this.refused();
    return refused !== null && refused.row === index ? refused.words : null;
  }

  /** What the API refused on a row, by its field's label; for a serial, the serial number's. */
  protected refusedLabel(index: number): string | null {
    const refused = this.refused();
    if (refused === null || refused.row !== index || refused.field === null) return null;
    const row = this.rows()[index];
    if (row?.line.productTracking === 'serial' && refused.field === 'lotCode') {
      return 'inventory.stock.fields.serial';
    }
    return FIELD_LABELS[refused.field] ?? 'purchases.receive.the_line';
  }

  /** A refusal of the receipt itself, not of one of its rows. */
  protected readonly receiptRefused = computed(() => {
    const refused = this.refused();
    if (refused === null || refused.row !== null) return null;
    const label = refused.field === null ? null : (FIELD_LABELS[refused.field] ?? null);
    return { code: refused.code, label, words: refused.words };
  });

  protected async submit(): Promise<void> {
    if (!this.valid() || this.busy()) return;
    const { input, sources } = this.built();
    this.busy.set(true);
    this.refused.set(null);
    try {
      const outcome = await this.data.receive(input);
      if (outcome === null) {
        this.ref.close(true);
        return;
      }
      const place =
        outcome.field === null
          ? { row: null, field: null }
          : receiptRefusal(outcome.field, sources);
      // A reason the screens word is said in its words; one they do not is named by its field, as it always was.
      const refusal = outcome.refusal;
      this.refused.set({
        code: outcome.code,
        row: place.row,
        field: place.field,
        words:
          refusal !== null && worded(this.translate, refusal)
            ? refusedError(this.translate, refusal, refusal.params, this.figures)
            : null,
      });
    } finally {
      this.busy.set(false);
    }
  }
}
