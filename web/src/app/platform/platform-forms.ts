// SPDX-License-Identifier: AGPL-3.0-or-later

import { countriesNamed } from '../shared/i18n/country-names';
import { filterValues } from '../shared/list/list-filters';
import type { ListDescriptor, ListQuery } from '../shared/list/list-types';
import {
  COMPANY_STATUSES,
  companyStatusShown,
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
 * names a key the API knows. Opening one links to the list itself with the company named, which shows its sheet. Its
 * country filter offers the countries given: those the platform's country setting offers, the fiscal presets', each
 * as every screen names a country, in the language given.
 */
export function companiesListOf(
  countries: readonly string[],
  language: string,
): ListDescriptor<PlatformCompanyRow> {
  return {
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
        // The API's `q` reads the name and its owners' addresses: this column holds the list's search box.
        filterable: true,
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
        priority: 'high',
        label: `${COMPANY_FIELDS}.status`,
        value: (row) => companyStatusShown(row),
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
        value: (row) => companyStatusShown(row),
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
        options: countriesNamed(countries, language).map(({ code, label }) => ({
          value: code,
          label,
        })),
      },
    ],
  };
}

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
    // A country as the API names one, an ISO code; one with no company there finds none.
    countryCodes: countries.filter((code) => /^[A-Z]{2}$/.test(code)),
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
      // The API's `q` reads the name and the address: this column holds the list's search box.
      filterable: true,
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
  ],
  filters: [
    {
      id: 'state',
      label: `${ACCOUNT_FIELDS}.state`,
      multiple: true,
      value: (row) => (row.active ? 'active' : 'inactive'),
      options: ['active', 'inactive'].map((state) => ({
        value: state,
        label: `platform.accounts.states.${state}`,
      })),
    },
    {
      id: 'role',
      label: `${ACCOUNT_FIELDS}.role`,
      multiple: true,
      value: (row) => (row.platformOperator ? 'operator' : 'member'),
      options: ['operator', 'member'].map((role) => ({
        value: role,
        label: `platform.accounts.roles.${role}`,
      })),
    },
  ],
};

/** One value of a facet of two narrows to it; both, or none, are every account. */
function oneOf(chosen: readonly string[], yes: string, no: string): boolean | null {
  const takesYes = chosen.includes(yes);
  const takesNo = chosen.includes(no);
  return takesYes === takesNo ? null : takesYes;
}

/**
 * What the API is asked for the page of accounts the list shows: the state and the role combine, so a deactivated
 * operator is found. An address or a saved view from before the role was its own facet says `state=operator`, which
 * still means the platform's own.
 */
export function accountSearch(query: ListQuery): PlatformAccountSearch {
  const states = filterValues(query.filters['state']);
  const roles = [
    ...filterValues(query.filters['role']),
    ...(states.includes('operator') ? ['operator'] : []),
  ];
  const key = query.sort === null ? undefined : ACCOUNT_SORT_KEYS[query.sort.column];
  return {
    page: query.pageIndex + 1,
    itemsPerPage: query.pageSize,
    q: query.query,
    active: oneOf(states, 'active', 'inactive'),
    platformOperator: oneOf(roles, 'operator', 'member'),
    order:
      query.sort === null || key === undefined ? null : { key, direction: query.sort.direction },
  };
}
