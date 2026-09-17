// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, HttpContext, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { activityInterceptor, SILENT } from './activity-interceptor';
import { RequestActivity } from './request-activity';

describe('activityInterceptor', () => {
  const activity = {
    started: vi.fn(),
    reached: vi.fn(),
    failed: vi.fn(),
    quiet: vi.fn(() => false),
  };
  const done = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
    activity.started.mockReturnValue(done);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([activityInterceptor])),
        provideHttpClientTesting(),
        { provide: RequestActivity, useValue: activity },
      ],
    });
  });

  const http = () => TestBed.inject(HttpClient);
  const backend = () => TestBed.inject(HttpTestingController);

  it('tracks an API request from start to answer', () => {
    http().get('/api/customers').subscribe();
    expect(activity.started).toHaveBeenCalledOnce();
    expect(done).not.toHaveBeenCalled();

    backend().expectOne('/api/customers').flush([]);
    expect(activity.reached).toHaveBeenCalledOnce();
    expect(done).toHaveBeenCalledOnce();
  });

  it('reports a failure with its status and address, and still ends the request', () => {
    http()
      .get('/api/customers')
      .subscribe({ error: () => undefined });
    backend()
      .expectOne('/api/customers')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });

    expect(activity.failed).toHaveBeenCalledWith(0, '/api/customers');
    expect(done).toHaveBeenCalledOnce();
  });

  it('counts a refusal the API answered as proof it is there', () => {
    http()
      .post('/api/customers', {})
      .subscribe({ error: () => undefined });
    backend().expectOne('/api/customers').flush({}, { status: 422, statusText: 'Unprocessable' });

    expect(activity.failed).toHaveBeenCalledWith(422, '/api/customers');
  });

  it('leaves a silent request out of the activity shown, while still watching its failures', () => {
    http()
      .put('/api/settings/x', {}, { context: new HttpContext().set(SILENT, true) })
      .subscribe({ error: () => undefined });
    expect(activity.started).not.toHaveBeenCalled();
    backend()
      .expectOne('/api/settings/x')
      .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });
    expect(activity.failed).toHaveBeenCalledWith(0, '/api/settings/x');
  });

  it('leaves out a request started while a quiet reload runs', () => {
    activity.quiet.mockReturnValueOnce(true);
    http().get('/api/customers').subscribe();
    backend().expectOne('/api/customers').flush([]);
    expect(activity.started).not.toHaveBeenCalled();
    expect(activity.reached).toHaveBeenCalledOnce();
  });

  it('ignores what is not the API, such as the translation files', () => {
    http().get('/i18n/fr.json').subscribe();
    backend().expectOne('/i18n/fr.json').flush({});
    expect(activity.started).not.toHaveBeenCalled();
    expect(activity.reached).not.toHaveBeenCalled();
  });
});
