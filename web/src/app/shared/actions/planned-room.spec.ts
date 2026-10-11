// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { plannedFits } from './planned-room';

describe('plannedFits', () => {
  // A 300 px title, a 500 px bar row, one 150 px « Bientôt » button: on one row they take 300 + 16 + 500 + 150 + 8.
  const room = {
    title: 300,
    row: 500,
    planned: [150],
    shown: false,
    more: 0,
    moreDrawn: false,
    moreStays: false,
  };

  it('shows them where the title and the bar keep their one row', () => {
    expect(plannedFits({ ...room, header: 974 })).toBe(true);
  });

  it('keeps them in « ⋮ » where they would push the bar under the title', () => {
    // Without them the bar sits beside the title (816 px); with them it would not.
    expect(plannedFits({ ...room, header: 973 })).toBe(false);
  });

  it('answers the same once they are drawn, so showing them cannot fold them again', () => {
    // Drawn, the row is 158 px wider; what it holds without them is unchanged.
    expect(plannedFits({ ...room, row: 658, shown: true, header: 974 })).toBe(true);
    expect(plannedFits({ ...room, row: 658, shown: true, header: 973 })).toBe(false);
  });

  it('shows them where the bar already has a row of its own and they fit on it', () => {
    expect(plannedFits({ ...room, header: 658 })).toBe(true);
    expect(plannedFits({ ...room, header: 657 })).toBe(false);
  });

  it('counts every planned button and the gap before each', () => {
    expect(plannedFits({ ...room, planned: [150, 120], header: 974 + 128 })).toBe(true);
    expect(plannedFits({ ...room, planned: [150, 120], header: 974 + 127 })).toBe(false);
  });

  it('has nothing to show without a planned action', () => {
    expect(plannedFits({ ...room, planned: [], header: 4000 })).toBe(false);
  });

  // A vendor with a long name, as drawn in a 960 px header: folded, « ⋮ » (40 px) holds the planned entry alone and
  // the bar sits on its own row; shown, « ⋮ » has nothing left and leaves. Each state must give the same answer, or
  // the bar flips every frame and nothing in it can be clicked.
  const vendor = { header: 960, title: 595, planned: [345.3], more: 40, moreStays: false };

  it('answers the same whether « ⋮ » leaves the row with them or not', () => {
    expect(plannedFits({ ...vendor, row: 380.3, shown: false, moreDrawn: true })).toBe(true);
    expect(plannedFits({ ...vendor, row: 685.6, shown: true, moreDrawn: false })).toBe(true);
  });

  it('counts « ⋮ » beside them where it keeps other entries', () => {
    // Folded the bar is 380.3 px; shown with « ⋮ » kept it is 733.6 px, and needs that much room.
    const kept = { ...vendor, moreStays: true };
    expect(plannedFits({ ...kept, header: 734, row: 380.3, shown: false, moreDrawn: true })).toBe(
      true,
    );
    expect(plannedFits({ ...kept, header: 733, row: 380.3, shown: false, moreDrawn: true })).toBe(
      false,
    );
    expect(plannedFits({ ...kept, header: 734, row: 733.6, shown: true, moreDrawn: true })).toBe(
      true,
    );
    expect(plannedFits({ ...kept, header: 733, row: 733.6, shown: true, moreDrawn: true })).toBe(
      false,
    );
  });

  it('keeps them folded where the bar beside the title would go under it once they are shown', () => {
    // Folded with « ⋮ »: 300 + 16 + 548 = 864 fits beside the title; shown without « ⋮ », 300 + 16 + 658 = 974 does
    // not, and a new row is what they must never cost.
    const beside = { ...room, row: 548, more: 40, moreDrawn: true, header: 973 };
    expect(plannedFits(beside)).toBe(false);
    expect(plannedFits({ ...beside, row: 658, shown: true, moreDrawn: false })).toBe(false);
    expect(plannedFits({ ...beside, header: 974 })).toBe(true);
    expect(plannedFits({ ...beside, row: 658, shown: true, moreDrawn: false, header: 974 })).toBe(
      true,
    );
  });
});
