// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, type Signal, signal, untracked } from '@angular/core';
import { refusedFilterIn, type RefusedFilter } from '../errors/filter-refused';
import { saidAlready } from '../errors/said-already';
import type { FieldRefusal } from '../form/field-refusals';
import { QuietReads } from './quiet-reads';

/**
 * What a write asked from a form that stays open on a refusal (a dialog's, a form inline) answered: what the API kept,
 * or the fields it refused, which the form says under them; null for any other failure, which the screen says.
 */
export type FormAnswer<T> =
  { readonly kept: T } | { readonly refused: readonly FieldRefusal[] } | null;

/** How a read is told apart: whether it is still the latest of its kind, and what the facade keeps beside the code. */
export interface ReadOptions {
  /** False once a later read of the same kind began: this one's answer, its failure included, is then dropped. */
  readonly current?: () => boolean;
  /** Runs after the error is set, for what the facade keeps beside it (a list emptied, a detail). */
  readonly failed?: (error: unknown) => void;
}

export interface WriteOptions<E> {
  /** This write's own code for a failure, such as a demo refusal already said, which shows no error (null). */
  readonly code?: (error: unknown) => E | null;
  /** Runs after the error is set, for what the facade keeps beside it (the fields refused). */
  readonly failed?: (error: unknown) => void;
}

/**
 * What a facade says about its requests: whether the screen should wait (`busy`) and what the last one was refused
 * for (`error`, a code the screen translates). One count over every marked request, so busy holds until the last one
 * answers and a press is never sent twice. A read nobody asked for, run inside `QuietReads`, is not marked: marked, the
 * screen's actions would turn disabled for its moment and a click already on its way would be refused unseen. A write
 * is always marked. An error is cleared when the next request starts, and kept until then, but for a write's: a reload
 * nobody asked for (another member's change heard) is no act of the person, so it clears and replaces only what a read
 * said, and a refused save stays on the screen until they act again. A failure an interceptor
 * has already said in words (`saidAlready`: the demo's refusal, a write still being made or whose answer was lost)
 * sets no error, so the screen never says it a second time, nor something else; `failed` still runs.
 */
export class RequestState<E> {
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<E | null>(null);
  /** Whether the error shown was a write's refusal, which only the person's own next request takes away. */
  private readonly writeRefusedSignal = signal(false);
  private running = 0;

  readonly busy: Signal<boolean> = this.busySignal.asReadonly();
  readonly error: Signal<E | null> = this.errorSignal.asReadonly();
  /** What the last read was refused for among the filters it asked for, or false; such a read sets no `error`. */
  private readonly filterRefusedSignal = signal<false | RefusedFilter>(false);
  /**
   * Whether the last read failed: what a list says in place of its rows, with « Réessayer » (or, for a refused filter,
   * « Effacer tout »). A refused save sets the same `error`, and reading again would not undo it, so a list bound to
   * `error` alone would call a refused save on an empty list a list it could not read.
   */
  readonly readFailed: Signal<boolean> = computed(
    () =>
      (this.errorSignal() !== null && !this.writeRefusedSignal()) ||
      this.filterRefusedSignal() !== false,
  );
  /**
   * Whether the read failed because a filter it asked for is refused (`isFilterRefused`): reading again would meet the
   * same refusal, so a list says to clear its filters rather than « Réessayer ». Such a read sets no `error`: the list
   * says it in place of its rows, and a page's own error line would say it a second time, in a facade's words for a
   * 422, which are a refused save's. It carries the API's parameters refused, from which the list names the filter.
   */
  readonly readRefusedFilter: Signal<false | RefusedFilter> = this.filterRefusedSignal.asReadonly();

  constructor(
    private readonly quietReads: QuietReads,
    private readonly code: (error: unknown) => E | null,
  ) {}

  /** Reads; never throws. False when the read failed or was no longer the latest. */
  async read(load: () => Promise<void>, options: ReadOptions = {}): Promise<boolean> {
    const quiet = this.quietReads.quiet();
    const end = quiet ? () => undefined : this.begin();
    // Untracked: a read started from an effect must not make that effect hang on this flag.
    const keepsRefusal = quiet && untracked(this.writeRefusedSignal);
    if (!keepsRefusal) this.say(null, false);
    try {
      await load();
      return options.current?.() ?? true;
    } catch (error) {
      if (options.current?.() === false) return false;
      if (!keepsRefusal) this.sayRead(error);
      options.failed?.(error);
      return false;
    } finally {
      end();
    }
  }

