// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SupportAccessApi, SupportAccessRefused } from './support-access-api';

/** A value sent refused, as API Platform writes the violation on its field. */
function violation(propertyPath: string, reason: string): object {
  return { violations: [{ propertyPath, message: reason, payload: { reason } }] };
}

describe('SupportAccessApi', () => {
  let api: SupportAccessApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(SupportAccessApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('opens the company to support for the hours chosen, with what is wrong', async () => {
    const opened = api.grant('c1', 24, 'La facture du 3 mars', null);
    const request = http.expectOne('/api/companies/c1/support-access');
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      hours: 24,
      reason: 'La facture du 3 mars',
      operator: null,
    });
    request.flush({ startsAt: 'a', endsAt: 'b', hours: [1, 24, 72], defaultHours: 24 });
    expect((await opened).endsAt).toBe('b');
  });

  it('opens it to the one operator the owner names', async () => {
    const opened = api.grant('c1', 1, null, 'op-2');
    const request = http.expectOne('/api/companies/c1/support-access');
    expect(request.request.body).toEqual({ hours: 1, reason: null, operator: 'op-2' });
    request.flush({ endsAt: 'b', operator: { id: 'op-2', name: 'Sami' } });
    expect((await opened).operator).toEqual({ id: 'op-2', name: 'Sami' });
  });

  // docs/SPEC.md § 7, the step-up list: opening the company to support waits for the owner's recent proof.
  it('names each refusal the page translates, the proof the API waits for among them', async () => {
    for (const code of ['owner_only', 'step_up_required'] as const) {
      const refused = api.grant('c1', 24, null, null);
      http
        .expectOne('/api/companies/c1/support-access')
        .flush({ error: code }, { status: 403, statusText: 'No' });
      await expect(refused).rejects.toEqual(new SupportAccessRefused(code));
    }

    // The length, what is wrong and whom it opens to are what was sent: a refusal of each is a violation on its field.
    for (const [field, code] of [
      ['hours', 'support_window'],
      ['reason', 'support_reason'],
      ['operator', 'support_operator'],
    ] as const) {
      const refused = api.grant('c1', 24, null, null);
      http
        .expectOne('/api/companies/c1/support-access')
        .flush(violation(field, code), { status: 422, statusText: 'No' });
      await expect(refused).rejects.toEqual(new SupportAccessRefused(code));
    }

    const gone = api.read('c1');
    http
      .expectOne('/api/companies/c1/support-access')
      .flush(null, { status: 404, statusText: 'Not Found' });
    await expect(gone).rejects.toEqual(new SupportAccessRefused('not_found'));
  });
});
