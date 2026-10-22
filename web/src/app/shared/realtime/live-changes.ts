// SPDX-License-Identifier: AGPL-3.0-or-later

import { type DestroyRef, inject, Injectable, Injector } from '@angular/core';
import { RequestActivity } from '../feedback/request-activity';
import { tabId } from './tab-interceptor';

/** How long a burst of changes is gathered before a screen hears it, so ten saved lines reload once. */
export const LIVE_BATCH_MS = 150;

/**
 * That something changed, never what it now holds: a screen reads it again through the API, under this session's
 * permissions (docs/SPEC.md § 7, 2026-09-17). The kind is the API's audit entity type (`customer`, `invoice`,
 * `setting`…), plus `stock` for a stock movement. A change of many records at once, as a file's import makes, is
 * `bulk`: it names none of them, only how many. A change this tab made itself is `own`: every panel of the tab hears
 * it, as the outcome of an action shows at once wherever its subject appears, but nobody is told that someone changed it.
 */
export interface LiveChange {
  readonly kind: string;
  readonly id: string | null;
  readonly action: string;
  readonly actor: { readonly id: string; readonly name: string | null } | null;
  readonly bulk?: true;
  readonly count?: number;
  readonly own?: true;
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
  const change: LiveChange = {
    kind,
    id,
    action,
    actor:
      actor === null ? null : { id: actor['id'] as string, name: actor['name'] as string | null },
  };
  const { bulk, count } = data;
  return bulk === true && typeof count === 'number' ? { ...change, bulk, count } : change;
}

/** The changes the API's answer names for this tab's own request (`X-Live-Changes`), each read as a publication is. */
export function readOwnChanges(header: string | null): LiveChange[] {
  if (header === null || header === '') return [];
  let named: unknown;
  try {
    named = JSON.parse(header);
  } catch {
    // An answer a proxy or an older API mangled names nothing this tab can act on; its screens read again on their own.
    return [];
  }
  if (!Array.isArray(named)) return [];
  return named.flatMap((item: unknown) => {
    const change = isRecord(item)
      ? readLiveChange({ ...item, type: 'changed', actor: null, origin: null }, '')
      : null;
    return change === null ? [] : [{ ...change, own: true as const }];
  });
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
    if (change !== null) this.deliver(change);
  }

  /**
   * What this tab's own request changed, as the API's answer names it: the connection says a change to every tab but
   * the one that made it, so without this the acting tab's other panels (a count, the bell, another list) would wait
   * for a reload.
   */
  heardHere(header: string | null): void {
    for (const change of readOwnChanges(header)) this.deliver(change);
  }

  private deliver(change: LiveChange): void {
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

  /**
   * Reads a screen's data again when one of these kinds changes, quietly, since nobody on it asked for the reload.
   * One read at a time: reads side by side pile up on a slow answer, and an older one landing last shows what was
   * there before. What changes during a read is read once after it, so the screen ends on the latest state.
   */
  reloadOn(kinds: readonly string[], reload: () => Promise<unknown>, destroyRef: DestroyRef): void {
    let reading = false;
    let again = false;
    const read = async (): Promise<void> => {
      again = reading;
      if (reading) return;
      reading = true;
      let failure: { readonly error: unknown } | undefined;
      do {
        again = false;
        // Read again if something changed meanwhile; a failure still left goes on as it did before, unhandled.
        failure = await this.injector
          .get(RequestActivity)
          .quietly(reload)
          .then(
            () => undefined,
            (error: unknown) => ({ error }),
          );
      } while (again);
      reading = false;
      if (failure) throw failure.error;
    };
    this.on(kinds, () => void read(), destroyRef);
  }
}
