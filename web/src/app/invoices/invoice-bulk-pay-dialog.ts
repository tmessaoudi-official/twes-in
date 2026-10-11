// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { TranslatePipe } from '@ngx-translate/core';
import { debounceTime, distinctUntilChanged } from 'rxjs';
import { Feedback } from '../shared/feedback/feedback';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormDescriptor } from '../shared/form/form-types';
import { AmountPipe } from '../shared/i18n/format-pipes';
import { InvoicesRefused } from './invoices-api';
import { InvoicesFacade } from './invoices-facade';
import {
  PAYMENT_METHODS,
  type BulkCheck,
  type BulkPaid,
  type PaymentMethod,
} from './invoices-types';

export interface BulkPayData {
  companyId: string;
  /** The selected invoices' ids, in the list's order. */
  ids: readonly string[];
  /** The company's day, which a payment is dated by default. */
  today: string;
  /** The currency's decimals, which the amounts are shown with. */
  scale: number | null;
}

/** The longest reference a payment keeps, as the API counts it. */
const REFERENCE_MAX = 64;

/** How a payment of a whole selection is made: one day, one method, one reference for all. */
function bulkPayForm(): FormDescriptor {
  return {
    id: 'invoice-bulk-pay',
    sections: [
      {
        id: 'payment',
        title: 'invoices.bulk.pay_how',
        fields: [
          { id: 'date', label: 'invoices.payments.date', kind: 'date', required: true },
          {
            id: 'method',
            label: 'invoices.payments.method',
            kind: 'select',
            required: true,
            options: PAYMENT_METHODS.map((method) => ({
              value: method,
              label: `invoices.payments.methods.${method}`,
            })),
          },
          {
            id: 'reference',
            label: 'invoices.payments.reference',
            kind: 'text',
            maxLength: REFERENCE_MAX,
            hint: 'invoices.bulk.pay_reference_hint',
          },
        ],
      },
    ],
  };
}

/** Thousandths of an amount written with at most three decimals, as the API writes them; summed without a float. */
function thousandths(amount: string): bigint {
  const [whole = '0', decimals = ''] = amount.split('.');
  return BigInt(whole + decimals.padEnd(3, '0').slice(0, 3));
}

function fromThousandths(value: bigint): string {
  const digits = value.toString().padStart(4, '0');
  return `${digits.slice(0, -3)}.${digits.slice(-3)}`;
}

/**
 * « Encaisser » on a selection: each invoice paid for all it still owes, by one method on one day. The day, method and
 * reference come first, then what the API says each invoice would receive on that day, the total, and every invoice
 * left out with why; a new day asks again. The API records all of it or nothing: when an invoice changed since the
 * preview, the preview is read again and shown, and nothing is paid until the person confirms the new one.
 */
