// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, EnvironmentInjector, runInInjectionContext } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { failureToasts, provideQuietFeedback } from '../testing/feedback';
import { trackNavigation } from './navigation-activity';
import { RequestActivity } from './request-activity';

@Component({ selector: 'app-navigation-activity-blank', template: '' })
class Blank {}

describe('trackNavigation', () => {
  it('counts a navigation from its start to its end, and a refused one ends too', async () => {
    const done = vi.fn();
    const started = vi.fn(() => done);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'a', component: Blank },
          { path: 'closed', canActivate: [() => false], component: Blank },
        ]),
        provideQuietFeedback(),
        { provide: RequestActivity, useValue: { started } },
      ],
    });
    runInInjectionContext(TestBed.inject(EnvironmentInjector), trackNavigation);
    const router = TestBed.inject(Router);

    await router.navigateByUrl('/a');
    expect(started).toHaveBeenCalledOnce();
    expect(done).toHaveBeenCalledOnce();

    await router.navigateByUrl('/closed');
    expect(started).toHaveBeenCalledTimes(2);
    expect(done).toHaveBeenCalledTimes(2);
  });

  // A page whose code cannot be fetched (the network dropped, or an update replaced the files an open tab asks for)
  // used to leave the person where they were with nothing said.
  it('says a page that could not open, and keeps the person where they were', async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'a', component: Blank },
          {
            path: 'lost',
            loadComponent: () =>
              Promise.reject(new TypeError('Failed to fetch dynamically imported module')),
          },
        ]),
        provideQuietFeedback(),
        { provide: RequestActivity, useValue: { started: () => () => undefined } },
      ],
    });
    runInInjectionContext(TestBed.inject(EnvironmentInjector), trackNavigation);
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/a');

    await router.navigateByUrl('/lost').catch(() => false);

    expect(failureToasts()).toEqual(['feedback.page_failed']);
    expect(router.url).toBe('/a');
  });
});
