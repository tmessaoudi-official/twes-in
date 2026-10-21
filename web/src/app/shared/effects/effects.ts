// SPDX-License-Identifier: AGPL-3.0-or-later

import { InjectionToken } from '@angular/core';

/** The header in which the API says what a request did beyond its subject (`EffectsAnswer` on the API). */
export const EFFECTS_HEADER = 'X-Effects';

/**
 * Something a request did beyond what it was asked (a receipt that moved a price), as its answer says it. Past what a
 * header may carry the API folds them to one per kind with `count`, and no subject or figures.
 */
export interface SaidEffect {
  readonly kind: string;
  readonly id: string | null;
  readonly params: Readonly<Record<string, string | number | boolean | null>>;
  readonly count?: number;
}

/**
 * Says the effects of its kinds to the person whose request did them, whichever page sent it. `shared/` imports no
 * feature, so each feature provides one for its own kinds (`EFFECT_SAYERS`, multi); an effect of a kind nobody says is
 * reported as an error, since the API said it for someone to hear.
 */
export interface EffectSayer {
  readonly kinds: readonly string[];
  say(effects: readonly SaidEffect[]): void | Promise<void>;
}

export const EFFECT_SAYERS = new InjectionToken<readonly EffectSayer[]>('EFFECT_SAYERS', {
  providedIn: 'root',
  factory: () => [],
});

/** The effects a header names, or the error that it names none the API could have written. */
export function saidEffects(header: string): SaidEffect[] {
  const parsed: unknown = JSON.parse(header);
  if (!Array.isArray(parsed) || !parsed.every(isEffect)) {
    throw new Error(`${EFFECTS_HEADER} is not a list of effects: ${header}`);
  }
  return parsed;
}

function isEffect(value: unknown): value is SaidEffect {
  if (typeof value !== 'object' || value === null) return false;
  const effect = value as Record<string, unknown>;
  return (
    typeof effect['kind'] === 'string' &&
    (effect['id'] === null || typeof effect['id'] === 'string') &&
    typeof effect['params'] === 'object' &&
    effect['params'] !== null &&
    (effect['count'] === undefined || typeof effect['count'] === 'number')
  );
}
