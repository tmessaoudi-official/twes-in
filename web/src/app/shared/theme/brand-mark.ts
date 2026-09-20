// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * The product's own mark (docs/SPEC.md § 7, 2026-09-20): the initial in round, geometric lower case with a rising
 * arrow where the dot of an i would sit.
 *
 * It is the same drawing as `public/icon.svg`, with one deliberate difference: the tile and the strokes take their
 * colours from the theme rather than naming them, so the mark follows the installation's accent and both schemes.
 * The favicon cannot do that — it is fetched outside the running app, where no custom property exists — which is why
 * the two are not one file. Keep the path data identical between them; only the colours differ.
 *
 * Nothing here is configurable yet: row 36 is what puts the mark behind a brand port an operator can replace.
 */
@Component({
  selector: 'app-brand-mark',
  template: `
    <svg
      [attr.width]="size()"
      [attr.height]="size()"
      viewBox="0 0 32 32"
      [attr.role]="label() === '' ? null : 'img'"
      [attr.aria-label]="label() === '' ? null : label()"
      [attr.aria-hidden]="label() === '' ? 'true' : null"
    >
      <rect width="32" height="32" rx="7.5" class="fill-primary" />
      <g
        fill="none"
        class="stroke-on-primary"
        stroke-width="2.6"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path d="M11.4 6.6V18.2c0 2.3 1.3 3.6 3.5 3.6" />
        <path d="M7.3 11.1h8.2" />
        <path d="M19.6 13.2 25.4 7.4" />
        <path d="M20.9 7.4h4.5v4.5" />
      </g>
    </svg>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BrandMark {
  readonly size = input(24);

  /**
   * What a screen reader says. It defaults to empty because the mark almost always sits beside the name in text,
   * where announcing it again would say the product's name twice.
   */
  readonly label = input('');
}
