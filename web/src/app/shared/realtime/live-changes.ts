// SPDX-License-Identifier: AGPL-3.0-or-later

import { type DestroyRef, inject, Injectable, Injector } from '@angular/core';
import { RequestActivity } from '../feedback/request-activity';
import { tabId } from './tab-interceptor';

/** How long a burst of changes is gathered before a screen hears it, so ten saved lines reload once. */
export const LIVE_BATCH_MS = 150;

/**
 * That something another tab or person changed, never what it now holds: a screen reads it again through the API,
 * under this session's permissions (docs/SPEC.md § 7, 2026-09-17). The kind is the API's audit entity type
 * (`customer`, `invoice`, `setting`…), plus `stock` for a stock movement.
 */
export interface LiveChange {
  readonly kind: string;
  readonly id: string | null;
  readonly action: string;
  readonly actor: { readonly id: string; readonly name: string | null } | null;
}

/** The change a realtime publication announces, or null for a notification, this tab's own echo, or anything malformed. */
export function readLiveChange(data: unknown, tab: string): LiveChange | null {
  if (!isRecord(data) || data['type'] !== 'changed' || data['origin'] === tab) return null;
  const { kind, id, action, actor } = data;
  if (typeof kind !== 'string' || typeof action !== 'string') return null;
  if (id !== null && typeof id !== 'string') return null;
  if (actor !== null) {
    if (!isRecord(actor) || typeof actor['id'] !== 'string') return null;
    if (actor['name'] !== null && typeof actor['name'] !== 'string') return null;
  }
  return {
    kind,
    id,
    action,
    actor:
      actor === null ? null : { id: actor['id'] as string, name: actor['name'] as string | null },
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

interface Listener {
  readonly kinds: ReadonlySet<string>;
  readonly handler: (changes: readonly LiveChange[]) => void;
  pending: LiveChange[];
  timer: ReturnType<typeof setTimeout> | null;
}

/** Where every screen hears what others changed; the realtime connection delivers each publication here. */
@Injectable({ providedIn: 'root' })
export class LiveChanges {
  // Read when a reload runs, so a screen that only listens needs nothing the activity bar needs.
  private readonly injector = inject(Injector);
  private readonly listeners = new Set<Listener>();

  receive(data: unknown): void {
    const change = readLiveChange(data, tabId());
    if (change === null) return;
    for (const listener of this.listeners) {
      if (!listener.kinds.has(change.kind)) continue;
      listener.pending.push(change);
      listener.timer ??= setTimeout(() => {
        const changes = listener.pending;
        listener.pending = [];
        listener.timer = null;
        listener.handler(changes);
      }, LIVE_BATCH_MS);
    }
  }

  /** Calls `handler` with each burst of changes of these kinds, until the screen that listens is destroyed. */
  on(
    kinds: readonly string[],
    handler: (changes: readonly LiveChange[]) => void,
    destroyRef: DestroyRef,
  ): void {
    const listener: Listener = { kinds: new Set(kinds), handler, pending: [], timer: null };
    this.listeners.add(listener);
    destroyRef.onDestroy(() => {
      if (listener.timer !== null) clearTimeout(listener.timer);
      this.listeners.delete(listener);
    });
  }

  /** Reads a screen's data again when one of these kinds changes, quietly, since nobody on it asked for the reload. */
  reloadOn(kinds: readonly string[], reload: () => Promise<unknown>, destroyRef: DestroyRef): void {
    this.on(kinds, () => void this.injector.get(RequestActivity).quietly(reload), destroyRef);
  }
}
