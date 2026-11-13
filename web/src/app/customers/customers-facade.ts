// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ExportFormat } from '../shared/list/export-address';
import { computed, inject, Injectable } from '@angular/core';
import { CustomFieldsApi, CustomFieldsRefused } from '../shared/custom-fields/custom-fields-api';
import { readPart, unreadOf } from '../shared/errors/read-part';
import { unreadPart, type UnreadPart, type UnknownRefusal } from '../shared/errors/unknown-refusal';
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
  private readonly unreadPartsSignal = sessionSignal<readonly UnreadPart[]>([]);

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
  /** Whether `error` is a read's failure, what a list says in place of its rows; a refused save is not. */
  readonly readFailed = this.state.readFailed;
  readonly readRefusedFilter = this.state.readRefusedFilter;
  /** The fields the last save was refused on, each with the reason the screens translate. */
  readonly refusals = this.refusalsSignal.asReadonly();
  /** The parts another read answers that the last load could not read: the screen shows without them, naming each. */
  readonly unreadParts = this.unreadPartsSignal.asReadonly();
  /**
   * Whether the custom fields are unknown: refused and never read for the company, so the form draws none, and a save
   * sends back the values the customer was read with, since a field left out of a save is emptied. Fields read before
   * stay drawn, said unread beside them.
   */
  readonly customFieldsUnread = computed(
    () =>
      this.unreadPartsSignal().some((part) => part.unread === 'custom_fields') &&
      this.customFieldsSignal().length === 0,
  );

  /**
   * What the list screen needs besides its page: the groups its rows name and it filters by, the custom fields it adds
   * columns for, and the options, whose tax regimes it filters by.
   */
  async loadListContext(companyId: string): Promise<void> {
    await this.read(async () => {
      const [groups, customFields, options] = await Promise.all([
        this.groupsPart(companyId),
        this.customFieldsPart(companyId),
        this.api.options(companyId),
      ]);
      this.groupsSignal.set(groups.value);
      this.optionsSignal.set(options);
      this.customFieldsSignal.set(customFields.value);
      this.unreadPartsSignal.set(unreadOf(groups, customFields));
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
        this.groupsPart(companyId),
        this.customFieldsPart(companyId),
        id === null ? Promise.resolve(null) : this.api.customer(companyId, id),
        id === null ? Promise.resolve([]) : this.api.contacts(companyId, id),
      ]);
      this.optionsSignal.set(options);
      this.groupsSignal.set(groups.value);
      this.customFieldsSignal.set(customFields.value);
      this.unreadPartsSignal.set(unreadOf(groups, customFields));
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

  /** The groups beside a customer or the list: a customer shows and saves without them, its own group kept. */
  private groupsPart(companyId: string) {
    return readPart(this.api.groups(companyId), 'customer_groups', this.groupsSignal(), (error) =>
      error instanceof CustomersRefused ? error.code : null,
    );
  }

  /** The custom fields another feature declares: the customer shows without them, and a save sends its values back. */
  private customFieldsPart(companyId: string) {
    return readPart(
      this.fields.list(companyId, 'customer'),
      'custom_fields',
      this.customFieldsSignal(),
      (error) => (error instanceof CustomFieldsRefused ? error.code : null),
    );
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

function codeOf(error: unknown): CustomersError | UnreadPart | UnknownRefusal {
  if (error instanceof CustomFieldsRefused) return unreadPart('custom_fields', error.code);
  return error instanceof CustomersRefused ? error.code : 'network';
}
