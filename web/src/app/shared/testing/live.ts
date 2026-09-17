// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { LIVE_BATCH_MS, LiveChanges } from '../realtime/live-changes';

/** Announces, as the realtime connection would, that another person saved a record; resolves once it was heard. */
export async function announceSaved(
  kind: string,
  id: string,
  actor: { id: string; name: string | null } | null = { id: 'u2', name: 'Nadia' },
  action = `${kind}.revised`,
): Promise<void> {
  TestBed.inject(LiveChanges).receive({ type: 'changed', kind, id, action, actor, origin: null });
  await new Promise((resolve) => setTimeout(resolve, LIVE_BATCH_MS + 10));
}
