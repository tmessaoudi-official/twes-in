// SPDX-License-Identifier: AGPL-3.0-or-later

import { arrowAlongLine, revealAlongLine, scrolledFromStart, towardsEnd } from './inline-direction';

describe('inline direction', () => {
  it('reads an arrow as a step towards the end of the line or its start, whichever way the line runs', () => {
    expect(arrowAlongLine('ArrowRight', 'ltr')).toBe(1);
    expect(arrowAlongLine('ArrowLeft', 'ltr')).toBe(-1);
    expect(arrowAlongLine('ArrowRight', 'rtl')).toBe(-1);
    expect(arrowAlongLine('ArrowLeft', 'rtl')).toBe(1);
    expect(arrowAlongLine('ArrowUp', 'rtl')).toBe(0);
    expect(arrowAlongLine('a', 'ltr')).toBe(0);
  });

  it('reads a move across the screen as a distance towards the end of the line', () => {
    expect(towardsEnd(12, 'ltr')).toBe(12);
    expect(towardsEnd(12, 'rtl')).toBe(-12);
    expect(towardsEnd(-5, 'rtl')).toBe(5);
  });

  describe('revealAlongLine', () => {
    /** jsdom lays nothing out: each element says where it stands on screen, and the box counts its own scroll. */
    function placed(left: number, right: number): HTMLElement {
      const element = document.createElement('div');
      element.getBoundingClientRect = () => ({ left, right }) as DOMRect;
      return element;
    }
    function box(left: number, right: number, scrollLeft: number): HTMLElement {
      const element = placed(left, right);
      let scrolled = scrollLeft;
      Object.defineProperty(element, 'scrollLeft', {
        configurable: true,
        get: () => scrolled,
        set: (value: number) => (scrolled = value),
      });
      return element;
    }

    it('moves the box by what the item stands past its edge, either edge', () => {
      const past = box(0, 300, 0);
      revealAlongLine(past, placed(320, 420));
      expect(past.scrollLeft).toBe(120);

      const before = box(0, 300, 200);
      revealAlongLine(before, placed(-50, 50));
      expect(before.scrollLeft).toBe(150);
    });

    it('moves a right-to-left box, which counts its scroll negative, by the same distance', () => {
      const rtl = box(0, 300, 0);
      revealAlongLine(rtl, placed(-130, -30));
      expect(rtl.scrollLeft).toBe(-130);
    });

    it('leaves the box where it is when the item is already in view', () => {
      const shown = box(0, 300, 40);
      revealAlongLine(shown, placed(100, 200));
      expect(shown.scrollLeft).toBe(40);
    });

    it('never asks the item to scroll itself into view, which would move the page around the box too', () => {
      const item = placed(320, 420);
      const scrolled = vi.fn();
      item.scrollIntoView = scrolled;
      revealAlongLine(box(0, 300, 0), item);
      expect(scrolled).not.toHaveBeenCalled();
    });
  });

  it("reads how far a box is scrolled from the line's start, whatever sign the browser gives it", () => {
    expect(scrolledFromStart({ scrollLeft: 40 })).toBe(40);
    expect(scrolledFromStart({ scrollLeft: -40 })).toBe(40);
    expect(scrolledFromStart({ scrollLeft: 0 })).toBe(0);
  });
});
