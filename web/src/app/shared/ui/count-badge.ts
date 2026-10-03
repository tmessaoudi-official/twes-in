// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { MatIconModule } from '@angular/material/icon';
import { MatTooltip } from '@angular/material/tooltip';
import type { IconName } from '../icons/icons';
import type { StatusTone } from '../theme/accent-theme';

/** What a number counts: things waiting for someone, things that need attention now, or a plain total. */
export type CountKind = 'waiting' | 'attention' | 'total';

const KINDS: Record<CountKind, { icon: IconName; tone: StatusTone }> = {
  waiting: { icon: 'schedule', tone: 'info' },
  attention: { icon: 'error', tone: 'danger' },
  total: { icon: 'format_list_numbered', tone: 'neutral' },
};

/**
 * A count a person can read at a glance (docs/SPEC.md § 7, 2026-10-04): a tinted pill whose icon says what kind of
 * number it is, so « 12 » is never a bare digit. The sentence — « 12 à valider » — is the pill's accessible name and
 * its tooltip, and `max` caps what is drawn (« 9+ ») without touching what is said. What waits or needs attention
 * draws nothing at zero; a total draws its zero.
 */
@Component({
  selector: 'app-count-badge',
  imports: [MatIconModule, MatTooltip],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (shown()) {
      <span
        role="img"
        class="inline-flex items-center gap-1 rounded-full py-0.5 pr-2 pl-1.5 text-xs leading-4 font-medium whitespace-nowrap"
        [attr.data-kind]="kind()"
        [style.background-color]="'var(--twes-status-' + tone() + '-bg)'"
        [style.color]="'var(--twes-status-' + tone() + '-fg)'"
        [attr.aria-label]="label()"
        [matTooltip]="label()"
      >
        <mat-icon aria-hidden="true" class="count-icon">{{ icon() }}</mat-icon>
        <span data-count aria-hidden="true">{{ text() }}</span>
      </span>
    }
  `,
  styles: `
    :host {
      display: inline-flex;
    }
    .count-icon {
      width: 1rem;
      height: 1rem;
      font-size: 1rem;
      line-height: 1rem;
    }
  `,
})
export class CountBadge {
  readonly count = input.required<number>();
  readonly kind = input<CountKind>('waiting');
  /** The count in words, already translated: « 3 en attente ». */
  readonly label = input.required<string>();
  /** Past this, the pill reads « 99+ »; a total is never capped unless a screen asks, because its figure is the point. */
  readonly max = input<number | null>(null);

  protected readonly icon = computed(() => KINDS[this.kind()].icon);
  protected readonly tone = computed(() => KINDS[this.kind()].tone);
  protected readonly shown = computed(() => this.kind() === 'total' || this.count() > 0);
  protected readonly text = computed(() => {
    const cap = this.max() ?? (this.kind() === 'total' ? null : 99);
    return cap !== null && this.count() > cap ? `${cap}+` : String(this.count());
  });
}
