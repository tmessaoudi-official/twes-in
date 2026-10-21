// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  HttpErrorResponse,
  type HttpHeaders,
  type HttpInterceptorFn,
  HttpResponse,
} from '@angular/common/http';
import { ErrorHandler, inject } from '@angular/core';
import { tap } from 'rxjs';
import { RequestActivity } from '../feedback/request-activity';
import { EFFECT_SAYERS, EFFECTS_HEADER, type SaidEffect, saidEffects } from './effects';

/**
 * Hands what a request did beyond its subject to whoever says it, so the person hears it at once whichever page sent
 * the request: no facade has to remember to ask. A refused answer may name effects too, when the request failed after
 * a unit of work had committed. They are said once the requests the answer set off have answered too (the page reading
 * itself again), so the page's own outcome comes first and does not take their place on screen, one toast showing at a
 * time. A sayer's failure is reported, never the request's.
 */
export const effectsInterceptor: HttpInterceptorFn = (request, next) => {
  const sayers = inject(EFFECT_SAYERS);
  const errors = inject(ErrorHandler);
  const activity = inject(RequestActivity);
  const say = (effects: readonly SaidEffect[]): void => {
    for (const sayer of sayers) {
      const own = effects.filter((effect) => sayer.kinds.includes(effect.kind));
      if (own.length > 0)
        void Promise.resolve(sayer.say(own)).catch((error: unknown) => errors.handleError(error));
    }
    const unsaid = effects.filter(
      (effect) => !sayers.some((sayer) => sayer.kinds.includes(effect.kind)),
    );
    if (unsaid.length > 0) {
      errors.handleError(
        new Error(`No screen says ${unsaid.map((effect) => effect.kind).join(', ')}`),
      );
    }
  };
  const hear = (headers: HttpHeaders): void => {
    const header = headers.get(EFFECTS_HEADER);
    if (header === null) return;
    try {
      const effects = saidEffects(header);
      if (effects.length > 0) void activity.settled().then(() => say(effects));
    } catch (error) {
      errors.handleError(error);
    }
  };
  return next(request).pipe(
    tap({
      next: (event) => {
        if (event instanceof HttpResponse) hear(event.headers);
      },
      error: (error: unknown) => {
        if (error instanceof HttpErrorResponse) hear(error.headers);
      },
    }),
  );
};
