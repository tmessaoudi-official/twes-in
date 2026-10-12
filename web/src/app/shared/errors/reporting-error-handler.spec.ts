// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { DOCUMENT, ErrorHandler, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { BuildInfo } from '../build/build-info';
import {
  MAX_REPORTS_PER_PAGE,
  REPORT_CLIENT_ERROR,
  ReportingErrorHandler,
} from './reporting-error-handler';

// An error in the browser reaches the error tracker through the API (docs/START.md § 15), a few per page at most.
describe('ReportingErrorHandler', () => {
  const report = vi.fn<(body: unknown) => Promise<void>>();
  let handler: ErrorHandler;

  async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) await Promise.resolve();
  }

  beforeEach(() => {
    report.mockReset().mockResolvedValue(undefined);
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    TestBed.configureTestingModule({
      providers: [
        { provide: ErrorHandler, useClass: ReportingErrorHandler },
        { provide: REPORT_CLIENT_ERROR, useValue: report },
        {
          provide: BuildInfo,
          useValue: { web: signal({ version: '2026.10.12.3', commit: 'abc1234' }) },
        },
      ],
    });
    handler = TestBed.inject(ErrorHandler);
  });

  afterEach(() => vi.restoreAllMocks());

  it('reports what broke, where and in which build, and still writes it to the console', async () => {
    const error = new TypeError('cannot read "total"');

    handler.handleError(error);
    await settle();

    expect(report).toHaveBeenCalledExactlyOnceWith({
      message: 'TypeError: cannot read "total"',
      stack: error.stack ?? '',
      page: TestBed.inject(DOCUMENT).location.pathname,
      build: '2026.10.12.3',
    });
    expect(console.error).toHaveBeenCalled();
  });

  it('reports something thrown that is not an Error as its text', async () => {
    handler.handleError('a plain string');
    await settle();

    expect(report).toHaveBeenCalledWith(
      expect.objectContaining({ message: 'a plain string', stack: '' }),
    );
  });

  it(`stops after ${MAX_REPORTS_PER_PAGE} reports on one page`, async () => {
    for (let i = 0; i < MAX_REPORTS_PER_PAGE + 3; i++) handler.handleError(new Error(`n${i}`));
    await settle();

    expect(report).toHaveBeenCalledTimes(MAX_REPORTS_PER_PAGE);
  });

  it('leaves an answer from the API to the API, which already knows it', async () => {
    handler.handleError(new HttpErrorResponse({ status: 500, url: '/api/invoices' }));
    await settle();

    expect(report).not.toHaveBeenCalled();
  });

  it('lets a report that could not be sent go, raising nothing more', async () => {
    report.mockRejectedValue(new Error('offline'));

    expect(() => handler.handleError(new Error('first'))).not.toThrow();
    await settle();

    expect(report).toHaveBeenCalledTimes(1);
  });
});
