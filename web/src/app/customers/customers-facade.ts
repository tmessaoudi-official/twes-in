// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ExportFormat } from '../shared/list/export-address';
import { inject, Injectable } from '@angular/core';
import { CustomFieldsApi } from '../shared/custom-fields/custom-fields-api';
import { LatestRequest, requestState } from '../shared/feedback/request-state';
import type { CustomFieldDefinition } from '../shared/custom-fields/custom-fields-types';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { CustomersApi, CustomersRefused } from './customers-api';
import type {
  ContactInput,
  ContactRow,
  CustomerGroupInput,
  CustomerGroupRow,
  CustomerInput,
  CustomerOptions,
  CustomerRow,
  CustomerSearch,
  CustomersError,
} from './customers-types';
import { sessionSignal } from '../shared/session/session-data';

/** The customers of the company being worked in, their groups, the company's custom fields, and the open one's contacts. */
@Injectable({ providedIn: 'root' })
export class CustomersFacade {
  private readonly api = inject(CustomersApi);
  private readonly fields = inject(CustomFieldsApi);
  private readonly customFieldsSignal = sessionSignal<readonly CustomFieldDefinition[]>([]);
  private readonly customersSignal = sessionSignal<readonly CustomerRow[]>([]);
  private readonly totalSignal = sessionSignal(0);
  private readonly pageRequests = new LatestRequest();
  private readonly groupsSignal = sessionSignal<readonly CustomerGroupRow[]>([]);
  private readonly optionsSignal = sessionSignal<CustomerOptions | null>(null);
  private readonly customerSignal = sessionSignal<CustomerRow | null>(null);
  private readonly contactsSignal = sessionSignal<readonly ContactRow[]>([]);
  private readonly state = requestState(codeOf);
  private readonly refusalsSignal = sessionSignal<readonly FieldRefusal[]>([]);

  readonly customers = this.customersSignal.asReadonly();
  /** How many customers the last search found in all, the page shown being one part of them. */
  readonly total = this.totalSignal.asReadonly();
  readonly groups = this.groupsSignal.asReadonly();
  /** Every custom field declared for customers, retired ones included; screens show the active ones. */
  readonly customFields = this.customFieldsSignal.asReadonly();
  readonly options = this.optionsSignal.asReadonly();
  readonly customer = this.customerSignal.asReadonly();
  readonly contacts = this.contactsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** The fields the last save was refused on, each with the reason the screens translate. */
  readonly refusals = this.refusalsSignal.asReadonly();

  /**
   * What the list screen needs besides its page: the groups its rows name and it filters by, the custom fields it adds
   * columns for, and the options, whose tax regimes it filters by.
   */
  async loadListContext(companyId: string): Promise<void> {
    await this.read(async () => {
      const [groups, customFields, options] = await Promise.all([
        this.api.groups(companyId),
        this.fields.list(companyId, 'customer'),
        this.api.options(companyId),
      ]);
      this.groupsSignal.set(groups);
      this.optionsSignal.set(options);
      this.customFieldsSignal.set(customFields);
    });
  }

  /**
   * One page of the customers the search finds. Only the latest search's answer is shown: typing sends one search per
   * pause, and an earlier one answering late would otherwise put back rows the words no longer find.
   */
  async loadPage(companyId: string, search: CustomerSearch): Promise<void> {
    const current = this.pageRequests.next();
    await this.read(async () => {
      const page = await this.api.customers(companyId, search);
      if (!current()) return;
      this.customersSignal.set(page.rows);
      this.totalSignal.set(page.total);
    });
  }

  exportUrl(companyId: string, search: CustomerSearch, format: ExportFormat): string {
    return this.api.exportUrl(companyId, search, format);
  }

  async loadGroups(companyId: string): Promise<void> {
    await this.read(async () => this.groupsSignal.set(await this.api.groups(companyId)));
  }

