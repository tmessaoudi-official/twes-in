// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import type { TranslateService } from '@ngx-translate/core';
import type { FormAnswer } from '../feedback/request-state';
import type { FieldRefusal } from './field-refusals';
import type { FieldError } from './form-builder';
import type { RefusalFigures } from './refusal-figures';
import { refusedError } from './refusal-words';

/**
 * A form that asks for a step and stays open when the API refuses it, a dialog's as the receipt's does or one inline:
 * each refusal said under its field, the first that no field takes said on the form's own line in its reason's words.
 * A step kept, or one that failed otherwise (said by the page), is for the form to close on.
 */
export class FormStep {
  readonly busy = signal(false);
  /** What the last refusal said that no field of the form took. */
  readonly unplaced = signal<FieldError | null>(null);

  constructor(
    private readonly translate: TranslateService,
    private readonly figures: RefusalFigures,
  ) {}

  /**
   * Runs the step; `place` says the refusals under the fields it can and hands back the rest. Answers what was kept,
   * null for a failure the page says, or `refused` while the form holds.
   */
  async run<T>(
    step: () => Promise<FormAnswer<T>>,
    place: (refusals: readonly FieldRefusal[]) => readonly FieldRefusal[],
  ): Promise<{ readonly kept: T } | null | 'refused'> {
    this.busy.set(true);
    this.unplaced.set(null);
    try {
      const answer = await step();
      if (answer === null || 'kept' in answer) return answer;
      this.say(answer.refused, place);
      return 'refused';
    } finally {
      this.busy.set(false);
    }
  }

  /** Says refusals the form met: under the fields `place` takes, the first left on the form's own line. */
  say(
    refusals: readonly FieldRefusal[],
    place: (refusals: readonly FieldRefusal[]) => readonly FieldRefusal[],
  ): void {
    const [first] = place(refusals);
    this.unplaced.set(first === undefined ? null : this.wordsOf(first));
  }

  /** A refusal's words, its figures in the reader's formats, for a hand-written field to say under itself. */
  wordsOf(refusal: FieldRefusal): FieldError {
    return refusedError(this.translate, refusal, refusal.params, this.figures);
  }
}
