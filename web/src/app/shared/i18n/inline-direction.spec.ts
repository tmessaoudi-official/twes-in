// SPDX-License-Identifier: AGPL-3.0-or-later

import { arrowAlongLine, scrolledFromStart, towardsEnd } from './inline-direction';

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

  it("reads how far a box is scrolled from the line's start, whatever sign the browser gives it", () => {
    expect(scrolledFromStart({ scrollLeft: 40 })).toBe(40);
    expect(scrolledFromStart({ scrollLeft: -40 })).toBe(40);
    expect(scrolledFromStart({ scrollLeft: 0 })).toBe(0);
  });
});
