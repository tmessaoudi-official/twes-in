// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from '@angular/core';
import type { Observable } from 'rxjs';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../i18n/format-pipes';

/** A line as the quantities read it: its unit, its quantity as typed, and whether it gives a deposit back. */
export interface QuantityLine {
  readonly unitId: string | null;
  readonly quantity: string | null;
  readonly deductsInvoiceId?: string | null;
}

export interface QuantityUnit {
  readonly id: string;
  readonly name: string;
  readonly decimals: number;
}

/** An editor's lines, as the quantities read them: any form array of lines. */
export interface QuantitySource {
  getRawValue(): readonly object[];
  readonly valueChanges: Observable<unknown>;
}

/** What the lines come to in one unit, written with the unit's decimals. */
export interface QuantityTotal {
  readonly unitName: string;
  readonly decimals: number;
  readonly quantity: string;
}

/** A quantity takes three decimals at most: it is added in thousandths, exactly, never as a float. */
const THOUSANDTHS = /^(\d+)(?:\.(\d{1,3}))?$/;

function thousandths(quantity: string): bigint | null {
  const parts = THOUSANDTHS.exec(quantity.trim());
  return parts === null
    ? null
    : BigInt(parts[1]!) * 1000n + BigInt((parts[2] ?? '').padEnd(3, '0'));
}

function written(total: bigint, decimals: number): string {
  const whole = (total / 1000n).toString();
  const fraction = (total % 1000n).toString().padStart(3, '0').slice(0, decimals);
  return decimals > 0 ? `${whole}.${fraction}` : whole;
}

/**
 * The « Quantités » line under a document's lines, as the PDF prints it: how much the lines carry in each unit, in
 * the order the units first appear. A line giving a deposit back carries nothing, a line still being typed is not
 * counted yet, and a single line already says it.
 */
export function quantityTotals(
  lines: readonly QuantityLine[],
  units: readonly QuantityUnit[],
): QuantityTotal[] {
  const counted = lines.flatMap((line) => {
    const unit = units.find((each) => each.id === line.unitId);
    const amount = thousandths(line.quantity ?? '');
    return unit === undefined || amount === null || (line.deductsInvoiceId ?? '') !== ''
      ? []
      : [{ unit, amount }];
  });
  if (counted.length < 2) return [];
  const byUnit = new Map<string, { unit: QuantityUnit; total: bigint }>();
  for (const { unit, amount } of counted) {
    const entry = byUnit.get(unit.id) ?? { unit, total: 0n };
    entry.total += amount;
    byUnit.set(unit.id, entry);
  }
  return [...byUnit.values()].map(({ unit, total }) => ({
    unitName: unit.name,
    decimals: unit.decimals,
    quantity: written(total, unit.decimals),
  }));
}

/** The quantities of an editor's lines, followed as they are typed. */
@Component({
  selector: 'app-quantity-totals',
  imports: [AmountPipe, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (totals().length > 0) {
      <p class="text-sm" [attr.data-testid]="testId()">
        {{ 'document_figures.quantities' | translate }}
        @for (total of totals(); track total.unitName; let last = $last) {
          <span class="tabular-nums">{{ total.quantity | amount: total.decimals }}</span>
          {{ total.unitName }}
          @if (!last) {
            ·
          }
        }
      </p>
    }
  `,
})
export class QuantityTotalsView {
  readonly lines = input.required<QuantitySource | null>();
  readonly units = input.required<readonly QuantityUnit[]>();
  readonly testId = input.required<string>();

  /** Bumped by every change to the lines, which a form does not tell signals of. */
  private readonly typed = signal(0);

  protected readonly totals = computed(() => {
    this.typed();
    const lines = this.lines();
    return lines === null
      ? []
      : quantityTotals(lines.getRawValue() as QuantityLine[], this.units());
  });

  constructor() {
    effect((onCleanup) => {
      const subscription = this.lines()?.valueChanges.subscribe(() =>
        this.typed.update((count) => count + 1),
      );
      onCleanup(() => subscription?.unsubscribe());
    });
  }
}
