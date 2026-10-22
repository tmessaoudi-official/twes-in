// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, inject } from '@angular/core';
import {
  NavigationCancel,
  NavigationEnd,
  NavigationError,
  NavigationStart,
  Router,
} from '@angular/router';
import { Feedback } from './feedback';
import { RequestActivity } from './request-activity';

/**
 * Counts a navigation as activity, from its start to its end however it ends: a lazy page and its guards load through
 * the router, not through HttpClient, and a person waits for them all the same. A navigation that fails is said: a page
 * whose code could not be fetched (the network dropped, or an update replaced the files an open tab asks for) leaves
 * the person where they were, which reads as a click that did nothing. Call once, from an app initializer.
 */
export function trackNavigation(): void {
  const activity = inject(RequestActivity);
  const feedback = inject(Feedback);
  let done: (() => void) | null = null;
  const subscription = inject(Router).events.subscribe((event) => {
    if (event instanceof NavigationStart) {
      done?.();
      done = activity.started();
    } else if (
      event instanceof NavigationEnd ||
      event instanceof NavigationCancel ||
      event instanceof NavigationError
    ) {
      done?.();
      done = null;
      if (event instanceof NavigationError) feedback.failure('feedback.page_failed');
    }
  });
  inject(DestroyRef).onDestroy(() => subscription.unsubscribe());
}
