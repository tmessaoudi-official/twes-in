// SPDX-License-Identifier: AGPL-3.0-or-later

import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../i18n/format-pipes';
import { WINDOW_CLASS } from './window-class';

/** One line of an issued document, as it is read: plain values, the words already chosen. */
export interface IssuedLine {
  readonly description: string;
  /** The product's reference, when the line names one. */
  readonly reference: string | null;
  /** The lot or serial the line sold or handed over. */
  readonly lot: string | null;
  /** Translation keys of what else is said about the line (taken from a delivery note, back in stock). */
  readonly notes: readonly string[];
  /** A decimal string. */
  readonly quantity: string;
  /** The decimals the line's unit counts, or `null` to show the quantity as it came. */
  readonly quantityScale: number | null;
  readonly unit: string;
  /** A decimal string, shown at the currency's scale. */
  readonly unitPrice: string;
  /** A percentage as a decimal string; `null` or zero is no discount. */
  readonly discountRate: string | null;
  /** The line's taxes, named. */
  readonly taxes: string;
  /** The line's net as last saved. */
  readonly net: string | null;
}

/**
 * The lines of a document that can no longer change, as the PDF lays them out: a document issued or validated is
 * read, so its lines are values in a table, not fields drawn disabled (audit 2026-10-06 V-6). On a phone each line is
 * an entry of a list, what it is first and its figures on one line under it. A discount or net column shows only when
 * one line has one.
 */
@Component({
  selector: 'app-issued-lines',
  imports: [NgTemplateOutlet, TranslatePipe, AmountPipe],
  templateUrl: './issued-lines.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class IssuedLines {
  private readonly windowClass = inject(WINDOW_CLASS);

  readonly lines = input.required<readonly IssuedLine[]>();
  readonly currencyScale = input.required<number>();
  readonly testId = input.required<string>();

  protected readonly compact = computed(() => this.windowClass() === 'compact');
  protected readonly discounted = computed(() =>
    this.lines().some((line) => this.discountOf(line) !== null),
  );
  protected readonly withNet = computed(() => this.lines().some((line) => line.net !== null));

  /** The line's discount rate without its trailing zeros, or `null` when it takes none. */
  protected discountOf(line: IssuedLine): string | null {
    const rate = line.discountRate?.trim() ?? '';
    if (rate === '' || Number(rate) === 0) return null;
    return rate.includes('.') ? rate.replace(/0+$/, '').replace(/\.$/, '') : rate;
  }
}
