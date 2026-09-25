// SPDX-License-Identifier: AGPL-3.0-or-later

import type { StatusTone } from './accent-theme';

/**
 * Where a record stands in its life, whatever the module (design direction § 1.1, docs/SPEC.md § 7, 2026-09-24
 * 22:51). A module places each of its statuses on a stage and never picks a tone, so the same stage reads alike
 * everywhere: a cancelled invoice and a cancelled delivery note are both withdrawn, grey and struck.
 */
export type LifecycleStage =
  'not-started' | 'under-way' | 'needs-action' | 'done' | 'withdrawn' | 'alarm' | 'linked';

export const LIFECYCLE_TONES: Readonly<Record<LifecycleStage, StatusTone>> = {
  'not-started': 'neutral',
  'under-way': 'info',
  'needs-action': 'warning',
  done: 'success',
  withdrawn: 'neutral',
  alarm: 'danger',
  linked: 'purple',
};

/** A module's status tones, derived from the stages it places its statuses on. */
export function tonesOf<S extends string>(
  stages: Readonly<Record<S, LifecycleStage>>,
): Readonly<Record<S, StatusTone>> {
  return Object.fromEntries(
    Object.entries<LifecycleStage>(stages).map(([status, stage]) => [
      status,
      LIFECYCLE_TONES[stage],
    ]),
  ) as Record<S, StatusTone>;
}

/** A withdrawn status has its label struck through, so grey alone never has to say it. */
export function withdrawn(stage: LifecycleStage): boolean {
  return stage === 'withdrawn';
}
