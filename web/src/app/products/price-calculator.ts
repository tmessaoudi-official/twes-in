// SPDX-License-Identifier: AGPL-3.0-or-later

import { NgTemplateOutlet } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  InjectionToken,
  input,
  type Signal,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import type { DescriptorFormGroup } from '../shared/form/form-builder';
import { AmountPipe } from '../shared/i18n/format-pipes';
import {
  amountOf,
  atCurrencyScale,
  exactPriceFor,
  markupOf,
  marginOf,
  type PercentBasis,
  profitOf,
} from './price-calculator-math';
import { ProductsFacade } from './products-facade';
import type { PricePreviewLine } from './products-types';

/** How long typing must pause before a price is counted with its taxes, so a burst of keys asks once. */
export const PRICE_PREVIEW_DELAY = new InjectionToken<number>('PRICE_PREVIEW_DELAY', {
  factory: () => 250,
});

/** The lines counted, null when the API could not count them, undefined while there is no price to count. */
type Preview = PricePreviewLine[] | null | undefined;

/** A price the API takes: digits, and up to four decimals after a point. */
const PRICE = /^\d{1,10}(\.\d{1,4})?$/;

/**
 * The price a product earns what it should (docs/SPEC.md § 7, 2026-10-02): from the cost and the net price the form
 * holds it says what a unit earns, as a margin on the price and as a markup on the cost, and from the margin or the
 * markup wanted it says the price to ask and puts it in the form on request. What either price comes to with the line
 * taxes chosen, per unit and per pack, is asked of the API's calculator (audit 2026-10-06, B-9), whose compounding and
 * rounding a guess here would get wrong.
 */
