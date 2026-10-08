// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  Injector,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { TourGuide } from './tour-guide';

/**
 * The card a tour step shows beside what it points at: a dialog that does not hold the page, named by the step's
 * title, with the way back, the way on and the way out. Escape ends the tour from anywhere on the card.
 */
@Component({
  selector: 'app-tour-card',
  imports: [MatButtonModule, MatIconModule, TranslatePipe, Label],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(keydown.escape)': 'guide.end()' },
  template: `
    @if (step(); as step) {
      <section
        role="dialog"
        aria-modal="false"
        aria-labelledby="twes-tour-title"
        aria-describedby="twes-tour-body"
        class="twes-tour-card flex flex-col gap-3 rounded-xl p-4"
        data-testid="tour-card"
      >
        <div class="flex items-start justify-between gap-2">
          <h2
            #title
            id="twes-tour-title"
            tabindex="-1"
            class="text-base font-semibold"
            data-testid="tour-title"
          >
            {{ step.titleKey | translate }}
          </h2>
          <button
            mat-icon-button
            type="button"
            [appLabel]="'tour.close' | translate"
            (click)="guide.end()"
            data-testid="tour-close"
          >
            <mat-icon aria-hidden="true">close</mat-icon>
          </button>
        </div>
        <p id="twes-tour-body" class="text-sm" data-testid="tour-body">
          {{ step.bodyKey | translate }}
        </p>
        @if (guide.missing()) {
          <p class="text-sm text-on-surface-variant" data-testid="tour-missing">
            {{ 'tour.missing' | translate }}
          </p>
        }
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="text-xs text-on-surface-variant" data-testid="tour-progress">
            {{ 'tour.step' | translate: progress() }}
          </span>
          <span class="flex gap-2">
            @if (guide.index() > 0) {
              <button
                mat-stroked-button
                type="button"
                (click)="guide.back()"
                data-testid="tour-back"
              >
                {{ 'tour.back' | translate }}
              </button>
            }
            <button mat-flat-button type="button" (click)="guide.next()" data-testid="tour-next">
              {{ (last() ? 'tour.finish' : 'tour.next') | translate }}
            </button>
          </span>
        </div>
      </section>
    }
  `,
})
export class TourCard {
  protected readonly guide = inject(TourGuide);
  private readonly title = viewChild<ElementRef<HTMLElement>>('title');

  protected readonly step = computed(() => this.guide.tour()?.steps[this.guide.index()] ?? null);
  protected readonly last = computed(
    () => this.guide.index() + 1 === (this.guide.tour()?.steps.length ?? 0),
  );
  protected readonly progress = computed(() => ({
    index: String(this.guide.index() + 1),
    count: String(this.guide.tour()?.steps.length ?? 0),
  }));

  constructor() {
    const injector = inject(Injector);
    // Each step drawn moves the focus to its title, so a screen reader reads the step and Tab reaches its buttons.
    effect(() => {
      this.guide.focusRequest();
      afterNextRender(() => this.title()?.nativeElement.focus(), { injector });
    });
  }
}
