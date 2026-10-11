// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  afterRenderEffect,
  DestroyRef,
  ElementRef,
  inject,
  signal,
  untracked,
  type Signal,
} from '@angular/core';

/** The gap between a header's title and its bar (`gap-4`), and between two buttons of a bar (`gap-2`). */
const HEADER_GAP = 16;
const BAR_GAP = 8;

/** What a page header measures, in pixels, with the planned buttons drawn or not. */
export interface HeaderRoom {
  /** The header's inner width. */
  header: number;
  /** The title block beside the bar. */
  title: number;
  /** The bar's row as drawn now: the working buttons, the back link, and the planned ones when they are shown. */
  row: number;
  /** The planned buttons' own widths, measured where they are drawn or kept out of the flow. */
  planned: readonly number[];
  /** Whether the planned buttons are in the row now. */
  shown: boolean;
  /** « ⋮ »'s width, as last drawn: it holds the planned entries while they are folded, so it is drawn then. */
  more: number;
  /** Whether « ⋮ » is in the row now: shown, the planned buttons may have left it nothing to hold. */
  moreDrawn: boolean;
  /** Whether « ⋮ » keeps other entries once the planned ones are shown, and so stays beside them. */
  moreStays: boolean;
}

/**
 * Whether a bar's « Bientôt » buttons fit beside the working ones: shown, they must add no row to the header. Where
 * the title and the bar share a row, both must still fit on it; where the bar already has a row of its own, it must
 * still fit on that one. Both layouts are worked out from the working buttons alone, without them and without « ⋮ »,
 * which may leave with them: so the answer is the same whichever is drawn, and the bar cannot flip between the two.
 */
export function plannedFits(room: HeaderRoom): boolean {
  if (room.planned.length === 0) return false;
  const planned = room.planned.reduce((sum, width) => sum + width + BAR_GAP, 0);
  const more = room.more > 0 ? room.more + BAR_GAP : 0;
  const working = room.row - (room.shown ? planned : 0) - (room.moreDrawn ? more : 0);
  const folded = working + more;
  const shown = working + planned + (room.moreStays ? more : 0);
  const beside = room.title + HEADER_GAP;
  return beside + folded <= room.header ? beside + shown <= room.header : shown <= room.header;
}

/**
 * Watches the document header a bar sits in and says whether its planned buttons fit; false until measured, and
 * always where nothing can be measured, so they wait in « ⋮ » rather than push the working ones onto a second row.
 * Each planned button carries `data-planned`, in the row or kept out of the flow, so its width is known either way.
 * Measured again after every render that changes `planned`, since a button kept out of the flow resizes nothing.
 * The bar's « ⋮ » carries `data-bar-more`; `moreStays` says whether it holds anything once they are shown.
 */
export function plannedRoom(
  planned: () => readonly unknown[],
  moreStays: () => boolean,
): Signal<boolean> {
  const host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  const fits = signal(false);
  let observer: ResizeObserver | null = null;
  let measure: (() => void) | null = null;
  // Kept from the last time « ⋮ » was drawn: shown, the planned buttons may have emptied it out of the row.
  let more = 0;
  const watch = (): (() => void) | null => {
    const header = host.closest<HTMLElement>('.twes-document-header');
    if (header === null || typeof ResizeObserver === 'undefined') return null;
    let row: HTMLElement = host;
    while (row.parentElement !== null && row.parentElement !== header) row = row.parentElement;
    const title = [...header.children].find((child) => child !== row);
    const width = (element: Element | undefined) => element?.getBoundingClientRect().width ?? 0;
    const remeasure = () => {
      const buttons = [...host.querySelectorAll('[data-planned]')];
      // A button's words can arrive after it is drawn: its own width changing is measured too.
      buttons.forEach((each) => observer?.observe(each));
      const drawnMore = host.querySelector('[data-bar-more]');
      if (drawnMore !== null) more = width(drawnMore);
      const answer = plannedFits({
        header: header.clientWidth,
        title: width(title),
        row: width(row),
        planned: buttons.map((each) => width(each)),
        shown: untracked(fits),
        more,
        moreDrawn: drawnMore !== null,
        moreStays: untracked(moreStays),
      });
      if (answer !== untracked(fits)) fits.set(answer);
    };
    observer = new ResizeObserver(remeasure);
    observer.observe(header);
    observer.observe(row);
    return remeasure;
  };
  afterRenderEffect(() => {
    planned();
    moreStays();
    measure ??= watch();
    measure?.();
  });
  inject(DestroyRef).onDestroy(() => observer?.disconnect());
  return fits.asReadonly();
}
