// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, EnvironmentInjector, runInInjectionContext } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { trackNavigation } from './navigation-activity';
import { RequestActivity } from './request-activity';

@Component({ template: '' })
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
});