@Component({
  selector: 'app-invoice-bulk-pay-dialog',
  imports: [
    AmountPipe,
    DescriptorForm,
    MatButtonModule,
    MatDialogModule,
    MatProgressBarModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title>
      {{ 'invoices.bulk.pay_title' | translate: { count: included().length } }}
    </h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-4">
        <app-descriptor-form
          [descriptor]="descriptor"
          [form]="group"
          testId="invoice-bulk-pay-form"
          (submitted)="pay()"
        />
        @if (checking()) {
          <mat-progress-bar mode="indeterminate" data-testid="invoice-bulk-pay-checking" />
        }
        @if (check(); as check) {
          @if (included().length > 0) {
            <ul class="flex flex-col gap-1" data-testid="invoice-bulk-pay-rows">
              @for (row of included(); track row.id) {
                <li class="flex items-baseline justify-between gap-4">
                  <span class="flex flex-col">
                    <span class="font-medium">{{ row.number }}</span>
                    <span class="text-sm text-on-surface-variant">{{ row.customerName }}</span>
                  </span>
                  <span class="tabular-nums">{{ row.amount | amount: data.scale }}</span>
                </li>
              }
            </ul>
            <p
              class="flex justify-between gap-4 border-t border-outline-variant pt-2 font-medium"
              data-testid="invoice-bulk-pay-total"
            >
              <span>{{ 'invoices.bulk.pay_total' | translate }}</span>
              <span class="tabular-nums">{{ total() | amount: data.scale }}</span>
            </p>
          }
          @if (skipped().length > 0) {
            <div class="flex flex-col gap-2">
              <h3 class="font-medium">
                {{ 'invoices.bulk.pay_skipped' | translate: { count: skipped().length } }}
              </h3>
              <ul class="flex flex-col gap-2" data-testid="invoice-bulk-pay-skipped">
                @for (row of skipped(); track row.id) {
                  <li
                    class="flex flex-col"
                    [attr.data-testid]="'invoice-bulk-pay-skipped-' + row.id"
                  >
                    <span class="font-medium">{{
                      row.number ?? row.customerName ?? ('invoices.bulk.unknown' | translate)
                    }}</span>
                    <span class="text-sm text-on-surface-variant">{{
                      'invoices.bulk.reasons.' + row.reason | translate
                    }}</span>
                  </li>
                }
              </ul>
            </div>
          }
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-button
        type="button"
        (click)="ref.close(null)"
        data-testid="invoice-bulk-pay-cancel"
      >
        {{ 'invoices.bulk.cancel' | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [disabled]="included().length === 0 || checking() || paying()"
        (click)="pay()"
        data-testid="invoice-bulk-pay-go"
      >
        {{ 'invoices.bulk.pay_go' | translate: { count: included().length } }}
      </button>
    </mat-dialog-actions>
  `,
})
export class InvoiceBulkPayDialog {
  protected readonly ref =
    inject<MatDialogRef<InvoiceBulkPayDialog, BulkPaid | null>>(MatDialogRef);
  protected readonly data = inject<BulkPayData>(MAT_DIALOG_DATA);
  private readonly facade = inject(InvoicesFacade);
  private readonly feedback = inject(Feedback);

  protected readonly descriptor = bulkPayForm();
  protected readonly group = buildFormGroup(this.descriptor, {
    date: this.data.today,
    // Cash first, as a shop counter is paid; one cheque or transfer settling several is picked from the list.
    method: 'cash',
    reference: '',
  });

  protected readonly check = signal<BulkCheck | null>(null);
  protected readonly checking = signal(false);
  protected readonly paying = signal(false);
  protected readonly included = computed(
    () => this.check()?.rows.filter((row) => row.included) ?? [],
  );
  protected readonly skipped = computed(
    () => this.check()?.rows.filter((row) => !row.included) ?? [],
  );
  protected readonly total = computed(() =>
    fromThousandths(this.included().reduce((sum, row) => sum + thousandths(row.amount ?? '0'), 0n)),
  );
  /** The day the preview shown was checked for, which a payment must be dated by. */
  private checkedDay: string | null = null;
  /** Which preview was asked last: an earlier answer arriving after a later one is not shown. */
  private asked = 0;

  constructor() {
    void this.preview();
    this.group.controls['date']!.valueChanges.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntilDestroyed(inject(DestroyRef)),
    ).subscribe(() => void this.preview());
  }

  protected async pay(): Promise<void> {
    const day = this.day();
    if (this.group.invalid || day === null) {
      this.group.markAllAsTouched();
      return;
    }
    if (day !== this.checkedDay || this.included().length === 0 || this.paying()) return;
    const values = this.group.getRawValue();
    const method = PAYMENT_METHODS.find((each) => each === values['method']) ?? 'other';
    const reference = String(values['reference'] ?? '').trim();
    this.paying.set(true);
    try {
      const paid = await this.facade.bulkPay(this.data.companyId, {
        method: method satisfies PaymentMethod,
        date: day,
        reference: reference === '' ? null : reference,
        rows: this.included().map((row) => ({ id: row.id, amount: row.amount ?? '' })),
      });
      this.ref.close(paid);
    } catch (error) {
      const code = error instanceof InvoicesRefused ? error.code : 'network';
      if (code === 'conflict') {
        // Something was paid, credited or changed since the preview: show what is true now, and pay nothing yet.
        this.feedback.failure('invoices.bulk.pay_changed');
        await this.preview();
      } else this.feedback.failure(`invoices.errors.${code}`);
    } finally {
      this.paying.set(false);
    }
  }

  private day(): string | null {
    const control = this.group.controls['date']!;
    const day = String(control.value ?? '').trim();
    return control.valid && day !== '' ? day : null;
  }

  private async preview(): Promise<void> {
    const day = this.day();
    if (day === null) return;
    const asked = ++this.asked;
    this.checking.set(true);
    try {
      const check = await this.facade.bulkCheck(this.data.companyId, 'pay', this.data.ids, day);
      if (asked !== this.asked) return;
      this.check.set(check);
      this.checkedDay = day;
    } catch {
      if (asked !== this.asked) return;
      this.check.set(null);
      this.checkedDay = null;
      this.feedback.failure('invoices.bulk.check_failed');
    } finally {
      if (asked === this.asked) this.checking.set(false);
    }
  }
}
