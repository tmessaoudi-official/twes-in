// SPDX-License-Identifier: AGPL-3.0-or-later

// What the plan writes on a rectangle (docs/SPEC.md § 7, 2026-09-22).
//
// A store that arrived with its building already numbered reads its own numbers; one that named it reads names; one
// changing from the first to the second wants both while it learns. That is a property of the reader, not of the
// data, so it is a presentation preference and not a column — which is why the modes themselves are declared in the
// settings registry, where every key's permitted values live, and this file holds only what is written.

import type { PlanLabelMode } from '../shared/settings/settings-registry';

/** What separates the two halves under `both`: the same middle dot the pickers and the lists already use. */
const BETWEEN = ' · ';

/**
 * The one line written inside a rectangle, for anything the plan draws.
 *
 * Both arguments may be empty, and each emptiness means something different. A stock location always has a code and
 * may have no name worth reading, so asking for the name falls back to the code: a blank label is indistinguishable
 * from a rectangle nobody drew, which would read as data loss. A piece of the building is the mirror — it has no
 * code and never will, because nothing on that layer holds goods — so it is labelled by its name in every mode
 * rather than disappearing whenever the reader asks for codes.
 */
export function planLabel(code: string, name: string, mode: PlanLabelMode): string {
  const numbered = code.trim();
  const called = name.trim();

  if (numbered === '') return called;
  if (called === '') return numbered;

  switch (mode) {
    case 'code':
      return numbered;
    case 'name':
      return called;
    case 'both':
      return `${numbered}${BETWEEN}${called}`;
  }
}

/** The size the plan draws a stock rectangle's label at, in the same metres the plan itself is measured in. */
export const LABEL_FONT = 0.32;

/** The building's own labels, a shade smaller: a wall names itself without competing with what stands against it. */
export const STRUCTURE_LABEL_FONT = 0.28;

/**
 * The share of the font size one character takes, averaged over mixed-case text in the page's own sans-serif.
 *
 * Measured, not guessed: on the rendered plan a 35-character label at `LABEL_FONT` ran 6,1 m across a 3,9 m rack,
 * which is 0,174 m a character, or 0,545 of the font size (2026-09-22). Rounded up, because the cost of the two
 * errors is not symmetric — a budget slightly too small drops a character, one too large puts the label back on
 * the floor beside its rectangle, which is the defect this exists to stop.
 */
const PER_CHARACTER = 0.55;

/** The inset the label is drawn at, taken off both sides so a cut label does not touch the far edge either. */
const INSET = 0.15;

/**
 * The most of a label that fits inside a rectangle that wide, cut with an ellipsis when it does not.
 *
 * A plan is a scale drawing, so a label is as wide as the thing it names is long — nothing reflows and nothing
 * scrolls. Left alone, a long one runs across the floor and reads as belonging to whatever it lands on. The full
 * text is still carried to the reader as the drawn element's own title, so cutting it hides nothing.
 */
export function fitLabel(label: string, width: number, fontSize: number): string {
  if (label === '') return '';
  const room = width - INSET * 2;
  const budget = Math.floor(room / (fontSize * PER_CHARACTER));
  if (budget >= label.length) return label;
  // One character plus an ellipsis is two glyphs saying nothing; below that, the rectangle is better bare.
  if (budget < 2) return '';

  return `${label.slice(0, budget - 1)}…`;
}
