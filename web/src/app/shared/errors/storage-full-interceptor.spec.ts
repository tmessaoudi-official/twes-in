// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { TextsParts } from '../i18n/texts-part';
import { failureToasts, provideQuietFeedback } from '../testing/feedback';
import { saidAlready } from './said-already';
import { STORAGE_FULL, storageFullInterceptor } from './storage-full-interceptor';

const INVOICES = '/api/companies/a/invoices';
const FULL_BODY = {
  type: STORAGE_FULL,
  title: 'Storage full',
  status: 507,
  detail: 'Tell whoever looks after it.',
};

describe('storageFullInterceptor', () => {
  let opened: string[];

  beforeEach(() => {
    opened = [];
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([storageFullInterceptor])),
        provideHttpClientTesting(),
        provideQuietFeedback(),
        {
          provide: TextsParts,
          useValue: { open: (name: string) => (opened.push(name), Promise.resolve()) },
        },
      ],
    });
  });

  function send(): { failure: unknown } {
    const seen: { failure: unknown } = { failure: null };
    TestBed.inject(HttpClient)
      .post(INVOICES, {})
      .subscribe({ error: (error: unknown) => (seen.failure = error) });
    return seen;
  }

  it('says a full disk in words from its own texts, kept until closed, and the screen says nothing more', async () => {
    const seen = send();
    TestBed.inject(HttpTestingController)
      .expectOne(INVOICES)
      .flush(FULL_BODY, { status: 507, statusText: 'Insufficient Storage' });
    await Promise.resolve();
    await Promise.resolve();

    expect(opened).toEqual(['storage-full']);
    expect(failureToasts()).toEqual(['storage_full.said']);
    expect(saidAlready(seen.failure)).toBe(true);
  });

  it('leaves any other server error to the screen', async () => {
    const seen = send();
    TestBed.inject(HttpTestingController)
      .expectOne(INVOICES)
      .flush(
        { type: '/problems/other', status: 507 },
        { status: 507, statusText: 'Insufficient Storage' },
      );
    await Promise.resolve();

    expect(opened).toEqual([]);
    expect(failureToasts()).toEqual([]);
    expect(saidAlready(seen.failure)).toBe(false);
  });
});
