// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The next value of a state read again from the API, in which every part equal to the previous one IS the previous
 * one: a signal or a computed over an unchanged part then hears nothing, where a fresh object from the same JSON would
 * wake every effect reading it (a page clearing what was being entered because its company "changed").
 * Plain JSON values only: objects, arrays and primitives.
 */
export function unchangedKept<T>(previous: T, next: T): T {
  if (Object.is(previous, next)) return previous;
  if (Array.isArray(previous) && Array.isArray(next)) {
    const kept = next.map((item, index) => unchangedKept(previous[index], item));
    const same =
      kept.length === previous.length && kept.every((item, index) => item === previous[index]);

    return (same ? previous : kept) as T;
  }
  if (isPlainObject(previous) && isPlainObject(next)) {
    const previousKeys = Object.keys(previous);
    const nextKeys = Object.keys(next);
    const kept: Record<string, unknown> = {};
    let same = previousKeys.length === nextKeys.length;
    for (const key of nextKeys) {
      kept[key] = Object.hasOwn(previous, key)
        ? unchangedKept(previous[key], next[key])
        : next[key];
      if (kept[key] !== previous[key] || !Object.hasOwn(previous, key)) same = false;
    }

    return (same ? previous : kept) as T;
  }

  return next;
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
  const prototype: unknown = Object.getPrototypeOf(value);

  return prototype === Object.prototype || prototype === null;
}
