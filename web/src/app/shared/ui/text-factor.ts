// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  computed,
  DestroyRef,
  DOCUMENT,
  inject,
  Injectable,
  type Signal,
  signal,
} from '@angular/core';

/**
 * How wide the window is, in Material's window size classes. The shell lays itself out by it — a bottom bar on a
 * phone, a rail, a labelled drawer — and a list uses the same boundary to stop being a table, so the two cannot
 * disagree about where a phone ends.
 */
export type WindowClass = 'compact' | 'medium' | 'expanded';

/** Where a phone ends and a labelled menu starts, in effective pixels: CSS pixels at 100 % text. */
export const COMPACT_BELOW = 600;
export const EXPANDED_FROM = 1200;

/** The class of a window this many CSS pixels wide at this text factor. */
export function windowClassOf(width: number, factor: number): WindowClass {
  const effective = width / factor;
  return effective < COMPACT_BELOW ? 'compact' : effective >= EXPANDED_FROM ? 'expanded' : 'medium';
}

/**
 * How much larger the text is than at 100 %: the root's font size over 16 px. It covers the device's text size and a
 * browser whose own default size was raised. A rem-sized probe tells it when that changes, whatever changed it: a
 * size chosen, the stylesheet arriving after the boot script set the class, the browser's setting.
 */
@Injectable({ providedIn: 'root' })
export class TextFactor {
  private readonly document = inject(DOCUMENT);
  private readonly measured = signal(this.measure());
  readonly factor: Signal<number> = this.measured.asReadonly();
  private readonly width = signal(this.document.defaultView?.innerWidth ?? EXPANDED_FROM);
  /**
   * The window class on effective pixels, for the root's `data-window` the stylesheet's phone rules read, on every page,
   * a signed-out one included. Read from the window's width, not through the CDK's BreakpointObserver as WINDOW_CLASS
   * is, so the first page, where ThemeFacade writes it, does not carry the CDK's layout module.
   */
  readonly windowClass = computed(() => windowClassOf(this.width(), this.factor()));

  constructor() {
    const view = this.document.defaultView;
    if (view === null) return;
    const resized = () => this.width.set(view.innerWidth);
    view.addEventListener('resize', resized, { passive: true });
    inject(DestroyRef).onDestroy(() => view.removeEventListener('resize', resized));
    const body = this.document.body;
    if (body === null || typeof view.ResizeObserver === 'undefined') return;
    const probe = this.document.createElement('div');
    probe.setAttribute('aria-hidden', 'true');
    probe.className = 'twes-text-probe';
    // Through the CSSOM, which the CSP's style-src does not govern; a style attribute it would refuse.
    Object.assign(probe.style, {
      position: 'absolute',
      insetBlockStart: '0',
      insetInlineStart: '0',
      inlineSize: '1rem',
      blockSize: '0',
      visibility: 'hidden',
      pointerEvents: 'none',
    });
    body.appendChild(probe);
    const observer = new view.ResizeObserver(() => this.measured.set(this.measure()));
    observer.observe(probe);
    inject(DestroyRef).onDestroy(() => {
      observer.disconnect();
      probe.remove();
    });
  }

  private measure(): number {
    const view = this.document.defaultView;
    const size = Number.parseFloat(
      view?.getComputedStyle(this.document.documentElement).fontSize ?? '',
    );
    // Nothing laid out (a test's document) reads as no size: the text is then at 100 %.
    return Number.isFinite(size) && size > 0 ? size / 16 : 1;
  }
}
