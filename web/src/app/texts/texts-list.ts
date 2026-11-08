// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import { placeOf, TEXT_PLACES, type TextQuery, type TextRow } from './texts-types';

/** A text as the list shows it: where it is read, in the screen's words, beside its wording at each level. */
export interface TextView {
  readonly id: string;
  readonly where: string;
  readonly key: string;
  readonly original: string;
  readonly installation: string | null;
  readonly company: string | null;
  readonly row: TextRow;
}

/**
 * The list of texts at a level, its columns and its filters; the page adds its actions. The four columns the
 * catalogue's search reads (TextCatalogue::says) draw the search box over them. Each filter names the parameters it
 * is sent as (`asked`), so a filter the API refuses is named on the list.
 */
export function textsListOf(installation: boolean): ListDescriptor<TextView> {
  return {
    id: installation ? 'platform-texts' : 'company-texts',
    rowId: (view) => view.id,
    pageSizes: [25, 50, 100, 200],
    columns: [
      { id: 'where', label: 'texts.columns.where', value: (view) => view.where, width: 140 },
      {
        id: 'original',
        label: 'texts.columns.original',
        value: (view) => view.original,
        hideable: false,
        filterable: true,
        wraps: true,
        width: 240,
      },
      {
        id: 'installation',
        label: installation ? 'texts.columns.yours' : 'texts.columns.installation',
        value: (view) => view.installation,
        filterable: true,
        wraps: true,
        width: 240,
        priority: installation ? 'high' : 'normal',
      },
      ...(installation
        ? []
        : [
            {
              id: 'company',
              label: 'texts.columns.yours',
              value: (view: TextView) => view.company,
              filterable: true,
              wraps: true,
              width: 240,
              priority: 'high' as const,
            },
          ]),
      {
        id: 'key',
        label: 'texts.columns.key',
        value: (view) => view.key,
        filterable: true,
        defaultHidden: true,
        priority: 'low',
      },
    ],
    filters: [
      {
        id: 'place',
        label: 'texts.filters.place',
        value: (view) => placeOf(view.row.domain),
        options: TEXT_PLACES.map((place) => ({ value: place, label: `texts.where.${place}` })),
        asked: ['domain'],
      },
      {
        id: 'changed',
        label: 'texts.filters.changed',
        // A text may hold both reasons, an old wording a release changed the original of.
        value: (view) => [
          ...(view.row.originalChanged ? ['changed'] : []),
          ...(view.row.formsMissing ? ['forms'] : []),
        ],
        options: [
          { value: 'changed', label: 'texts.filters.original_changed' },
          { value: 'forms', label: 'texts.filters.forms_missing' },
        ],
        // Each reason is a switch of its own for the API.
        asked: ['changed', 'forms'],
      },
    ],
  };
}

/** What the catalogue is asked for a page of the list: its search, its place, its reason and its page. */
export function textSearch(query: ListQuery): TextQuery {
  return {
    words: query.query,
    place: TEXT_PLACES.find((place) => place === query.filters['place']) ?? null,
    changed: query.filters['changed'] === 'changed',
    forms: query.filters['changed'] === 'forms',
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
  };
}
