// SPDX-License-Identifier: AGPL-3.0-or-later

import { type Signal, signal } from '@angular/core';
import type { TranslateService } from '@ngx-translate/core';
import type { FieldRefusal } from '../form/field-refusals';
import type { FieldError } from '../form/form-builder';
import type { RefusalFigures } from '../form/refusal-figures';
import { refusedError } from '../form/refusal-words';

/**
 * The line a document's page says above its form when a step is refused. A refusal said under its field is not said
 * again there; one that no field shown takes (the document's lines as a whole, a field the form does not show) is said
 * in its own words rather than a list of everything that might be wrong; a code with no refusals behind it (a conflict,
 * a refusal without reasons) keeps the code's words. What the page placed holds for the refusals it placed only, so a
 * later step's refusals are read afresh.
 */
export class RefusalLine {
  private readonly placedOf = signal<{
    of: readonly FieldRefusal[];
    unplaced: readonly FieldRefusal[];
  } | null>(null);

  constructor(
    private readonly refusals: Signal<readonly FieldRefusal[]>,
    private readonly prefix: string,
    private readonly translate: TranslateService,
    private readonly figures: RefusalFigures,
  ) {}

  /** The page said `of` under its fields but for `unplaced`. */
  placed(of: readonly FieldRefusal[], unplaced: readonly FieldRefusal[]): void {
    this.placedOf.set({ of, unplaced });
  }

  /** What the line says for the page's error code, null when the fields already say it all. */
  for(code: string): FieldError | null {
    const refusals = this.refusals();
    if (code !== 'invalid' || refusals.length === 0) return { key: this.prefix + code, params: {} };
    const placed = this.placedOf();
    const unplaced = placed !== null && placed.of === refusals ? placed.unplaced : refusals;
    const [first] = unplaced;
    return first === undefined
      ? null
      : refusedError(this.translate, first, first.params, this.figures);
  }
}
