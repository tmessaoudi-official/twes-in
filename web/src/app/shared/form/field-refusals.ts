// SPDX-License-Identifier: AGPL-3.0-or-later

import { CUSTOM_FIELD_PREFIX } from '../custom-fields/custom-fields';

/**
 * A field the API refused, as its 422 names it: the form field it lands on, the reason the screens translate (the
 * import's own codes, `import.rejections.*`) and that text's parameters. A resource constraint and a domain rule
 * answer the same shape, a violation whose payload carries the reason.
 */
export interface FieldRefusal {
  readonly field: string;
  readonly reason: string | null;
  readonly params: Readonly<Record<string, number | string>>;
}

/** Where a form keeps a registration number: one field per identifier of the preset, as the API keys them. */
export const IDENTIFIER_FIELD_PREFIX = 'identifier__';

/** The refusals a 422's body names; none when it names no field. A feature's API adapter hands it the body. */
export function refusalsIn(body: unknown): readonly FieldRefusal[] {
  const violations: unknown = (body as { violations?: unknown } | null)?.violations;
  if (!Array.isArray(violations)) return [];
  return violations.flatMap((violation: unknown) => {
    const { propertyPath, payload } = (violation ?? {}) as {
      propertyPath?: unknown;
      payload?: unknown;
    };
    if (typeof propertyPath !== 'string' || propertyPath === '') return [];
    const { reason, params } = (payload ?? {}) as { reason?: unknown; params?: unknown };
    return [
      {
        field: fieldOf(propertyPath),
        reason: typeof reason === 'string' ? reason : null,
        params: plainParams(params),
      },
    ];
  });
}

/**
 * The form field a property path lands on. A map's entry is written `identifiers.siren` by a domain rule and
 * `identifiers[siren]` by a constraint over each of its values; both are the one field the form shows for it.
 */
function fieldOf(path: string): string {
  const entry = /^(identifiers|customFields)(?:\.|\[)([^\]]+)\]?$/.exec(path);
  if (entry === null) return path;
  return (entry[1] === 'identifiers' ? IDENTIFIER_FIELD_PREFIX : CUSTOM_FIELD_PREFIX) + entry[2];
}

function plainParams(params: unknown): Readonly<Record<string, number | string>> {
  if (params === null || typeof params !== 'object' || Array.isArray(params)) return {};
  return Object.fromEntries(
    Object.entries(params).filter(
      (entry): entry is [string, number | string] =>
        typeof entry[1] === 'string' || typeof entry[1] === 'number',
    ),
  );
}
