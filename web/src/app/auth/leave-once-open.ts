// SPDX-License-Identifier: AGPL-3.0-or-later

import { DestroyRef, DOCUMENT, effect, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AuthFacade } from './auth-facade';

/**
 * How often a page outside the shell asks again whether its company has opened. It hears no live change: the
 * connection gives a company that is not active no channel, and an operator's approval or a confirmed payment is
 * decided elsewhere.
 */
const ASK_AGAIN_EVERY_MS = 30_000;

/**
 * For a page a member of a shut company waits on: the session is read again while the page is open and whenever the
 * window comes back in front, and the application opens as soon as the company does, at `into`: the page the shell
 * shows for what was waited on, so an outcome said here is still read there. Called in a constructor.
 */
export function leaveOnceOpen(into = '/'): void {
  const auth = inject(AuthFacade);
  const router = inject(Router);
  const document = inject(DOCUMENT);
  effect(() => {
    if (auth.me() !== null && !auth.companyClosed()) void router.navigateByUrl(into);
  });
  const askAgain = () => {
    if (document.visibilityState !== 'hidden') void auth.refresh();
  };
  document.addEventListener('visibilitychange', askAgain);
  const timer = setInterval(askAgain, ASK_AGAIN_EVERY_MS);
  inject(DestroyRef).onDestroy(() => {
    document.removeEventListener('visibilitychange', askAgain);
    clearInterval(timer);
  });
}
