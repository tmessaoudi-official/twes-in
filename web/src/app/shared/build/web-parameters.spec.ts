// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { WEB_PARAMETERS_UNTIL_TOLD, WebParameters } from './web-parameters';

describe('WebParameters', () => {
  it('holds to the shipped values until the API said the operator’s', () => {
    const parameters = TestBed.inject(WebParameters);

    expect(WEB_PARAMETERS_UNTIL_TOLD).toEqual({
      errorReportsPerPage: 5,
      submissionRetryMinutes: 15,
      passwordResetSeconds: 3600,
      signupSeconds: 86400,
      stepUpSeconds: 300,
      recordsUndoableSeconds: 86400,
      erasureUndoableSeconds: 86400,
    });
    expect(parameters.errorReportsPerPage()).toBe(5);
    expect(parameters.submissionRetryMs()).toBe(15 * 60 * 1000);
    expect(parameters.passwordResetSeconds()).toBe(3600);
  });

  it('takes what the API last said', () => {
    const parameters = TestBed.inject(WebParameters);

    parameters.tell({
      ...WEB_PARAMETERS_UNTIL_TOLD,
      errorReportsPerPage: 0,
      submissionRetryMinutes: 2,
    });
    parameters.tell({
      errorReportsPerPage: 12,
      submissionRetryMinutes: 30,
      passwordResetSeconds: 900,
      signupSeconds: 7200,
      stepUpSeconds: 90,
      recordsUndoableSeconds: 172800,
      erasureUndoableSeconds: 3600,
    });

    expect(parameters.errorReportsPerPage()).toBe(12);
    expect(parameters.submissionRetryMs()).toBe(30 * 60 * 1000);
    // The durations the screens state are the operator's, never the shipped hour.
    expect(parameters.passwordResetSeconds()).toBe(900);
    expect(parameters.signupSeconds()).toBe(7200);
    expect(parameters.stepUpSeconds()).toBe(90);
    expect(parameters.recordsUndoableSeconds()).toBe(172800);
    expect(parameters.erasureUndoableSeconds()).toBe(3600);
  });
});
