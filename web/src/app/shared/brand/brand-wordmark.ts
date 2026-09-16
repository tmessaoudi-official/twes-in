// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

export interface WordmarkSegment {
  readonly text: string;
  /** `joiner` is a hyphen or space, drawn muted; `arrow` is the one letter the rising arrow sits on. */
  readonly kind: 'letters' | 'joiner' | 'arrow';
}

const JOINER = /[\s\-·.]/;

/**
 * The Angle wordmark, computed from the name so a rename redraws it: lowercase, joiners muted, and the dot of the
 * last "i" replaced by a rising arrow. A name without an "i" carries the arrow over its last letter instead.
 */
export function wordmarkSegments(name: string): WordmarkSegment[] {
  const characters = name.trim() === '' ? [] : [...name.toLocaleLowerCase()];
  let arrowAt = characters.lastIndexOf('i');
  for (let index = characters.length - 1; arrowAt === -1 && index >= 0; index--) {
    if (!JOINER.test(characters[index])) {
      arrowAt = index;
    }
  }
  const segments: WordmarkSegment[] = [];
  characters.forEach((character, index) => {
    if (index === arrowAt) {
      segments.push({ text: character === 'i' ? 'ı' : character, kind: 'arrow' });
      return;
    }
    const kind = JOINER.test(character) ? 'joiner' : 'letters';
    const last = segments.at(-1);
    if (last !== undefined && last.kind === kind) {
      segments[segments.length - 1] = { text: last.text + character, kind };
    } else {
      segments.push({ text: character, kind });
    }
  });
  return segments;
}

@Component({
  selector: 'app-brand-wordmark',
  // Text only through bindings: an interpolation's surrounding whitespace would draw as a gap inside the name. The
  // arrow is the accent-coloured ::after of the arrow segment (styles.scss).
  template: `
    <span class="twes-wordmark" role="img" [attr.aria-label]="name()">
      <span aria-hidden="true">
        @for (segment of segments(); track $index) {
          <span [class]="'twes-wordmark-' + segment.kind" [textContent]="segment.text"></span>
        }
      </span>
    </span>
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BrandWordmark {
  readonly name = input.required<string>();
  protected readonly segments = computed(() => wordmarkSegments(this.name()));
}
