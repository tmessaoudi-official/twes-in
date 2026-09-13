// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import type { ListDescriptor } from '../shared/list/list-types';
import { DESIGN_CUSTOMERS, type DesignCustomer } from './design-fixtures';

/** The customers list as configuration: the shape the real list takes at G3a, over fixture rows. */
export const DESIGN_CUSTOMERS_LIST: ListDescriptor<DesignCustomer> = {
  id: 'design-customers',
  rowId: (row) => row.id,
  pageSizes: [10, 25, 50],
  defaultSort: { column: 'name', direction: 'asc' },
  columns: [
    {
      id: 'name',
      label: 'design.customers.name',
      value: (row) => row.name,
      sortable: true,
      filterable: true,
      hideable: false,
    },
    {
      id: 'city',
      label: 'design.customers.city',
      value: (row) => row.city,
      sortable: true,
      filterable: true,
    },
    {
      id: 'country',
      label: 'design.customers.country',
      value: (row) => row.country,
      sortable: true,
      width: 96,
    },
    {
      id: 'vat',
      label: 'design.customers.vat',
      value: (row) => row.vat,
      filterable: true,
      defaultHidden: true,
    },
    {
      id: 'balance',
      label: 'design.customers.balance',
      value: (row) => row.balanceSortKey,
      sortable: true,
      align: 'end',
      width: 160,
    },
    {
      id: 'status',
      label: 'design.customers.status',
      value: (row) => row.status,
      sortable: true,
      width: 120,
    },
  ],
  filters: [
    {
      id: 'status',
      label: 'design.customers.status',
      value: (row) => row.status,
      options: ['active', 'archived'].map((status) => ({
        value: status,
        label: `design.customers.statuses.${status}`,
      })),
    },
    {
      id: 'country',
      label: 'design.customers.country',
      value: (row) => row.country,
      options: ['TN', 'FR'].map((country) => ({
        value: country,
        label: `design.countries.${country}`,
      })),
    },
  ],
};

@Component({
  selector: 'app-design-customers-page',
  imports: [
    DataList,
    DataListCell,
    DataListRowActions,
    MatButtonModule,
    MatIconModule,
    RouterLink,
    TranslatePipe,
  ],
  templateUrl: './design-customers-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class DesignCustomersPage {
  protected readonly list = DESIGN_CUSTOMERS_LIST;
  protected readonly rows = DESIGN_CUSTOMERS;
  protected readonly rowTestId = (row: DesignCustomer): string => `design-customer-${row.id}`;
}
