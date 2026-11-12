// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  unreadPart,
  type UnknownRefusal,
  type UnreadPart,
  type UnreadPartName,
} from './unknown-refusal';

/** A part another feature reads for a screen beside its own record: what was read, or the part named as not read. */
export interface PartRead<T> {
  readonly value: T;
  readonly unread: UnreadPart | null;
}

/**
 * Reads a part beside a screen's record so that its refusal takes only the part away: the record still shows, the
 * part is empty and named with its code. The network, and anything that is not the part refused, fails the whole
 * read, as the record's own read would. `refusedCode` answers the part's code when the error is its refusal, or null.
 */
export async function readPart<T>(
  read: Promise<T>,
  part: UnreadPartName,
  empty: T,
  refusedCode: (error: unknown) => string | UnknownRefusal | null,
): Promise<PartRead<T>> {
  try {
    return { value: await read, unread: null };
  } catch (error) {
    const code = refusedCode(error);
    const said = code === null ? 'network' : unreadPart(part, code);
    if (said === 'network') throw error;
    return { value: empty, unread: said };
  }
}

/** The parts a screen could not read, in the order it read them. */
export function unreadOf(...reads: readonly PartRead<unknown>[]): readonly UnreadPart[] {
  return reads.flatMap((read) => (read.unread === null ? [] : [read.unread]));
}
