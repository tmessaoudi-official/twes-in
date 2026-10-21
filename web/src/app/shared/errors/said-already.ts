// SPDX-License-Identifier: AGPL-3.0-or-later

import { isDemoRefused } from '../demo/demo-refused-interceptor';
import { submissionProblemOf } from '../submissions/submission-interceptor';

/**
 * The API's answer each feature refusal was built from. Kept beside the refusal rather than as its `Error.cause`: a
 * cause becomes part of the error as everything else sees it (a spec's equality, a log, the client error report, which
 * would then carry an answer's body), while this link serves `saidAlready` alone.
 */
const answers = new WeakMap<Error, unknown>();

/**
 * A feature's refusal built from what the API answered, which it keeps: the code the screen translates is the
 * feature's, while `saidAlready` can still read the answer underneath it.
 */
export function refusedFor<R extends Error>(answer: unknown, refusal: R): R {
  answers.set(refusal, answer);
  return refusal;
}

/**
 * Whether an interceptor has already said this failure in words, wherever it was asked from: the demo's refusal, and a
 * write still being made or made with its answer lost. A screen then shows no error of its own, which would say it a
 * second time or, worse, say something else (a 409 read as « someone changed this »). Read through the answers a
 * refusal was built from.
 */
export function saidAlready(error: unknown): boolean {
  let seen: unknown = error;
  for (let depth = 0; depth < 4 && seen !== undefined && seen !== null; depth++) {
    if (isDemoRefused(seen) || submissionProblemOf(seen) !== null) return true;
    seen = seen instanceof Error ? answers.get(seen) : undefined;
  }
  return false;
}