  /** What the customer form needs: its options, the groups, and the customer with its contacts unless it is new. */
  async loadCustomer(companyId: string, id: string | null): Promise<void> {
    await this.read(async () => {
      const [options, groups, customFields, customer, contacts] = await Promise.all([
        this.api.options(companyId),
        this.api.groups(companyId),
        this.fields.list(companyId, 'customer'),
        id === null ? Promise.resolve(null) : this.api.customer(companyId, id),
        id === null ? Promise.resolve([]) : this.api.contacts(companyId, id),
      ]);
      this.optionsSignal.set(options);
      this.groupsSignal.set(groups);
      this.customFieldsSignal.set(customFields);
      this.customerSignal.set(customer);
      this.contactsSignal.set(contacts);
    });
  }

  /** The customer as the API kept it, or null with the reason in `error`. */
  async createCustomer(companyId: string, input: CustomerInput): Promise<CustomerRow | null> {
    return this.save(() => this.api.createCustomer(companyId, input));
  }

  async reviseCustomer(
    companyId: string,
    id: string,
    input: CustomerInput,
    version: number | null,
  ): Promise<CustomerRow | null> {
    return this.save(() => this.api.reviseCustomer(companyId, id, input, version));
  }

  async createGroup(companyId: string, input: CustomerGroupInput): Promise<boolean> {
    return this.write(
      () => this.api.createGroup(companyId, input),
      () => this.reloadGroups(companyId),
    );
  }

  async reviseGroup(
    companyId: string,
    id: string,
    input: CustomerGroupInput,
    version: number | null,
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseGroup(companyId, id, input, version),
      () => this.reloadGroups(companyId),
    );
  }

  async deleteGroup(companyId: string, id: string): Promise<boolean> {
    return this.write(
      () => this.api.deleteGroup(companyId, id),
      () => this.reloadGroups(companyId),
    );
  }

  /** The contacts are read again after each change: making one primary steps the previous one down. */
  async addContact(companyId: string, customerId: string, input: ContactInput): Promise<boolean> {
    return this.write(
      () => this.api.addContact(companyId, customerId, input),
      () => this.reloadContacts(companyId, customerId),
    );
  }

  async reviseContact(
    companyId: string,
    customerId: string,
    id: string,
    input: ContactInput,
    version: number | null,
  ): Promise<boolean> {
    return this.write(
      () => this.api.reviseContact(companyId, customerId, id, input, version),
      () => this.reloadContacts(companyId, customerId),
    );
  }

  async removeContact(companyId: string, customerId: string, id: string): Promise<boolean> {
    return this.write(
      () => this.api.removeContact(companyId, customerId, id),
      () => this.reloadContacts(companyId, customerId),
    );
  }

  clearError(): void {
    this.state.clearError();
  }

  private async reloadGroups(companyId: string): Promise<void> {
    this.groupsSignal.set(await this.api.groups(companyId));
  }

  /** Reads a customer's contacts again, as an open contact editor does when another person saved one. */
  async loadContacts(companyId: string, customerId: string): Promise<void> {
    await this.read(() => this.reloadContacts(companyId, customerId));
  }

  private async reloadContacts(companyId: string, customerId: string): Promise<void> {
    this.contactsSignal.set(await this.api.contacts(companyId, customerId));
  }

  private async read(load: () => Promise<void>): Promise<void> {
    await this.state.read(load);
  }

  private async save(call: () => Promise<CustomerRow>): Promise<CustomerRow | null> {
    this.refusalsSignal.set([]);
    return this.state.writeAnswer(
      async () => {
        const customer = await call();
        this.customerSignal.set(customer);
        return customer;
      },
      {
        failed: (error) =>
          this.refusalsSignal.set(error instanceof CustomersRefused ? error.refusals : []),
      },
    );
  }

  private async write(call: () => Promise<unknown>, reload: () => Promise<void>): Promise<boolean> {
    this.refusalsSignal.set([]);
    return this.state.write(
      async () => {
        await call();
        await reload();
      },
      {
        failed: (error) =>
          this.refusalsSignal.set(error instanceof CustomersRefused ? error.refusals : []),
      },
    );
  }
}

function codeOf(error: unknown): CustomersError {
  return error instanceof CustomersRefused ? error.code : 'network';
}