  /** Writes; never throws. True when the API kept it. */
  async write(call: () => Promise<unknown>, options: WriteOptions<E> = {}): Promise<boolean> {
    return (await this.attempt(call, options)).kept;
  }

  /** Writes, and answers what the API answered, or null when it refused. */
  async writeAnswer<T>(call: () => Promise<T>, options: WriteOptions<E> = {}): Promise<T | null> {
    const outcome = await this.attempt(call, options);
    return outcome.kept ? outcome.answer : null;
  }

  /**
   * A write a form that stays open asked for. A refusal naming fields is handed back for the form to say, and nothing
   * is said on the screen around it, so it is never said twice; any other failure is said as `write` says one.
   */
  async writeOrRefuse<T>(
    call: () => Promise<T>,
    refusalsOf: (error: unknown) => readonly FieldRefusal[],
  ): Promise<FormAnswer<T>> {
    let refused: readonly FieldRefusal[] = [];
    const outcome = await this.attempt(call, {
      code: (error) => {
        refused = refusalsOf(error);
        return refused.length > 0 ? null : this.code(error);
      },
    });
    if (outcome.kept) return { kept: outcome.answer };
    return refused.length > 0 ? { refused } : null;
  }

  /** Marks work busy and lets its failure through untouched, for a facade that keeps its own refusal. */
  async marked<T>(work: () => Promise<T>): Promise<T> {
    const end = this.begin();
    try {
      return await work();
    } finally {
      end();
    }
  }

  /** A failure outside a marked request, such as a picker's search: said, busy left as it is. */
  fail(error: unknown): void {
    this.sayRead(error);
  }

  /**
   * A write's refusal a screen caught itself, around a call it marks on its own: said as `write` says one, so a reload
   * nobody asked for leaves it on the screen.
   */
  refuse(error: unknown): void {
    this.say(this.shown(error, this.code), true);
  }

  clearError(): void {
    this.say(null, false);
  }

  private async attempt<T>(
    call: () => Promise<T>,
    options: WriteOptions<E>,
  ): Promise<{ kept: true; answer: T } | { kept: false }> {
    const end = this.begin();
    this.say(null, false);
    try {
      return { kept: true, answer: await call() };
    } catch (error) {
      this.say(this.shown(error, options.code ?? this.code), true);
      options.failed?.(error);
      return { kept: false };
    } finally {
      end();
    }
  }

  /** A read's failure: a refused filter as itself, with no code; any other as the facade's code. */
  private sayRead(error: unknown): void {
    const refused = refusedFilterIn(error);
    if (refused !== null) this.say(null, false, refused);
    else this.say(this.shown(error, this.code), false);
  }

  private say(
    error: E | null,
    fromWrite: boolean,
    filterRefused: false | RefusedFilter = false,
  ): void {
    this.errorSignal.set(error);
    this.writeRefusedSignal.set(fromWrite && error !== null);
    this.filterRefusedSignal.set(fromWrite ? false : filterRefused);
  }

  /** What the screen says of a failure: nothing when an interceptor already said it. */
  private shown(error: unknown, code: (error: unknown) => E | null): E | null {
    return saidAlready(error) ? null : code(error);
  }

  /** Counts one marked request; what it returns ends it, once. */
  private begin(): () => void {
    this.running++;
    this.busySignal.set(true);
    let ended = false;
    return () => {
      if (ended) return;
      ended = true;
      if (--this.running === 0) this.busySignal.set(false);
    };
  }
}

/** A facade's request state, made where it is injected: `private readonly state = requestState(codeOf);`. */
export function requestState<E>(code: (error: unknown) => E | null): RequestState<E> {
  return new RequestState(inject(QuietReads), code);
}

/**
 * Which of several requests of one kind is the latest: typing sends one search per pause and they need not come back
 * in order, so an earlier one answering late must not put back what the words no longer find.
 */
export class LatestRequest {
  private latest = 0;

  /** A new request; what it returns says whether it is still the latest. */
  next(): () => boolean {
    const mine = ++this.latest;
    return () => mine === this.latest;
  }
}
