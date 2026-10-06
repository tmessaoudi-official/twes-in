// SPDX-License-Identifier: AGPL-3.0-or-later

import { Directive, effect, ElementRef, inject, input } from '@angular/core';
import { MatTooltip } from '@angular/material/tooltip';

/**
 * A tooltip that says a label's whole text only when its ellipsis cut it, such as a menu entry beside its « Bientôt »
 * chip. Measured as the pointer arrives, since the room a label has changes with the window and the menu's width. It
 * names nothing for assistive technology: the text is already there whole, unlike `appLabel`, which names a control.
 */
@Directive({
  selector: '[appCutTooltip]',
  hostDirectives: [{ directive: MatTooltip, inputs: ['matTooltipPosition'] }],
  host: { '(mouseenter)': 'measure()' },
})
export class CutTooltip {
  readonly text = input.required<string>({ alias: 'appCutTooltip' });
  private readonly tooltip = inject(MatTooltip);
  private readonly element: HTMLElement = inject(ElementRef).nativeElement;

  constructor() {
    effect(() => {
      this.tooltip.message = this.text();
    });
  }

  /** Runs before the tooltip's own pointer listener, which reads `disabled` to decide whether to show. */
  protected measure(): void {
    this.tooltip.disabled = this.element.scrollWidth <= this.element.clientWidth;
  }
}
