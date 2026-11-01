// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { TranslateService } from '@ngx-translate/core';
import { appConfig } from './app.config';

// The texts are fetched through HttpClient, so through every interceptor, while the texts service is still being built:
// an interceptor that builds, on its way, anything needing the texts service (or HttpClient) closes a cycle, and the
// whole application stops at start on NG0200 with every text a key. ngx-translate only warns of it, and unit specs give
// each interceptor its own providers, so neither sees it; this one builds the application's own and asks that the texts
// request leaves.
describe('the application configuration', () => {
  it('sends the texts request through the whole interceptor chain without a cycle', () => {
    TestBed.configureTestingModule({
      providers: [...appConfig.providers, provideHttpClientTesting()],
    });

    TestBed.inject(TranslateService);

    const sent = TestBed.inject(HttpTestingController)
      .match(() => true)
      .map((request) => request.request.url);
    expect(sent.some((url) => url.includes('/i18n/'))).toBe(true);
  });
});
