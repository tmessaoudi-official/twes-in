// SPDX-License-Identifier: AGPL-3.0-or-later

import { filterValues } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  COMPANY_COUNTRIES,
  COMPANY_STATUSES,
  type PlatformAccountRow,
  type PlatformAccountSearch,
  type PlatformCompanyRow,
  type PlatformCompanySearch,
} from './platform-types';

const COMPANY_FIELDS = 'platform.list.company';
const ACCOUNT_FIELDS = 'platform.list.account';

/** The column a person sorts the companies by, as the API names what it sorts them by. */
const COMPANY_SORT_KEYS: Readonly<
  Record<string, NonNullable<PlatformCompanySearch['order']>['key']>
> = { name: 'name', country: 'countryCode', status: 'status', created: 'createdAt' };

/** The column a person sorts the accounts by. */
const ACCOUNT_SORT_KEYS: Readonly<
  Record<string, NonNullable<PlatformAccountSearch['order']>['key']>
> = { name: 'displayName', email: 'email', state: 'active', created: 'createdAt' };

/**
 * The platform's companies, a page at a time: the API searches, filters and sorts them, so every column that sorts
 * names a key the API knows. Opening one links to the list itself with the company named, which shows its sheet.
 */
export const COMPANIES_LIST: ListDescriptor<PlatformCompanyRow> = {
  id: 'platform-companies',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'name', direction: 'asc' },
  link: () => [],
  linkQuery: (row) => ({ company: row.id }),
  linkColumn: 'name',
  columns: [
    {
      id: 'name',
      label: `${COMPANY_FIELDS}.name`,
      value: (row) => row.name,
      sortable: true,
      hideable: false,
    },
    {
      id: 'country',
      label: `${COMPANY_FIELDS}.country`,
      value: (row) => row.countryCode,
      sortable: true,
      width: 90,
    },
    {
      id: 'status',
      label: `${COMPANY_FIELDS}.status`,
      value: (row) => row.status,
      sortable: true,
      width: 140,
    },
    {
      id: 'owners',
      label: `${COMPANY_FIELDS}.owners`,
      value: (row) => row.owners.join(', '),
    },
    {
      id: 'subscription',
      label: `${COMPANY_FIELDS}.subscription`,
      value: (row) => row.subscription?.stage ?? '',
    },
    {
      id: 'created',
      label: `${COMPANY_FIELDS}.created`,
      value: (row) => row.createdAt.slice(0, 10),
      sortable: true,
      width: 120,
    },
  ],
  filters: [
    {
      id: 'status',
      label: `${COMPANY_FIELDS}.status`,
      multiple: true,
      value: (row) => row.status,
      options: COMPANY_STATUSES.map((status) => ({
        value: status,
        label: `platform.companies.statuses.${status}`,
      })),
    },
    {
      id: 'country',
      label: `${COMPANY_FIELDS}.country`,
      multiple: true,
      value: (row) => row.countryCode,
      options: Object.keys(COMPANY_COUNTRIES).map((code) => ({
        value: code,
        label: `platform.companies.countries.${code}`,
      })),
    },
  ],
};

/** What the API is asked for the page of companies the list shows. */
export function companySearch(query: ListQuery): PlatformCompanySearch {
  const statuses = filterValues(query.filters['status']);
  const countries = filterValues(query.filters['country']);
  const key = query.sort === null ? undefined : COMPANY_SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    statuses: COMPANY_STATUSES.filter((known) => statuses.includes(known)),
    countryCodes: Object.keys(COMPANY_COUNTRIES).filter((code) => countries.includes(code)),
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}

/** The platform's accounts, a page at a time, each showing the companies it belongs to. */
export const ACCOUNTS_LIST: ListDescriptor<PlatformAccountRow> = {
  id: 'platform-accounts',
  rowId: (row) => row.id,
  pageSizes: [25, 50, 100],
  defaultSort: { column: 'name', direction: 'asc' },
  columns: [
    {
      id: 'name',
      label: `${ACCOUNT_FIELDS}.name`,
      value: (row) => row.displayName,
      sortable: true,
      hideable: false,
    },
    {
      id: 'companies',
      label: `${ACCOUNT_FIELDS}.companies`,
      value: (row) => row.companies.map((company) => company.name).join(', '),
    },
    {
      id: 'state',
      label: `${ACCOUNT_FIELDS}.state`,
      value: (row) => (row.active ? 'active' : 'inactive'),
      sortable: true,
      width: 140,
    },
    {
      id: 'created',
      label: `${ACCOUNT_FIELDS}.created`,
      value: (row) => row.createdAt.slice(0, 10),
      sortable: true,
      width: 120,
    },
    { id: 'actions', label: `${ACCOUNT_FIELDS}.actions`, value: () => null, hideable: false },
  ],
  filters: [
    {
      id: 'state',
      label: `${ACCOUNT_FIELDS}.state`,
      value: (row) => (row.active ? 'active' : 'inactive'),
      options: ['active', 'inactive', 'operator'].map((state) => ({
        value: state,
        label: `platform.accounts.states.${state}`,
      })),
    },
  ],
};

/** What the API is asked for the page of accounts the list shows; « Opérateurs » narrows to the platform's own. */
export function accountSearch(query: ListQuery): PlatformAccountSearch {
  const state = query.filters['state'];
  const key = query.sort === null ? undefined : ACCOUNT_SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    active: state === 'active' ? true : state === 'inactive' ? false : null,
    platformOperator: state === 'operator' ? true : null,
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}
