// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { WEB_PARAMETERS_UNTIL_TOLD, WebParameters } from './web-parameters';

describe('WebParameters', () => {
  it('holds to the shipped values until the API said the operator’s', () => {
    const parameters = TestBed.inject(WebParameters);

    expect(WEB_PARAMETERS_UNTIL_TOLD).toEqual({
      errorReportsPerPage: 5,
      submissionRetryMinutes: 15,
    });
    expect(parameters.errorReportsPerPage()).toBe(5);
    expect(parameters.submissionRetryMs()).toBe(15 * 60 * 1000);
  });

  it('takes what the API last said', () => {
    const parameters = TestBed.inject(WebParameters);

    parameters.tell({ errorReportsPerPage: 0, submissionRetryMinutes: 2 });
    parameters.tell({ errorReportsPerPage: 12, submissionRetryMinutes: 30 });

    expect(parameters.errorReportsPerPage()).toBe(12);
    expect(parameters.submissionRetryMs()).toBe(30 * 60 * 1000);
  });
});
