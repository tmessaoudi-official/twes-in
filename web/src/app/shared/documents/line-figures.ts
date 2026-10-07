// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { MatIcon } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { AmountPipe } from '../i18n/format-pipes';
import type { LineFigures } from './document-figures';

/**
 * A line's figures while it is typed, folded to its net and what it adds, tax included; unfolded, every step from
 * quantity × price to that total, its share of the document discount and each of its taxes on its own base. Only
 * figures a customer may see: what the line costs and earns belongs to « Rentabilité », never here.
 */
@Component({
  selector: 'app-line-figures',
  imports: [AmountPipe, MatIcon, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="ms-auto flex flex-col items-end gap-1 tabular-nums">
      <button
        type="button"
        class="flex cursor-pointer items-center gap-2 rounded-md px-1 text-end"
        [attr.aria-expanded]="open()"
        [attr.aria-controls]="testId() + '-details'"
        [attr.aria-label]="'document_figures.toggle' | translate: { position: position() }"
        (click)="open.set(!open())"
        [attr.data-testid]="testId() + '-toggle'"
      >
        <span
          >{{ 'document_figures.net' | translate }}
          <span class="font-semibold" [attr.data-testid]="testId() + '-net'">{{
            figures().net | amount: scale()
          }}</span></span
        >
        <span aria-hidden="true">·</span>
        <span
          >{{ 'document_figures.total' | translate }}
          <span class="font-semibold" [attr.data-testid]="testId() + '-total'">{{
            figures().total | amount: scale()
          }}</span></span
        >
        <mat-icon aria-hidden="true" class="shrink-0">{{
          open() ? 'expand_less' : 'expand_more'
        }}</mat-icon>
      </button>
      @if (open()) {
        <dl
          class="grid grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-sm"
          [id]="testId() + '-details'"
          [attr.data-testid]="testId() + '-details'"
        >
          <dt>{{ 'document_figures.amount' | translate }}</dt>
          <dd class="text-end">{{ figures().amount | amount: scale() }}</dd>
          @if (!isZero(figures().discount)) {
            <dt>{{ 'document_figures.discount' | translate }}</dt>
            <dd class="text-end">{{ '-' + figures().discount | amount: scale() }}</dd>
          }
          <dt>{{ 'document_figures.net' | translate }}</dt>
          <dd class="text-end">{{ figures().net | amount: scale() }}</dd>
          @if (!isZero(figures().documentDiscount)) {
            <dt>{{ 'document_figures.document_discount' | translate }}</dt>
            <dd class="text-end" [attr.data-testid]="testId() + '-document-discount'">
              {{ '-' + figures().documentDiscount | amount: scale() }}
            </dd>
          }
          @for (tax of taxes(); track tax.code) {
            <dt>
              {{
                'document_figures.tax'
                  | translate: { name: tax.name, base: (tax.base | amount: scale()) }
              }}
            </dt>
            <dd class="text-end" [attr.data-testid]="testId() + '-figure-tax-' + tax.code">
              {{ tax.amount | amount: scale() }}
            </dd>
          }
          <dt class="font-semibold">{{ 'document_figures.total' | translate }}</dt>
          <dd class="text-end font-semibold">{{ figures().total | amount: scale() }}</dd>
        </dl>
      }
    </div>
  `,
})
export class LineFiguresView {
  readonly figures = input.required<LineFigures>();
  readonly scale = input.required<number>();
  /** The line's place, for the toggle's accessible name. */
  readonly position = input.required<number>();
  readonly testId = input.required<string>();
  /** A tax's name by its code, as the line's own taxes name it; the code itself when none does. */
  readonly taxName = input<(code: string) => string | null>(() => null);

  /** Folded by default, on every width: the line's own fields come first. */
  protected readonly open = signal(false);
  protected readonly taxes = computed(() => {
    const name = this.taxName();
    return this.figures().taxes.map((tax) => ({ ...tax, name: name(tax.code) ?? tax.code }));
  });

  protected isZero(value: string): boolean {
    return Number(value) === 0;
  }
}
