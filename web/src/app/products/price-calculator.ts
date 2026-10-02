// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, effect, input, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import {
  amountOf,
  atCurrencyScale,
  markupOf,
  marginOf,
  type PercentBasis,
  priceFor,
  profitOf,
} from './price-calculator-math';

/**
 * The price a product earns what it should (docs/SPEC.md § 7, 2026-10-02): from the cost and the net price the form
 * holds it says what a unit earns, as a margin on the price and as a markup on the cost, and from the margin or the
 * markup wanted it says the price to ask and puts it in the form on request. Net amounts only: what a customer pays
 * with tax is the API's calculator's, whose compounding and rounding a guess here would get wrong.
 */
@Component({
  selector: 'app-price-calculator',
  imports: [FormsModule, MatButtonModule, MatFormFieldModule, MatInputModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="flex flex-col gap-4" data-testid="price-calculator">
      <div class="flex flex-col gap-1">
        <h2 class="m-0 text-lg font-medium">{{ 'products.calculator.title' | translate }}</h2>
        <p class="m-0 max-w-prose">{{ 'products.calculator.intro' | translate }}</p>
      </div>

      @if (cost() === null || price() === null) {
        <p data-testid="price-calculator-missing">
          {{ 'products.calculator.missing' | translate }}
        </p>
      } @else {
        <dl class="m-0 grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3">
          <div>
            <dt class="text-sm opacity-80">{{ 'products.calculator.profit' | translate }}</dt>
            <dd class="m-0 text-xl font-semibold" data-testid="price-calculator-profit">
              {{ shown(profit()) }} {{ currency() }}
            </dd>
          </div>
          <div>
            <dt class="text-sm opacity-80">{{ 'products.calculator.margin' | translate }}</dt>
            <dd class="m-0 text-xl font-semibold" data-testid="price-calculator-margin">
              {{ percentShown(margin()) }}
            </dd>
          </div>
          <div>
            <dt class="text-sm opacity-80">{{ 'products.calculator.markup' | translate }}</dt>
            <dd class="m-0 text-xl font-semibold" data-testid="price-calculator-markup">
              {{ percentShown(markup()) }}
            </dd>
          </div>
        </dl>
      }

      @if (cost() !== null) {
        <div class="flex flex-wrap items-start gap-3">
          <div
            class="flex gap-1"
            role="group"
            [attr.aria-label]="'products.calculator.basis' | translate"
          >
            @for (option of bases; track option) {
              <button
                mat-stroked-button
                type="button"
                [attr.aria-pressed]="basis() === option"
                [class.is-on]="basis() === option"
                (click)="basis.set(option)"
                [attr.data-testid]="'price-calculator-basis-' + option"
              >
                {{ 'products.calculator.' + option | translate }}
              </button>
            }
          </div>
          <mat-form-field class="w-40">
            <mat-label>{{ 'products.calculator.wanted' | translate }}</mat-label>
            <input
              matInput
              inputmode="decimal"
              autocomplete="off"
              data-testid="price-calculator-wanted"
              [ngModel]="wanted()"
              (ngModelChange)="wanted.set($event)"
            />
            <span matTextSuffix>%</span>
          </mat-form-field>
          @if (target(); as target) {
            <div class="flex flex-col gap-1">
              <span class="text-sm opacity-80">{{
                'products.calculator.price_to_ask' | translate
              }}</span>
              <span class="text-xl font-semibold" data-testid="price-calculator-target">
                {{ target }} {{ currency() }}
              </span>
              @if (!readOnly()) {
                <button
                  mat-flat-button
                  type="button"
                  (click)="apply(target)"
                  data-testid="price-calculator-apply"
                >
                  {{ 'products.calculator.apply' | translate }}
                </button>
              }
            </div>
          } @else if (wanted().trim() !== '') {
            <p class="m-0 self-center" data-testid="price-calculator-no-price">
              {{ 'products.calculator.no_price' | translate }}
            </p>
          }
        </div>
      }
    </section>
  `,
})
export class PriceCalculator {
  /** The product's form: its cost and net price are read from it as they are typed, and the price goes back into it. */
  readonly form = input.required<DescriptorFormGroup>();
  /** How many decimals the company's currency counts in. */
  readonly scale = input.required<number>();
  readonly currency = input.required<string>();
  readonly readOnly = input(false);

  protected readonly bases: readonly PercentBasis[] = ['margin', 'markup'];
  protected readonly basis = signal<PercentBasis>('margin');
  protected readonly wanted = signal('');
  protected readonly cost = signal<number | null>(null);
  protected readonly price = signal<number | null>(null);

  protected readonly profit = computed(() => {
    const [cost, price] = [this.cost(), this.price()];
    return cost === null || price === null ? null : profitOf(cost, price);
  });
  protected readonly margin = computed(() => {
    const [cost, price] = [this.cost(), this.price()];
    return cost === null || price === null ? null : marginOf(cost, price);
  });
  protected readonly markup = computed(() => {
    const [cost, price] = [this.cost(), this.price()];
    return cost === null || price === null ? null : markupOf(cost, price);
  });
  /** The price for the percentage wanted, at the currency's scale; null while none is typed or none exists. */
  protected readonly target = computed(() => {
    const cost = this.cost();
    const percent = amountOf(this.wanted().replace(',', '.'));
    if (cost === null || percent === null) return null;
    const price = priceFor(cost, percent, this.basis());
    return price === null ? null : atCurrencyScale(price, this.scale());
  });

  constructor() {
    effect((onCleanup) => {
      const form = this.form();
      const read = (): void => {
        this.cost.set(amountOf(String(form.get('costPrice')?.value ?? '')));
        this.price.set(amountOf(String(form.get('unitPriceNet')?.value ?? '')));
      };
      read();
      const subscription = form.valueChanges.subscribe(read);
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected shown(value: number | null): string {
    return value === null ? '' : atCurrencyScale(value, this.scale());
  }

  protected percentShown(value: number | null): string {
    return value === null ? '—' : `${(Math.round(value * 100) / 100).toFixed(2)} %`;
  }

  protected apply(price: string): void {
    const control = this.form().get('unitPriceNet');
    if (control === null || control === undefined) return;
    control.setValue(price);
    control.markAsDirty();
  }
}
