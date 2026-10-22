// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Direction } from '@angular/cdk/bidi';

/**
 * A screen reads its sides from where a line starts and ends, which is the right in Arabic: what the keyboard and the
 * pointer say in left and right is turned into that here, for the code that measures or moves things itself.
 */

/** An arrow key's step along the line: 1 towards its end, -1 towards its start, 0 for any other key. */
export function arrowAlongLine(key: string, direction: Direction): -1 | 0 | 1 {
  const forwards = direction === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
  const backwards = direction === 'rtl' ? 'ArrowRight' : 'ArrowLeft';
  return key === forwards ? 1 : key === backwards ? -1 : 0;
}

/** How far a move across the screen (to the right when positive) goes towards the end of the line. */
export function towardsEnd(dx: number, direction: Direction): number {
  return direction === 'rtl' ? -dx : dx;
}

/**
 * Brings an item into view along the line inside its own scroll box, moving that box alone: `scrollIntoView` scrolls
 * every scrolling box around it too, so a page scrolled down jumped back up to a tab bar whose labels changed size.
 * The distance is read on screen from the item's and the box's edges, the same in either direction, and moves a box
 * scrolled negative in right to left the same way.
 */
export function revealAlongLine(box: HTMLElement, item: HTMLElement): void {
  const outer = box.getBoundingClientRect();
  const inner = item.getBoundingClientRect();
  // physical-direction: screen coordinates, which scrollLeft counts in whatever way the line runs
  const shift =
    inner.left < outer.left
      ? inner.left - outer.left
      : inner.right > outer.right
        ? inner.right - outer.right
        : 0;
  if (shift !== 0) box.scrollLeft += shift;
}

/** How far a box is scrolled from the line's start: browsers count it negative in right to left. */
export function scrolledFromStart(box: { readonly scrollLeft: number }): number {
  return Math.abs(box.scrollLeft);
}
