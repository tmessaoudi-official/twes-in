// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { RecordChanged } from '../shared/form/record-changed';
import { liveRecord } from '../shared/form/live-record';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import type { ListDescriptor } from '../shared/list/list-types';
import { DataList, DataListCell } from '../shared/list/data-list';
import {
  CONTACT_FORM,
  CONTACTS_LIST,
  contactInput,
  contactValues,
  customerForm,
  customerInput,
  customerValues,
} from './customer-forms';
import { CustomersFacade } from './customers-facade';
import { PartyDefaults } from './party-defaults';
import type { ContactRow } from './customers-types';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { ScreenActions } from '../shared/actions/screen-actions';
import type { PlannedAction } from '../shared/actions/planned-actions';
import type { ScreenAction } from '../shared/actions/screen-action';
import { RecordBar } from '../shared/form/record-bar';
import { MatTabsModule } from '@angular/material/tabs';

/** One customer: a new one to fill in, or an existing one with the people to write to there. */
/** What a customer will offer once its planned modules ship (docs/SPEC.md § 7, 2026-09-26 18:17, row 150), shown « Bientôt » beside what it offers today. */
export const CUSTOMER_PLANNED: readonly PlannedAction[] = [
  { module: 'quotes', label: 'planned_actions.new_quote', icon: 'request_quote' },
  { module: 'statements', label: 'planned_actions.statement', icon: 'account_balance_wallet' },
];

