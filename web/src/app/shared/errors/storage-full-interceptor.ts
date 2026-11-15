// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse, type HttpInterceptorFn } from '@angular/common/http';
import { inject, Injector } from '@angular/core';
import { tap } from 'rxjs';
import { Feedback } from '../feedback/feedback';
import { TextsParts } from '../i18n/texts-part';

/** The API's answer when the installation's disk had no room left for a write, the database's or the files'. */
export const STORAGE_FULL = '/problems/storage-full';

/** Its words, opened into the root when the answer first arrives: a full disk is rare, and the root's texts are near their budget. */
export const STORAGE_FULL_TEXTS = 'storage-full';

/**
 * The installation's disk is full: nothing the request did was kept, and only the people looking after the
 * installation can make room, so no screen can say it better than this, wherever the write came from. Said in words,
 * kept until closed, and `saidAlready` tells the screen not to say it again as its own refusal or a lost connection.
 * The texts are asked for through the injector once one arrives, never when the interceptor is built: their own request
 * passes through here (NG0200, app-config.spec.ts).
 */
export const storageFullInterceptor: HttpInterceptorFn = (request, next) => {
  const feedback = inject(Feedback);
  const injector = inject(Injector);
  return next(request).pipe(
    tap({
      error: (error: unknown) => {
        if (!isStorageFull(error)) return;
        void injector
          .get(TextsParts)
          .open(STORAGE_FULL_TEXTS)
          .finally(() => feedback.failure('storage_full.said'));
      },
    }),
  );
};

/**
 * Whether an error is the API's answer that the installation's disk is full. A file asked for as bytes (a download)
 * brings its answer as bytes too, unread here: its 507, a status the API gives nothing else, says it alone.
 */
export function isStorageFull(error: unknown): boolean {
  if (!(error instanceof HttpErrorResponse) || error.status !== 507) return false;
  const body: unknown = error.error;
  if (body instanceof Blob) return true;
  return typeof body === 'object' && body !== null && 'type' in body && body.type === STORAGE_FULL;
}
