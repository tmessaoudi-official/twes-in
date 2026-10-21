// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { SUBMISSION_IN_PROGRESS, SUBMISSION_UNANSWERED } from '../submissions/submission-keys';
import { demoAnswer, submissionAnswer } from '../testing/said-answers';
import { RECORD_CHANGED } from './record-changed';
import { refusedFor, saidAlready } from './said-already';

class Refused extends Error {}

describe('saidAlready', () => {
  it('reads the answers an interceptor says, bare or kept under a refusal', () => {
    for (const answer of [
      demoAnswer(),
      submissionAnswer(SUBMISSION_IN_PROGRESS),
      submissionAnswer(SUBMISSION_UNANSWERED),
    ]) {
      expect(saidAlready(answer)).toBe(true);
      expect(saidAlready(refusedFor(answer, new Refused('invalid')))).toBe(true);
      expect(saidAlready(refusedFor(refusedFor(answer, new Refused('a')), new Refused('b')))).toBe(
        true,
      );
    }
  });

  it('leaves every other failure to the screen, a refusal without its answer among them', () => {
    const changed = new HttpErrorResponse({ status: 409, error: { type: RECORD_CHANGED } });
    const refused = new HttpErrorResponse({ status: 403, error: { error: 'support_read_only' } });

    expect(saidAlready(changed)).toBe(false);
    expect(saidAlready(refusedFor(changed, new Refused('record_changed')))).toBe(false);
    expect(saidAlready(refused)).toBe(false);
    expect(saidAlready(new Refused('invalid'))).toBe(false);
    expect(saidAlready(new HttpErrorResponse({ status: 0 }))).toBe(false);
    expect(saidAlready(null)).toBe(false);
    expect(saidAlready('demo_refused')).toBe(false);
  });

  it('stops on a refusal built from itself', () => {
    const looped = new Refused('invalid');

    expect(saidAlready(refusedFor(looped, looped))).toBe(false);
  });

  it('hands the same refusal back, unchanged as anything else sees it', () => {
    const refusal = new Refused('invalid');

    expect(refusedFor(demoAnswer(), refusal)).toBe(refusal);
    expect(refusal).toEqual(new Refused('invalid'));
    expect(refusal.cause).toBeUndefined();
  });
});
