// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DataErasureApi } from './data-erasure/data-erasure-api';
import { StopMailApi } from './notifications/stop-mail-api';
import { RecordRemovalsApi } from './shared/records/record-removals-api';
import { SettingsApi } from './shared/settings/settings-api';
import { TextsParts } from './shared/i18n/texts-part';
import { SupportAccessApi } from './support-access/support-access-api';

/**
 * An answer the API gave that a reader has no words for (a code added later, a status nobody expected) is never said
 * as a lost connection: the reader keeps the code it named, `{ unknown }`, which every screen says in one sentence,
 * `feedback.unknown_refusal` (`shared/errors/unknown-refusal-api.ts`). Only no answer, or a gateway answering for an
 * API that is restarting, is the network.
 */

interface Reader {
  readonly name: string;
  readonly call: () => Promise<unknown>;
  readonly url: string;
}

const READERS: readonly Reader[] = [
  {
    name: 'the erasure',
    call: () => TestBed.inject(DataErasureApi).preview('c1'),
    url: '/api/companies/c1/data-erasure',
  },
  {
    name: "support's access",
    call: () => TestBed.inject(SupportAccessApi).read('c1'),
    url: '/api/companies/c1/support-access',
  },
  {
    name: 'the bin',
    call: () => TestBed.inject(RecordRemovalsApi).restore('c1', 'r1'),
    url: '/api/companies/c1/record-removals/r1/restore',
  },
  {
    name: 'the settings',
    call: () => TestBed.inject(SettingsApi).change('c1', 'presentation.density', 'user', 'compact'),
    url: '/api/companies/c1/settings/presentation.density',
  },
  {
    name: "a mail's stop link",
    call: () => TestBed.inject(StopMailApi).stop('t1'),
    url: '/api/notification-preferences/stop',
  },
];

describe('an answer a reader does not know', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TextsParts, useValue: { open: () => Promise.resolve() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it.each(READERS)('from $name keeps the code it named', async ({ call, url }) => {
    const asked = call();
    http.expectOne(url).flush({ error: 'brand_new' }, { status: 403, statusText: 'Forbidden' });
    await expect(asked).rejects.toMatchObject({ code: { unknown: 'brand_new' } });
  });

  it.each(READERS)('from $name naming nothing keeps its status', async ({ call, url }) => {
    const asked = call();
    http.expectOne(url).flush(null, { status: 500, statusText: 'Server Error' });
    await expect(asked).rejects.toMatchObject({ code: { unknown: '500' } });
  });

  it.each(READERS)(
    'from $name through a gateway while the API restarts is the network',
    async ({ call, url }) => {
      const asked = call();
      http.expectOne(url).flush(null, { status: 503, statusText: 'Service Unavailable' });
      await expect(asked).rejects.toMatchObject({ code: 'network' });
    },
  );
});