@Component({
  selector: 'app-price-calculator',
  imports: [
    AmountPipe,
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    NgTemplateOutlet,
    TranslatePipe,
  ],
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
              {{ shown(profit()) | amount: scale() }} {{ currency() }}
            </dd>
          </div>
          <div>
            <dt class="text-sm opacity-80">{{ 'products.calculator.margin' | translate }}</dt>
            <dd class="m-0 text-xl font-semibold" data-testid="price-calculator-margin">
              @if (percentOf(margin()) === null) {
                —
              } @else {
                {{ percentOf(margin()) | amount: 2 }}&nbsp;%
              }
            </dd>
            <dd class="m-0 text-sm opacity-80" data-testid="price-calculator-margin-how">
              {{
                'products.calculator.margin_how'
                  | translate
                    : {
                        profit: shown(profit()) | amount: scale(),
                        price: shown(price()) | amount: scale(),
                      }
              }}
            </dd>
          </div>
          <div>
            <dt class="text-sm opacity-80">{{ 'products.calculator.markup' | translate }}</dt>
            <dd class="m-0 text-xl font-semibold" data-testid="price-calculator-markup">
              @if (percentOf(markup()) === null) {
                —
              } @else {
                {{ percentOf(markup()) | amount: 2 }}&nbsp;%
              }
            </dd>
            <dd class="m-0 text-sm opacity-80" data-testid="price-calculator-markup-how">
              {{
                'products.calculator.markup_how'
                  | translate
                    : {
                        profit: shown(profit()) | amount: scale(),
                        cost: shown(cost()) | amount: scale(),
                      }
              }}
            </dd>
          </div>
        </dl>
      }

      @if (withTax(); as lines) {
        <div class="flex flex-col gap-1">
          <h3 class="m-0 text-base font-medium">
            {{ 'products.calculator.with_tax' | translate }}
          </h3>
          <ng-container
            *ngTemplateOutlet="
              prices;
              context: { $implicit: lines, prefix: 'price-calculator-with-tax-' }
            "
          />
        </div>
      } @else if (withTax() === null) {
        <p class="m-0" data-testid="price-calculator-with-tax-unavailable">
          {{ 'products.calculator.with_tax_unavailable' | translate }}
        </p>
      }

      @if (cost() !== null) {
        <div class="flex flex-wrap items-start gap-3">
          <!-- On a phone the two bases stand one under the other rather than each wrapping its words over its outline. -->
          <div
            class="flex flex-wrap gap-1"
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
                class="whitespace-nowrap"
              >
                {{ 'products.calculator.' + option | translate }}
              </button>
            }
          </div>
          <mat-form-field class="w-40" floatLabel="always">
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
                {{ target | amount: scale() }} {{ currency() }}
              </span>
              <span
                class="max-w-prose text-sm opacity-80"
                data-testid="price-calculator-target-how"
              >
                {{
                  'products.calculator.target_' + basis()
                    | translate
                      : {
                          cost: shown(cost()) | amount: scale(),
                          percent: wantedShown(),
                          price: target | amount: scale(),
                        }
                }}
              </span>
              @if (other(); as other) {
                <span class="max-w-prose text-sm opacity-80" data-testid="price-calculator-other">
                  {{
                    'products.calculator.other_' + basis()
                      | translate
                        : {
                            percent: wantedShown(),
                            price: other | amount: scale(),
                            currency: currency(),
                          }
                  }}
                </span>
              }
              @if (targetWithTax(); as lines) {
                <ng-container
                  *ngTemplateOutlet="
                    prices;
                    context: { $implicit: lines, prefix: 'price-calculator-target-with-tax-' }
                  "
                />
              }
              @if (!readOnly()) {
                <button
                  mat-flat-button
                  type="button"
                  class="self-start"
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

    <ng-template #prices let-lines let-prefix="prefix">
      <table class="w-full max-w-xl border-collapse text-sm">
        <thead>
          <tr>
            <th scope="col" class="py-1 pe-3 text-start font-medium">
              {{ 'products.calculator.quantity' | translate }}
            </th>
            <th scope="col" class="py-1 pe-3 text-end font-medium">
              {{ 'products.calculator.net' | translate: { currency: currency() } }}
            </th>
            <th scope="col" class="py-1 pe-3 text-end font-medium">
              {{ 'products.calculator.tax' | translate }}
            </th>
            <th scope="col" class="py-1 text-end font-medium">
              {{ 'products.calculator.total' | translate: { currency: currency() } }}
            </th>
          </tr>
        </thead>
        <tbody>
          @for (line of asLines(lines); track line.quantity) {
            <tr [attr.data-testid]="prefix + line.quantity">
              <th scope="row" class="py-1 pe-3 text-start font-normal">
                @if (line.quantity === '1') {
                  {{ 'products.calculator.per_unit' | translate }}
                } @else {
                  {{ 'products.calculator.per_pack' | translate: { count: line.quantity } }}
                }
              </th>
              <td class="py-1 pe-3 text-end tabular-nums">{{ line.net | amount: scale() }}</td>
              <td class="py-1 pe-3 text-end tabular-nums">{{ line.tax | amount: scale() }}</td>
              <td class="py-1 text-end font-semibold tabular-nums">
                {{ line.total | amount: scale() }}
              </td>
            </tr>
          }
        </tbody>
      </table>
    </ng-template>
  `,
})
export class PriceCalculator {
  /** The product's form: its cost and net price are read from it as they are typed, and the price goes back into it. */
  readonly form = input.required<DescriptorFormGroup>();
  /** How many decimals the company's currency counts in. */
  readonly scale = input.required<number>();
  readonly currency = input.required<string>();
  readonly readOnly = input(false);
  /** The company the taxes are counted for; none, and nothing is counted. */
  readonly companyId = input<string | null>(null);
  /** How many units each of the product's packs holds, as its codes say. */
  readonly packs = input<readonly number[]>([]);

  private readonly products = inject(ProductsFacade);
  private readonly delay = inject(PRICE_PREVIEW_DELAY);

  protected readonly bases: readonly PercentBasis[] = ['margin', 'markup'];
  protected readonly basis = signal<PercentBasis>('margin');
  protected readonly wanted = signal('');
  protected readonly cost = signal<number | null>(null);
  /** The cost as the form holds it, which the price to ask is counted from exactly. */
  private readonly costText = signal('');
  protected readonly price = signal<number | null>(null);
  /** The net price as the form holds it, when the API would take it. */
  private readonly priceText = signal<string | null>(null);
  private readonly taxIds = signal<readonly string[]>([], { equal: sameList });

  /** A unit, then each pack once, smallest first. */
  private readonly quantities = computed(
    () => [
      '1',
      ...[...new Set(this.packs().filter((count) => count > 1))].sort((a, b) => a - b).map(String),
    ],
    { equal: sameList },
  );

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
  protected readonly target = computed(() =>
    exactPriceFor(this.costText(), this.wanted().replace(',', '.'), this.basis(), this.scale()),
  );

  /** The price the same percentage gives on the other basis, so « 30 % » is never read as the wrong one of the two. */
  protected readonly other = computed(() =>
    exactPriceFor(
      this.costText(),
      this.wanted().replace(',', '.'),
      this.basis() === 'margin' ? 'markup' : 'margin',
      this.scale(),
    ),
  );

  /** The price in the form with its taxes. */
  protected readonly withTax = this.preview(() => this.priceText());
  /** The price to ask with its taxes. */
  protected readonly targetWithTax = this.preview(() => this.target());

  protected wantedShown(): string {
    return this.wanted().trim().replace('.', ',');
  }

  constructor() {
    effect((onCleanup) => {
      const form = this.form();
      const read = (): void => {
        this.cost.set(amountOf(String(form.get('costPrice')?.value ?? '')));
        this.costText.set(String(form.get('costPrice')?.value ?? ''));
        this.price.set(amountOf(String(form.get('unitPriceNet')?.value ?? '')));
        const text = String(form.get('unitPriceNet')?.value ?? '').trim();
        this.priceText.set(PRICE.test(text) ? text : null);
        const taxes: unknown = form.get('defaultTaxComponentIds')?.value;
        this.taxIds.set(
          Array.isArray(taxes) ? taxes.filter((id): id is string => typeof id === 'string') : [],
        );
      };
      read();
      const subscription = form.valueChanges.subscribe(read);
      onCleanup(() => subscription.unsubscribe());
    });
  }

  protected shown(value: number | null): string {
    return value === null ? '' : atCurrencyScale(value, this.scale());
  }

  /** A percentage at two decimals, as the amount pipe takes it; none for a figure there is not. */
  protected percentOf(value: number | null): string | null {
    return value === null ? null : (Math.round(value * 100) / 100).toFixed(2);
  }

  protected asLines(lines: unknown): PricePreviewLine[] {
    return lines as PricePreviewLine[];
  }

  /**
   * What `priceOf` comes to with the taxes, once typing pauses: the answer to the latest question only, the previous
   * one shown meanwhile so the figures do not blink on every key.
   */
  private preview(priceOf: () => string | null): Signal<Preview> {
    const shown = signal<Preview>(undefined);
    let asked = 0;
    effect((onCleanup) => {
      const [companyId, price, taxes, quantities] = [
        this.companyId(),
        priceOf(),
        this.taxIds(),
        this.quantities(),
      ];
      const turn = ++asked;
      if (companyId === null || price === null) {
        shown.set(undefined);
        return;
      }
      const timer = setTimeout(() => {
        void this.products.pricePreview(companyId, price, taxes, quantities).then((lines) => {
          if (turn === asked) shown.set(lines);
        });
      }, this.delay);
      onCleanup(() => clearTimeout(timer));
    });
    return shown.asReadonly();
  }

  protected apply(price: string): void {
    const control = this.form().get('unitPriceNet');
    if (control === null || control === undefined) return;
    control.setValue(price);
    control.markAsDirty();
  }
}

function sameList(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((item, index) => item === b[index]);
}
