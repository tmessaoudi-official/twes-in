// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, Injectable } from '@angular/core';
import { saidAlready } from '../shared/errors/said-already';
import { LatestRequest, requestState } from '../shared/feedback/request-state';
import { sessionSignal } from '../shared/session/session-data';
import { StepUp } from '../shared/step-up/step-up';
import { stepUpAsked } from '../shared/step-up/step-up-api';
import { TextCatalogueApi } from './text-catalogue-api';
import {
  type SavedText,
  type TextLevel,
  type TextPage,
  type TextQuery,
  type TextRefusalCode,
  TextRefused,
  type TextRow,
} from './texts-types';

/** A write's outcome: what was kept, why it was not, or nothing to say when an interceptor has said it already. */
export type TextOutcome<T> =
  | { readonly kept: true; readonly answer: T }
  | { readonly kept: false; readonly refusal: TextRefusalCode | null };

/**
 * « Textes »: a page of the texts a level may word otherwise, read again as the words, the place or the language
 * change, and each text worded or taken back one at a time.
 */
@Injectable({ providedIn: 'root' })
export class TextsFacade {
  private readonly api = inject(TextCatalogueApi);
  private readonly stepUp = inject(StepUp);
  private readonly pageSignal = sessionSignal<TextPage>({ rows: [], total: 0 });
  /** A read fails in one way only, the API not reached or refusing: the page says so without a reason. */
  private readonly state = requestState<true>(() => true);
  private readonly latest = new LatestRequest();

  readonly rows = computed(() => this.pageSignal().rows);
  readonly total = computed(() => this.pageSignal().total);
  readonly busy = this.state.busy;
  readonly failed = computed(() => this.state.error() !== null);

  async load(level: TextLevel, language: string, query: TextQuery): Promise<void> {
    const current = this.latest.next();
    await this.state.read(
      async () => {
        const page = await this.api.page(level, language, query);
        if (current()) this.pageSignal.set(page);
      },
      { current, failed: () => this.pageSignal.set({ rows: [], total: 0 }) },
    );
  }

  write(
    level: TextLevel,
    language: string,
    text: Pick<TextRow, 'domain' | 'key'>,
    value: string,
    version: number,
  ): Promise<TextOutcome<SavedText>> {
    return this.outcome(level, () => this.api.write(level, language, text, value, version));
  }

  reset(
    level: TextLevel,
    language: string,
    text: Pick<TextRow, 'domain' | 'key'>,
  ): Promise<TextOutcome<null>> {
    return this.outcome(level, async () => {
      await this.api.reset(level, language, text);
      return null;
    });
  }

  /**
   * Runs a write marked busy; the installation's waits for the operator to prove who is at the screen again, as every
   * platform write does (docs/SPEC.md § 7, the step-up list).
   */
  private async outcome<T>(level: TextLevel, call: () => Promise<T>): Promise<TextOutcome<T>> {
    try {
      const answer = await this.state.marked(() =>
        level.kind === 'installation'
          ? this.stepUp.retrying(call, stepUpAsked, 'step_up.intro_change')
          : call(),
      );
      return { kept: true, answer };
    } catch (error) {
      if (saidAlready(error)) return { kept: false, refusal: null };
      return { kept: false, refusal: error instanceof TextRefused ? error.code : 'unavailable' };
    }
  }
}