@Component({
  selector: 'app-customer-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListCell,
    DescriptorForm,
    MatTabsModule,
    RecordBar,
    PartyDefaults,
    RecordChanged,
  ],
  templateUrl: './customer-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerPage {
  private readonly facade = inject(CustomersFacade);
  private readonly unsaved = inject(UnsavedChanges);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly router = inject(Router);

  /** Bound from the route parameter by withComponentInputBinding(); absent on `customers/new`. */
  readonly customerId = input<string | undefined>(undefined);

  protected readonly id = computed(() => this.customerId() ?? null);
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly contacts = this.facade.contacts;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('customer.write'));

  /** Null while a new customer is filled in; undefined until the customer asked for has been read. */
  protected readonly current = computed(() => {
    const id = this.id();
    if (id === null) return null;
    const customer = this.facade.customer();
    return customer?.id === id ? customer : undefined;
  });
  protected readonly descriptor = computed(() => {
    const options = this.facade.options();
    return options === null
      ? null
      : customerForm(options, this.facade.groups(), this.facade.customFields());
  });
  /**
   * What the form is of: the customer and the fields shown. Reading the customer again yields new objects with the
   * same content, and must not rebuild the form over what is being typed; another customer or other fields must.
   */
  private readonly formKey = computed(() => {
    const descriptor = this.descriptor();
    const current = this.current();
    if (descriptor === null || current === undefined) return null;
    return `${current?.id ?? 'new'}|${JSON.stringify(descriptor)}`;
  });
  protected readonly form = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const descriptor = this.descriptor();
      const options = this.facade.options();
      const current = this.current();
      if (descriptor === null || options === null || current === undefined) return null;
      return buildFormGroup(
        descriptor,
        customerValues(current, options, this.facade.customFields()),
      );
    });
  });

  /** The saved version the form stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'customer',
    id: this.id,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      const id = this.id();
      if (companyId && id !== null) await this.facade.loadCustomer(companyId, id);
    },
    saved: () => {
      const current = this.current();
      const options = this.facade.options();
      return current && options
        ? customerValues(current, options, this.facade.customFields())
        : null;
    },
  });

  /** What the form holds that the API has not been told yet; the bar beside the title shows it. */
  protected readonly savedValues = computed(() => {
    const current = this.current();
    const options = this.facade.options();
    return current && options ? customerValues(current, options, this.facade.customFields()) : null;
  });
  protected readonly changes = unsavedChanges(this.form, this.savedValues);

  /** The subject of the defaults panel: the customer once it exists. */
  protected readonly defaultsSubject = computed(() => {
    const current = this.current();
    return current ? { customerId: current.id } : null;
  });

  /** A contact's own actions: editing under the pointer, removing behind "⋮" as everything destructive is. */
  protected readonly contactList = computed<ListDescriptor<ContactRow>>(() => ({
    ...CONTACTS_LIST,
    actions: [
      {
        id: 'edit',
        label: 'customers.edit',
        icon: 'edit',
        run: (row) => this.openContact(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
      },
      {
        id: 'remove',
        label: 'customers.contacts.remove',
        icon: 'delete',
        destructive: true,
        run: (row) => void this.removeContact(row),
        disabled: () => this.busy(),
        shown: () => this.mayWrite(),
        confirm: (row) => ({
          kind: 'definitif',
          title: 'customers.contacts.remove_title',
          message: 'customers.contacts.remove_message',
          // The same name the list's own column shows, so the question and the row read alike.
          messageParams: {
            name: [row.firstName, row.lastName].filter((part) => part !== null).join(' '),
          },
          confirmLabel: 'customers.contacts.remove_confirm',
          keepLabel: 'customers.contacts.keep',
        }),
      },
    ],
  }));
  protected readonly contactDescriptor = CONTACT_FORM;
  protected readonly contactTestId = (row: ContactRow): string => `contact-${row.email ?? row.id}`;
  protected readonly contactEditing = signal<ContactRow | 'new' | null>(null);
  protected readonly contactForm = computed(() => {
    const editing = this.contactEditing();
    return editing === null
      ? null
      : buildFormGroup(CONTACT_FORM, contactValues(editing === 'new' ? null : editing));
  });

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45).
    inject(ScreenActions).declare(this.recordActions);
    effect(() => {
      const companyId = this.company()?.id;
      const id = this.id();
      untracked(() => {
        this.contactEditing.set(null);
        if (companyId) {
          void this.facade.loadCustomer(companyId, id);
        }
      });
    });
  }

  /** What the record will offer once its planned modules ship, from the moment it exists. */
  protected readonly planned = computed(() => (this.current() ? CUSTOMER_PLANNED : []));
  /**
   * What this page offers, declared once (row 45): the bar beside the title draws it, and the keyboard, the Ctrl K
   * palette and the "?" sheet read the same list — so "s" saves here as it does on a document.
   */
  protected readonly recordActions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const changes = this.changes();
    const may = this.mayWrite();
    return [
      {
        id: 'save',
        label: 'form.save',
        icon: 'save',
        primary: true,
        shortcut: 's',
        // A save that is always available teaches nothing about whether there is anything to save.
        disabled: busy || changes === 0,
        run: () => this.saveFromBar(),
        shown: may,
      },
      {
        id: 'revert',
        label: 'form.revert',
        disabled: busy,
        run: () => this.revert(),
        shown: may && changes > 0,
      },
    ];
  });

  /** From the bar beside the title, which holds no form of its own. */
  protected saveFromBar(): void {
    const form = this.form();
    if (form === null) return;
    if (form.invalid) {
      form.markAllAsTouched();
      return;
    }
    void this.save(form.getRawValue());
  }

  protected revert(): void {
    const form = this.form();
    const saved = this.savedValues();
    if (form !== null && saved !== null) revertToSaved(form, saved);
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const options = this.facade.options();
    if (!companyId || options === null || this.busy()) return;
    const input = customerInput(values, options, this.facade.customFields());
    const id = this.id();
    if (id === null) {
      const created = await this.facade.createCustomer(companyId, input);
      if (created !== null) {
        // Its next step, to whoever may take it (docs/SPEC.md § 7, 2026-09-26, row 139).
        const next =
          this.auth.hasModule('invoices') && this.auth.hasPermission('invoice.write')
            ? {
                key: 'customers.suggest.invoice',
                run: () =>
                  void this.router.navigate(['/invoices/new'], {
                    queryParams: { billTo: created.id },
                  }),
              }
            : undefined;
        this.feedback.success('customers.saved', {}, next);
        // It exists now: going to it is not leaving unsaved work, though the form still holds what was
        // typed and the record holds what the API answered (row 45's leave guard, 2026-09-20).
        this.unsaved.savedAndLeaving();
        await this.router.navigate(['/customers', created.id], { replaceUrl: true });
      }
    } else if ((await this.facade.reviseCustomer(companyId, id, input)) !== null) {
      const form = this.form();
      if (form !== null) this.sync.savedHere(form);
      this.feedback.success('customers.saved');
    }
  }

  protected openContact(target: ContactRow | 'new'): void {
    this.facade.clearError();
    this.contactEditing.set(target);
  }

  protected cancelContact(): void {
    this.contactEditing.set(null);
    this.facade.clearError();
  }

  protected async saveContact(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    const editing = this.contactEditing();
    if (!companyId || id === null || editing === null || this.busy()) return;
    const input = contactInput(values);
    const accepted =
      editing === 'new'
        ? await this.facade.addContact(companyId, id, input)
        : await this.facade.reviseContact(companyId, id, editing.id, input);
    if (accepted) {
      this.contactEditing.set(null);
    }
  }

  protected async removeContact(row: ContactRow): Promise<void> {
    const companyId = this.company()?.id;
    const id = this.id();
    if (!companyId || id === null || this.busy()) return;
    await this.facade.removeContact(companyId, id, row.id);
  }
}
