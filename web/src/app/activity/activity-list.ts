// SPDX-License-Identifier: AGPL-3.0-or-later

import { filterValues, idValues, rangeParams } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery, ListRangeFilter } from '../shared/list/list-types';
import type { ActivityRow, ActivitySearch } from './activity-types';

/**
 * The kinds of record the journal names, each with its label under `activity.kinds`. A kind recorded later and not
 * listed here still shows, under its own code.
 */
export const ACTIVITY_KINDS = [
  'customer',
  'contact',
  'customer_group',
  'product',
  'product_category',
  'price_list',
  'vendor',
  'quote',
  'invoice',
  'delivery_note',
  'expense',
  'expense_category',
  'stock_location',
  'user',
  'membership',
  'invitation',
  'role',
  'company',
  'establishment',
  'numbering_series',
  'tax_component',
  'unit',
  'custom_field',
  'setting',
  'module',
  'export',
] as const;

/**
 * The actions the journal says in words, each under `activity.actions.<kind>.<verb>`, written after the person's name:
 * « a émis la facture ». Anything else is shown as the API records it, so a new action is never hidden.
 */
export const ACTIVITY_ACTIONS: readonly string[] = [
  'auth.login',
  'auth.logout',
  'auth.login_failed',
  'auth.mfa_enrolled',
  'auth.mfa_failed',
  'auth.mfa_verified',
  'auth.passkey_registered',
  'auth.passkey_removed',
  'auth.password_changed',
  'auth.password_reset',
  'auth.recovery_codes_regenerated',
  'auth.step_up',
  'company.profile_revised',
  'company.logo_changed',
  'company.logo_removed',
  'company.mfa_required_changed',
  'customer.created',
  'customer.revised',
  'customer.credit_applied',
  'contact.created',
  'contact.revised',
  'contact.deleted',
  'customer_group.created',
  'customer_group.revised',
  'customer_group.deleted',
  'product.created',
  'product.revised',
  'product.photo_added',
  'product.photo_removed',
  'product.photo_restored',
  'product.photos_reordered',
  'product.main_photo_changed',
  'product_category.created',
  'product_category.revised',
  'product_category.deleted',
  'price_list.created',
  'price_list.revised',
  'price_list.deleted',
  'vendor.created',
  'vendor.revised',
  'quote.created',
  'quote.revised',
  'quote.sent',
  'quote.accepted',
  'quote.refused',
  'quote.cancelled',
  'quote.invoiced',
  'invoice.created',
  'invoice.revised',
  'invoice.issued',
  'invoice.cancelled',
  'invoice.credited',
  'payment.recorded',
  'payment.deleted',
  'delivery_note.created',
  'delivery_note.revised',
  'delivery_note.validated',
  'delivery_note.delivered',
  'delivery_note.cancelled',
  'delivery_note.invoiced',
  'expense.created',
  'expense.revised',
  'expense.recorded',
  'expense.paid',
  'expense.deleted',
  'expense_category.created',
  'expense_category.revised',
  'stock_location.created',
  'stock_location.revised',
  'stock_location.deleted',
  'role.created',
  'role.revised',
  'role.deleted',
  'membership.removed',
  'invitation.sent',
  'invitation.accepted',
  'setting.changed',
  'setting.reset',
  'module.enabled',
  'module.disabled',
  'export.downloaded',
  'establishment.created',
  'establishment.revised',
  'numbering_series.revised',
  'tax_component.created',
  'tax_component.revised',
  'unit.created',
  'unit.revised',
  'custom_field.created',
  'custom_field.revised',
];

/** The translation key that says an action in words, or null for one said as recorded. */
export function actionKey(action: string): string | null {
  return ACTIVITY_ACTIONS.includes(action) ? `activity.actions.${action}` : null;
}

/** The translation key of a kind of record, or null for a kind not listed. */
export function kindKey(kind: string): string | null {
  return (ACTIVITY_KINDS as readonly string[]).includes(kind) ? `activity.kinds.${kind}` : null;
}

/** Where a record of the journal opens, for the kinds that have a page of their own. */
const RECORD_ROUTES: Readonly<Record<string, string>> = {
  customer: '/customers',
  product: '/products',
  vendor: '/vendors',
  quote: '/quotes',
  invoice: '/invoices',
  delivery_note: '/delivery-notes',
  expense: '/expenses',
};

export function recordLink(row: Pick<ActivityRow, 'entityType' | 'entityId'>): unknown[] | null {
  const route = RECORD_ROUTES[row.entityType];
  return route === undefined || row.entityId === null ? null : [route, row.entityId];
}

const RANGES: ListRangeFilter[] = [{ id: 'at', kind: 'day', label: 'activity.fields.at' }];

export const ACTIVITY_LIST: ListDescriptor<ActivityRow> = {
  id: 'activity',
  rowId: (row) => row.id,
  pageSizes: [50, 100, 200],
  defaultSort: { column: 'at', direction: 'desc' },
  columns: [
    {
      id: 'at',
      label: 'activity.fields.at',
      value: (row) => row.at,
      sortable: true,
      hideable: false,
      width: 180,
    },
    {
      id: 'actor',
      label: 'activity.fields.actor',
      value: (row) => row.actorName ?? '',
      hideable: false,
      width: 180,
    },
    { id: 'action', label: 'activity.fields.action', value: (row) => row.action, hideable: false },
    { id: 'record', label: 'activity.fields.record', value: (row) => row.entityType },
    { id: 'fields', label: 'activity.fields.fields', value: (row) => row.fields.join(', ') },
    {
      id: 'ip',
      label: 'activity.fields.ip',
      value: (row) => row.ip ?? '',
      defaultHidden: true,
      width: 140,
    },
  ],
  filters: [
    {
      id: 'kind',
      label: 'activity.fields.record',
      anyLabel: 'list.filter_any_feminine',
      multiple: true,
      value: (row) => row.entityType,
      options: ACTIVITY_KINDS.map((kind) => ({ value: kind, label: `activity.kinds.${kind}` })),
    },
  ],
  ranges: RANGES,
  picks: [{ id: 'actor', label: 'activity.fields.actor' }],
};

/** What the API is asked for the page of the journal the list shows: every filter is sent, none applied here. */
export function activitySearch(query: ListQuery, entityId: string | null = null): ActivitySearch {
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    actorIds: idValues(query.filters['actor']),
    entityTypes: filterValues(query.filters['kind']),
    entityId,
    intervals: rangeParams(query.filters, RANGES),
    direction: query.sort?.direction === 'asc' ? 'asc' : 'desc',
  };
}
