// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { generate } from 'lean-qr';

/** The standard quiet zone around a QR code, in modules: a scanner needs it to find the code's edges. */
const QUIET_ZONE = 4;

/**
 * A QR code drawn as SVG, one rect per dark module, dark on white whatever the colour scheme, because an
 * authenticator app reads contrast. Presentation attributes only: the Content Security Policy refuses style
 * attributes.
 */
@Component({
  selector: 'app-qr-code',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <svg
      xmlns="http://www.w3.org/2000/svg"
      role="img"
      [attr.aria-label]="label()"
      [attr.viewBox]="viewBox()"
      shape-rendering="crispEdges"
      class="block h-auto w-full"
    >
      <rect x="0" y="0" [attr.width]="side()" [attr.height]="side()" fill="#ffffff" />
      @for (module of modules(); track $index) {
        <rect
          data-module
          [attr.x]="module.x"
          [attr.y]="module.y"
          width="1"
          height="1"
          fill="#000000"
        />
      }
    </svg>
  `,
})
export class QrCode {
  readonly value = input.required<string>();
  readonly label = input.required<string>();

  private readonly code = computed(() => generate(this.value()));
  protected readonly side = computed(() => this.code().size + 2 * QUIET_ZONE);
  protected readonly viewBox = computed(() => `0 0 ${this.side()} ${this.side()}`);
  protected readonly modules = computed(() => {
    const code = this.code();
    const dark: { x: number; y: number }[] = [];
    for (let y = 0; y < code.size; y += 1) {
      for (let x = 0; x < code.size; x += 1) {
        if (code.get(x, y)) {
          dark.push({ x: x + QUIET_ZONE, y: y + QUIET_ZONE });
        }
      }
    }
    return dark;
  });
}
