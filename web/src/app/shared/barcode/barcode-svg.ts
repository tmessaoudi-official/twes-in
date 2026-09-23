// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { linearBarcode } from './linear-barcode';

/** The light margin each side of the bars, in modules: EAN asks 11 and 7, Code 128 10; ten serves a printed label. */
const QUIET_ZONE = 10;
/** The bars' height in modules, near EAN's own proportion at its nominal size. */
const HEIGHT = 60;

/**
 * A one-dimensional barcode drawn as SVG (docs/SPEC.md § 7, 2026-09-23 slice 8): one rect per bar, dark on white
 * whatever the colour scheme, because a scanner reads contrast. Nothing is drawn for a value no symbology here
 * carries. Presentation attributes only: the Content Security Policy refuses style attributes.
 */
@Component({
  selector: 'app-barcode-svg',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (bars(); as bars) {
      <svg
        xmlns="http://www.w3.org/2000/svg"
        role="img"
        [attr.aria-label]="value()"
        [attr.viewBox]="'0 0 ' + bars.width + ' ' + height"
        preserveAspectRatio="none"
        shape-rendering="crispEdges"
        class="block h-full w-full"
      >
        <rect x="0" y="0" [attr.width]="bars.width" [attr.height]="height" fill="#ffffff" />
        @for (bar of bars.runs; track $index) {
          <rect
            data-bar
            [attr.x]="bar.x"
            y="0"
            [attr.width]="bar.width"
            [attr.height]="height"
            fill="#000000"
          />
        }
      </svg>
    }
  `,
})
export class BarcodeSvg {
  readonly value = input.required<string>();
  protected readonly height = HEIGHT;

  protected readonly bars = computed(() => {
    const code = linearBarcode(this.value());
    if (code === null) return null;
    const runs = [...code.modules.matchAll(/1+/g)].map((run) => ({
      x: QUIET_ZONE + run.index,
      width: run[0].length,
    }));
    return { runs, width: code.modules.length + 2 * QUIET_ZONE };
  });
}
