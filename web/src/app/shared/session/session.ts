// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Signal } from '@angular/core';

/** Who is signed in and in which company, as far as shared code needs to know. */
export interface SessionState {
  readonly user: { readonly id: string };
  readonly company: {
    readonly id: string;
    readonly countryCode: string;
    readonly currency: string;
  } | null;
}

/**
 * The port shared code reads the session through, so shared/ depends on no feature (docs/SPEC.md § 7, 2026-09-16).
 * The auth feature's facade answers it (app.config.ts); a test provides its own.
 */
export abstract class Session {
  /** null while nobody is signed in or before the API has answered */
  abstract readonly me: Signal<SessionState | null>;
}
