// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { AmountPipe } from '../i18n/format-pipes';
import type { IconName } from '../icons/icons';
import { LIFECYCLE_TONES, type LifecycleStage } from '../theme/lifecycle-tones';

/** Which way a figure moved against the one it is read against, and the sentence saying by how much. */
export interface KpiDelta {
  readonly kind: 'up' | 'down' | 'same';
  readonly words: string;
}

/** Where the tile's « Voir » leads: the list behind the figure. */
export interface KpiLink {
  readonly route: string;
  readonly queryParams?: Readonly<Record<string, string>>;
  readonly label: string;
}

const ARROWS: Readonly<Record<KpiDelta['kind'], IconName>> = {
  up: 'trending_up',
  down: 'trending_down',
  same: 'trending_flat',
};

/**
 * One figure of the home (the approved design's KPI tile, plan row S7.5): a strip naming it with its icon and the list
 * behind it, then the figure and its currency, the change against the period it is read against, and a caption; or a
 * sentence while there is no figure, never a dash. The strip wears the accent, which names the company; the change
 * takes a status tone from the stage the panel places it on (lifecycle-tones.ts), neutral by default as the design
 * draws it, and never the accent, which says no state. A card, not a link, so « Voir » is its one control.
 */
@Component({
  selector: 'app-kpi-tile',
  imports: [MatCardModule, MatIconModule, RouterLink, AmountPipe],
  template: `
    <mat-card
      appearance="outlined"
      class="h-full overflow-hidden"
      data-part="tile"
      [attr.data-hero]="hero()"
    >
      <div
        class="flex min-h-9 items-center gap-2 bg-accent-strip px-4 py-1.5 text-sm font-strong text-accent-text"
        data-part="strip"
      >
        <mat-icon aria-hidden="true" class="shrink-0">{{ icon() }}</mat-icon>
        <!-- value-direction: every text the tile is handed is in the screen's language, which reads in its direction -->
        <span class="min-w-0 flex-1" data-part="label">{{ label() }}</span>
        @if (see(); as link) {
          <!-- value-direction: a text in the screen's language -->
          <a
            [routerLink]="link.route"
            [queryParams]="link.queryParams ?? null"
            class="shrink-0 text-sm font-medium text-accent-text"
            data-part="see"
            >{{ link.label }}</a
          >
        }
      </div>
      <div class="flex flex-1 flex-col gap-2 px-4 pt-3 pb-4">
        @if (figure(); as value) {
          <span
            class="flex flex-wrap items-baseline gap-1.5 font-bold tabular-nums"
            [class.text-4xl]="hero()"
            [class.text-3xl]="!hero()"
            [style.color]="figureColour()"
            data-part="figure"
            >{{ value | amount: scale() }}
            @if (currency(); as code) {
              <bdi class="text-sm font-medium text-on-surface-variant">{{ code }}</bdi>
            }
          </span>
        } @else if (blank(); as sentence) {
          <!-- value-direction: a text in the screen's language -->
          <p class="text-sm text-on-surface-variant" data-part="blank">{{ sentence }}</p>
        }
        @if (delta(); as change) {
          <span
            class="inline-flex max-w-full items-center gap-1 self-start rounded-pill px-2 py-0.5 text-xs font-semibold tabular-nums"
            data-part="delta"
            [attr.data-tone]="deltaTone()"
            [style.--twes-delta-bg]="'var(--twes-status-' + deltaTone() + '-bg)'"
            [style.--twes-delta-fg]="'var(--twes-status-' + deltaTone() + '-fg)'"
            ><mat-icon aria-hidden="true" class="mat-icon-rtl-mirror shrink-0">{{
              arrow()
            }}</mat-icon
            ><!-- value-direction: the change said in the screen's language, its amount set by the text it is part of -->
            <span class="min-w-0">{{ change.words }}</span></span
          >
        }
        @if (caption(); as text) {
          <!-- value-direction: a text in the screen's language -->
          <span class="text-xs font-medium text-on-surface-variant" data-part="caption">{{
            text
          }}</span>
        }
        <ng-content />
      </div>
    </mat-card>
  `,
  styles: `
    :host {
      display: block;
      min-width: 0;
    }
    [data-part='delta'] {
      background-color: var(--twes-delta-bg);
      color: var(--twes-delta-fg);
    }
    [data-part='delta'] mat-icon,
    [data-part='strip'] mat-icon {
      width: var(--text-xl);
      height: var(--text-xl);
      font-size: var(--text-xl);
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class KpiTile {
  readonly icon = input.required<IconName>();
  /** What the figure is, as the screen says it. */
  readonly label = input.required<string>();
  /** The figure, as the API's decimal string; null while there is none, the tile then saying `blank`. */
  readonly figure = input<string | null>(null);
  readonly scale = input<number | null>(null);
  readonly currency = input<string | null>(null);
  readonly blank = input<string | null>(null);
  readonly delta = input<KpiDelta | null>(null);
  /** The stage the panel places the change on, whose status tone it takes; neutral unless the panel says otherwise. */
  readonly deltaStage = input<LifecycleStage>('not-started');
  /** A figure that is itself a state, money overdue: drawn in its stage's tone. */
  readonly figureStage = input<LifecycleStage | null>(null);
  readonly caption = input<string | null>(null);
  readonly see = input<KpiLink | null>(null);
  /** The first figure of a row, drawn larger. */
  readonly hero = input(false);

  protected readonly deltaTone = computed(() => LIFECYCLE_TONES[this.deltaStage()]);
  protected readonly arrow = computed(() => ARROWS[this.delta()?.kind ?? 'same']);
  protected readonly figureColour = computed(() => {
    const stage = this.figureStage();
    return stage === null ? null : `var(--twes-status-${LIFECYCLE_TONES[stage]}-fg)`;
  });
}
