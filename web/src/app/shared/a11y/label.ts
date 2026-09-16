// SPDX-License-Identifier: AGPL-3.0-or-later

import { Directive, effect, inject, input } from '@angular/core';
import { MatTooltip } from '@angular/material/tooltip';

/**
 * One name for a control that shows no words of its own: its accessible name and, from the same string, the tooltip
 * a pointer sees, so the two can never say different things (docs/SPEC.md § 7, 2026-09-16 review). A tooltip is a
 * convenience only, never the one place something is explained: touch screens do not show it.
 */
@Directive({
  selector: '[appLabel]',
  hostDirectives: [
    {
      directive: MatTooltip,
      inputs: ['matTooltipPosition', 'matTooltipDisabled'],
    },
  ],
  host: { '[attr.aria-label]': 'appLabel()' },
})
export class Label {
  readonly appLabel = input.required<string>();

  constructor() {
    const tooltip = inject(MatTooltip);
    effect(() => {
      tooltip.message = this.appLabel();
    });
  }
}
