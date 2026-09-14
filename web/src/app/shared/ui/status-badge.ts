// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import type { StatusTone } from '../theme/accent-theme';

/**
 * A status in words on a soft pill, a dot in its tone beside it. The label is projected, so each screen keeps its own
 * translation; the colours are the theme's `--twes-status-<tone>-*` tokens (accent-theme.ts), so both schemes and any
 * accent keep the label readable.
 */
@Component({
  selector: 'app-status-badge',
  template: `<span data-part="dot" aria-hidden="true" class="dot"></span><ng-content />`,
  styles: `
    :host {
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      padding: 0.125rem 0.5rem;
      border-radius: 9999px;
      background-color: var(--status-bg);
      color: var(--status-fg);
      font-size: 0.75rem;
      line-height: 1rem;
      font-weight: 500;
      white-space: nowrap;
    }
    .dot {
      flex-shrink: 0;
      width: 0.375rem;
      height: 0.375rem;
      border-radius: 9999px;
      background-color: var(--status-dot);
    }
  `,
  host: {
    '[attr.data-tone]': 'tone()',
    '[style.--status-bg]': 'bg()',
    '[style.--status-fg]': 'fg()',
    '[style.--status-dot]': 'dot()',
  },
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatusBadge {
  readonly tone = input.required<StatusTone>();

  protected readonly bg = computed(() => `var(--twes-status-${this.tone()}-bg)`);
  protected readonly fg = computed(() => `var(--twes-status-${this.tone()}-fg)`);
  protected readonly dot = computed(() => `var(--twes-status-${this.tone()}-dot)`);
}
