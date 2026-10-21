// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { refusedFor } from '../errors/said-already';
import { SUBMISSION_IN_PROGRESS, SUBMISSION_UNANSWERED } from '../submissions/submission-keys';
import { demoAnswer, submissionAnswer } from '../testing/said-answers';
import { QuietReads } from './quiet-reads';
import { LatestRequest, requestState, type RequestState } from './request-state';

class Refused extends Error {
  constructor(readonly code: 'not_found' | 'held') {
    super(code);
  }
}

type Code = 'not_found' | 'network';

const codeOf = (error: unknown): Code =>
  error instanceof Refused && error.code === 'not_found' ? 'not_found' : 'network';

/** A call that answers only when told to, so a test can look at the state while it runs. */
function pending<T>(): {
  promise: Promise<T>;
  answer: (value: T) => void;
  refuse: (error: unknown) => void;
} {
  let answer!: (value: T) => void;
  let refuse!: (error: unknown) => void;
  const promise = new Promise<T>((resolve, reject) => {
    answer = resolve;
    refuse = reject;
  });
  return { promise, answer, refuse };
}

describe('requestState', () => {
  let state: RequestState<Code>;
  let quiet: QuietReads;

  beforeEach(() => {
    TestBed.resetTestingModule();
    quiet = TestBed.inject(QuietReads);
    state = TestBed.runInInjectionContext(() => requestState(codeOf));
  });

  it('marks a read busy while it runs, and says whether it was answered', async () => {
    const call = pending<void>();
    const reading = state.read(() => call.promise);
    expect(state.busy()).toBe(true);
    call.answer();
    expect(await reading).toBe(true);
    expect(state.busy()).toBe(false);
  });

  it('marks no read busy that nobody asked for, and leaves a mark already there as it is', async () => {
    let seen: boolean | null = null;
    await quiet.quietly(() =>
      state.read(async () => {
        seen = state.busy();
      }),
    );
    expect(seen).toBe(false);

    const write = pending<void>();
    const writing = state.write(() => write.promise);
    await quiet.quietly(() => state.read(async () => undefined));
    expect(state.busy()).toBe(true);
    write.answer();
    await writing;
    expect(state.busy()).toBe(false);
  });

  it('always marks a write, even while a quiet reload runs', async () => {
    let seen: boolean | null = null;
    await quiet.quietly(() =>
      state.write(async () => {
        seen = state.busy();
      }),
    );
    expect(seen).toBe(true);
    expect(state.busy()).toBe(false);
  });

  it('stays busy while any marked request runs, whichever ends first', async () => {
    const read = pending<void>();
    const write = pending<void>();
    const reading = state.read(() => read.promise);
    const writing = state.write(() => write.promise);
    read.answer();
    await reading;
    expect(state.busy()).toBe(true);
    write.answer();
    await writing;
    expect(state.busy()).toBe(false);

    const second = pending<void>();
    const secondWrite = state.write(() => second.promise);
    const firstRead = pending<void>();
    const secondRead = state.read(() => firstRead.promise);
    second.answer();
    await secondWrite;
    expect(state.busy()).toBe(true);
    firstRead.answer();
    await secondRead;
    expect(state.busy()).toBe(false);
  });

  it('keeps an error until the next request starts, and maps it to the screen’s code', async () => {
    expect(await state.read(() => Promise.reject(new Refused('not_found')))).toBe(false);
    expect(state.error()).toBe('not_found');
    expect(state.busy()).toBe(false);

    const next = pending<void>();
    const reading = state.read(() => next.promise);
    expect(state.error()).toBeNull();
    next.refuse(new Error('unreachable'));
    await reading;
    expect(state.error()).toBe('network');

    const write = pending<void>();
    const writing = state.write(() => write.promise);
    expect(state.error()).toBeNull();
    write.answer();
    expect(await writing).toBe(true);
    expect(state.error()).toBeNull();
  });

  it('drops the outcome of a read that is no longer the latest, its failure included', async () => {
    const pages = new LatestRequest();
    const first = pages.next();
    const earlier = pending<void>();
    const reading = state.read(() => earlier.promise, { current: first });
    const second = pages.next();
    expect(first()).toBe(false);
    expect(second()).toBe(true);
    earlier.refuse(new Refused('not_found'));
    expect(await reading).toBe(false);
    expect(state.error()).toBeNull();
    expect(state.busy()).toBe(false);
  });

  it('hands the failure to the facade for what it keeps beside the code', async () => {
    const seen: unknown[] = [];
    const refusal = new Refused('not_found');
    await state.read(() => Promise.reject(refusal), { failed: (error) => seen.push(error) });
    expect(
      await state.write(() => Promise.reject(refusal), { failed: (error) => seen.push(error) }),
    ).toBe(false);
    expect(seen).toEqual([refusal, refusal]);
  });

  it('lets one write say its own code, and a null code shows nothing', async () => {
    const held = new Refused('held');
    await state.write(() => Promise.reject(held), {
      code: (error) => (error instanceof Refused && error.code === 'held' ? null : codeOf(error)),
    });
    expect(state.error()).toBeNull();

    const nothing = TestBed.runInInjectionContext(() => requestState<Code>(() => null));
    await nothing.read(() => Promise.reject(new Error('refused')));
    expect(nothing.error()).toBeNull();
  });

  it.each([
    ['the demo refused', demoAnswer],
    ['a write is still being made', () => submissionAnswer(SUBMISSION_IN_PROGRESS)],
    ['a write was made and its answer lost', () => submissionAnswer(SUBMISSION_UNANSWERED)],
  ])(
    'shows no error of its own where an interceptor has said %s, read, written or failed, under a refusal or bare',
    async (_said, answer) => {
      const failed: unknown[] = [];
      for (const refusal of [refusedFor(answer(), new Refused('not_found')), answer()]) {
        state.fail(new Refused('not_found'));
        await state.read(() => Promise.reject(refusal), { failed: (error) => failed.push(error) });
        expect(state.error()).toBeNull();

        state.fail(new Refused('not_found'));
        expect(
          await state.write(() => Promise.reject(refusal), {
            failed: (error) => failed.push(error),
          }),
        ).toBe(false);
        expect(state.error()).toBeNull();
        expect(state.busy()).toBe(false);

        state.fail(new Refused('not_found'));
        await state.writeAnswer(() => Promise.reject(refusal), { code: () => 'not_found' });
        expect(state.error()).toBeNull();

        state.fail(new Refused('not_found'));
        state.fail(refusal);
        expect(state.error()).toBeNull();
      }
      // The facade's own bookkeeping still runs: only what the screen says is left out.
      expect(failed).toHaveLength(4);
    },
  );

  it('answers what a write answered, or null when it was refused', async () => {
    expect(await state.writeAnswer(() => Promise.resolve({ id: 'a1' }))).toEqual({ id: 'a1' });
    expect(await state.writeAnswer(() => Promise.reject(new Refused('not_found')))).toBeNull();
    expect(state.error()).toBe('not_found');
  });

  it('says a failure outside a request without marking busy, and forgets it on demand', () => {
    state.fail(new Refused('not_found'));
    expect(state.error()).toBe('not_found');
    expect(state.busy()).toBe(false);
    state.clearError();
    expect(state.error()).toBeNull();
  });

  it('marks work busy and lets its failure through untouched', async () => {
    const refusal = new Refused('not_found');
    const work = pending<string>();
    const marking = state.marked(() => work.promise);
    expect(state.busy()).toBe(true);
    work.refuse(refusal);
    await expect(marking).rejects.toBe(refusal);
    expect(state.busy()).toBe(false);
    expect(state.error()).toBeNull();
    expect(await state.marked(async () => 'kept')).toBe('kept');
  });
});
