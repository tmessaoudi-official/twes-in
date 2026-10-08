// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * The three steps a depot is drawn in, as the brief's first-use board numbers them: measure the floor, put up the
 * building or lay the architect's plan under it, then the racks, each of which is a place stock is kept. Shown on a
 * company with no floor and on a floor with nothing drawn, each step ticked from what is there, never by hand.
 */
@Component({
  selector: 'app-stock-map-first-steps',
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="flex items-start gap-4">
      @if (pictured()) {
        <!-- A depot in miniature, for a person who has never seen a plan of theirs: a floor, its walls, three racks. -->
        <svg viewBox="0 0 120 80" class="hidden h-20 w-32 shrink-0 sm:block" aria-hidden="true">
          <rect
            x="4"
            y="4"
            width="112"
            height="72"
            rx="2"
            class="fill-surface-container-low stroke-outline"
            stroke-width="2"
          />
          <rect
            x="14"
            y="14"
            width="10"
            height="44"
            class="fill-primary-container stroke-primary"
          />
          <rect
            x="34"
            y="14"
            width="10"
            height="44"
            class="fill-primary-container stroke-primary"
          />
          <rect
            x="54"
            y="14"
            width="10"
            height="44"
            class="fill-primary-container stroke-primary"
          />
          <rect
            x="78"
            y="44"
            width="30"
            height="22"
            class="fill-surface-container-high stroke-outline"
          />
        </svg>
      }
      <ol class="flex flex-col gap-2" data-testid="stock-map-steps">
        @for (step of steps(); track step.key; let index = $index) {
          <li class="flex items-start gap-2" [attr.data-testid]="'stock-map-step-' + step.key">
            <span
              class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-medium"
              [class.bg-primary]="step.done"
              [class.text-on-primary]="step.done"
              [class.border]="!step.done"
              [class.border-outline]="!step.done"
              aria-hidden="true"
            >
              {{ step.done ? '✓' : index + 1 }}
            </span>
            <span class="text-sm">
              {{ 'inventory.plan.first.steps.' + step.key | translate }}
              @if (step.done) {
                <span
                  class="text-on-surface-variant"
                  [attr.data-testid]="'stock-map-step-done-' + step.key"
                >
                  · {{ 'inventory.plan.first.done' | translate }}
                </span>
              }
            </span>
          </li>
        }
      </ol>
    </div>
  `,
})
export class StockMapFirstSteps {
  /** The floor's width and depth were given. */
  readonly measured = input(false);
  /** Walls, doors or posts were put up, or a plan was laid under the floor. */
  readonly built = input(false);
  /** Something that keeps stock was drawn. */
  readonly drawn = input(false);
  readonly pictured = input(false);

  protected readonly steps = computed(() => [
    { key: 'measure', done: this.measured() },
    { key: 'build', done: this.built() },
    { key: 'place', done: this.drawn() },
  ]);
}
