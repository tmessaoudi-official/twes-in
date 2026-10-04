// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ProductHomeRow } from './products-types';

/** One establishment's homes in their order, the first the main one a receipt proposes. */
export interface EstablishmentHomes {
  readonly establishmentId: string;
  readonly establishmentCode: string;
  readonly establishmentName: string;
  readonly homes: readonly ProductHomeRow[];
}

/** The homes grouped by establishment, in the order the API gave the establishments, each group by position. */
export function groupByEstablishment(rows: readonly ProductHomeRow[]): EstablishmentHomes[] {
  const groups = new Map<string, ProductHomeRow[]>();
  for (const row of rows) {
    const own = groups.get(row.establishmentId);
    if (own === undefined) groups.set(row.establishmentId, [row]);
    else own.push(row);
  }
  return [...groups.values()].map((homes) => {
    const ordered = [...homes].sort((a, b) => a.position - b.position);
    const { establishmentId, establishmentCode, establishmentName } = ordered[0];
    return { establishmentId, establishmentCode, establishmentName, homes: ordered };
  });
}

/** A place added after the others; one already there stays where it is. */
export function appended(ids: readonly string[], locationId: string): string[] {
  return ids.includes(locationId) ? [...ids] : [...ids, locationId];
}

export function without(ids: readonly string[], locationId: string): string[] {
  return ids.filter((id) => id !== locationId);
}

/** A place one step along the order (−1 towards the main end), staying put at an end or when it is not there. */
export function moved(ids: readonly string[], locationId: string, step: -1 | 1): string[] {
  const from = ids.indexOf(locationId);
  const to = from + step;
  const next = [...ids];
  if (from === -1 || to < 0 || to >= ids.length) return next;
  [next[from], next[to]] = [next[to], next[from]];
  return next;
}
