// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, Pipe, type PipeTransform } from '@angular/core';
import { TranslateService } from '@ngx-translate/core';
import { WebParameters } from '../build/web-parameters';
import { Session } from '../session/session';

/**
 * A duration an operator sets, said in the screen's language with its plural form: in hours when it is a whole number
 * of them, else in minutes, else in seconds, so the screen never promises less or more than is so (`duration.*` in the
 * main texts, which every page holds).
 */
@Injectable({ providedIn: 'root' })
export class DurationWords {
  private readonly translate = inject(TranslateService);
  private readonly parameters = inject(WebParameters);
  private readonly session = inject(Session, { optional: true });

  of(seconds: number): string {
    const [unit, count] =
      seconds % 3600 === 0
        ? (['hours', seconds / 3600] as const)
        : seconds % 60 === 0
          ? (['minutes', seconds / 60] as const)
          : (['seconds', seconds] as const);
    return this.translate.instant(`duration.${unit}`, { count }) as string;
  }

  /**
   * How long a delete, a merge or an import can be taken back, in seconds: the working company's own window, which its
   * deletes keep (`records.undoable_for`), else the operator's while no company is open.
   */
  recordsUndoableSeconds(): number {
    const own = this.session?.me()?.company?.recordsUndoableSeconds;
    return own !== undefined && own > 0 ? own : this.parameters.recordsUndoableSeconds();
  }

  /** The same as a toast or a confirmation says it. */
  recordsUndoable(): string {
    return this.of(this.recordsUndoableSeconds());
  }
}

/** The same in a template; impure, as the translate pipe is, so a change of language says it again. */
@Pipe({ name: 'duration', pure: false })
export class DurationPipe implements PipeTransform {
  private readonly words = inject(DurationWords);

  transform(seconds: number): string {
    return this.words.of(seconds);
  }
}
