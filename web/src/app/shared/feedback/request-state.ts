// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, type Signal, signal } from '@angular/core';
import { saidAlready } from '../errors/said-already';
import { QuietReads } from './quiet-reads';

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
 * is always marked. An error is cleared when the next request starts, and kept until then. A failure an interceptor
 * has already said in words (`saidAlready`: the demo's refusal, a write still being made or whose answer was lost)
 * sets no error, so the screen never says it a second time, nor something else; `failed` still runs.
 */
export class RequestState<E> {
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<E | null>(null);
  private running = 0;

  readonly busy: Signal<boolean> = this.busySignal.asReadonly();
  readonly error: Signal<E | null> = this.errorSignal.asReadonly();

  constructor(
    private readonly quietReads: QuietReads,
    private readonly code: (error: unknown) => E | null,
  ) {}

  /** Reads; never throws. False when the read failed or was no longer the latest. */
  async read(load: () => Promise<void>, options: ReadOptions = {}): Promise<boolean> {
    const end = this.quietReads.quiet() ? () => undefined : this.begin();
    this.errorSignal.set(null);
    try {
      await load();
      return options.current?.() ?? true;
    } catch (error) {
      if (options.current?.() === false) return false;
      this.errorSignal.set(this.shown(error, this.code));
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
    this.errorSignal.set(this.shown(error, this.code));
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async attempt<T>(
    call: () => Promise<T>,
    options: WriteOptions<E>,
  ): Promise<{ kept: true; answer: T } | { kept: false }> {
    const end = this.begin();
    this.errorSignal.set(null);
    try {
      return { kept: true, answer: await call() };
    } catch (error) {
      this.errorSignal.set(this.shown(error, options.code ?? this.code));
      options.failed?.(error);
      return { kept: false };
    } finally {
      end();
    }
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
