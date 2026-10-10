// SPDX-License-Identifier: AGPL-3.0-or-later

import type { TranslateService } from '@ngx-translate/core';
import { ErasureRefused } from './data-erasure-api';

/** The tables a conflict names that the page says in words; any other is said as « something made since ». */
const NAMED_TABLES = [
  'venue_area',
  'venue_spot',
  'venue_structure',
  'invoice',
  'delivery_note',
  'quote',
] as const;

/** A refusal as one toast: its translation key and what it names. */
export function refusalToast(
  error: unknown,
  translate: TranslateService,
): { key: string; params: Record<string, string> } {
  const code = error instanceof ErasureRefused ? error.code : 'network';
  const table = error instanceof ErasureRefused ? error.table : null;
  if (code === 'erasure_conflict') {
    const named = NAMED_TABLES.find((candidate) => candidate === table);
    return named === undefined
      ? { key: 'data_erasure.errors.erasure_conflict_any', params: {} }
      : {
          key: 'data_erasure.errors.erasure_conflict',
          params: { table: translate.instant(`data_erasure.tables.${named}`) },
        };
  }
  return { key: `data_erasure.errors.${code}`, params: {} };
}
