// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { AccountDeletionApi } from './account/account-deletion-api';
import { ActivityApi } from './activity/activity-api';
import { AuthApi } from './auth/auth-api';
import { ClosingApi } from './company/closing-api';
import { CompanyApi } from './company/company-api';
import { CompanySecurityApi } from './company/company-security-api';
import { DocumentDesignApi } from './company/document-design-api';
import { DEFAULT_DESIGN } from './company/document-design-forms';
import { ModulesApi } from './company/modules-api';
import { RolesApi } from './company/roles-api';
import { CustomersApi } from './customers/customers-api';
import { DataErasureApi } from './data-erasure/data-erasure-api';
import { DeliveryNotesApi } from './delivery-notes/delivery-notes-api';
import { ExpensesApi } from './expenses/expenses-api';
import { FiscalApi } from './fiscal/fiscal-api';
import { ImportApi } from './import/import-api';
import { InventoryApi } from './inventory/inventory-api';
import { InvitationApi } from './invitation/invitation-api';
import { InvoicesApi } from './invoices/invoices-api';
import { SubscriptionApi } from './licensing/subscription-api';
import { StopMailApi } from './notifications/stop-mail-api';
import { PasswordResetApi } from './password-reset/password-reset-api';
import { PlatformApi } from './platform/platform-api';
import { PriceListsApi } from './price-lists/price-lists-api';
import { ProductPhotosApi } from './products/product-photos-api';
import { ProductsApi } from './products/products-api';
import { PurchasesApi } from './purchases/purchases-api';
import { QuotesApi } from './quotes/quotes-api';
import { RecurringExpensesApi } from './recurring-expenses/recurring-expenses-api';
import { RecurringApi } from './recurring/recurring-api';
import { CustomFieldsApi } from './shared/custom-fields/custom-fields-api';
import { RecordRemovalsApi } from './shared/records/record-removals-api';
import { SettingsApi } from './shared/settings/settings-api';
import { TextsParts } from './shared/i18n/texts-part';
import { SignupApi } from './signup/signup-api';
import { SupportAccessApi } from './support-access/support-access-api';
import { VendorsApi } from './vendors/vendors-api';

/**
 * An answer the API gave that a reader has no words for (a code added later, a status nobody expected) is never said
 * as a lost connection: the reader keeps the code it named, `{ unknown }`, which every screen says in one sentence,
 * `feedback.unknown_refusal` (`shared/errors/unknown-refusal-api.ts`). Only no answer, or a gateway answering for an
 * API that is restarting, is the network.
 */

interface Reader {
  readonly name: string;
  readonly call: () => Promise<unknown>;
  readonly url: string;
}

const READERS: readonly Reader[] = [
  {
    name: 'the erasure',
    call: () => TestBed.inject(DataErasureApi).preview('c1'),
    url: '/api/companies/c1/data-erasure',
  },
  {
    name: "support's access",
    call: () => TestBed.inject(SupportAccessApi).read('c1'),
    url: '/api/companies/c1/support-access',
  },
  {
    name: 'the bin',
    call: () => TestBed.inject(RecordRemovalsApi).restore('c1', 'r1'),
    url: '/api/companies/c1/record-removals/r1/restore',
  },
  {
    name: 'the settings',
    call: () => TestBed.inject(SettingsApi).change('c1', 'presentation.density', 'user', 'compact'),
    url: '/api/companies/c1/settings/presentation.density',
  },
  {
    name: "a mail's stop link",
    call: () => TestBed.inject(StopMailApi).stop('t1'),
    url: '/api/notification-preferences/stop',
  },
];

describe('an answer a reader does not know', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TextsParts, useValue: { open: () => Promise.resolve() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it.each(READERS)('from $name keeps the code it named', async ({ call, url }) => {
    const asked = call();
    http.expectOne(url).flush({ error: 'brand_new' }, { status: 403, statusText: 'Forbidden' });
    await expect(asked).rejects.toMatchObject({ code: { unknown: 'brand_new' } });
  });

  it.each(READERS)('from $name naming nothing keeps its status', async ({ call, url }) => {
    const asked = call();
    http.expectOne(url).flush(null, { status: 500, statusText: 'Server Error' });
    await expect(asked).rejects.toMatchObject({ code: { unknown: '500' } });
  });

  it.each(READERS)(
    'from $name through a gateway while the API restarts is the network',
    async ({ call, url }) => {
      const asked = call();
      http.expectOne(url).flush(null, { status: 503, statusText: 'Service Unavailable' });
      await expect(asked).rejects.toMatchObject({ code: 'network' });
    },
  );
});

/**
 * Every way a feature reader decides the network, one call each: the canon's rule (`isUnreachable`), so no answer and
 * a gateway answering for an API that is restarting are both the lost connection, and an answer the API gave is not.
 */
interface NetworkReader {
  readonly name: string;
  readonly call: () => Promise<unknown>;
  /** Where the reader carries what it says; most carry it as their `code`. */
  readonly at?: (said: unknown) => object;
  /** The reader's own word for a value it sent refused (400, 422) that the answer does not name. */
  readonly word?: string;
  /** The reader names a 403 in its own words (the preview's « forbidden »). */
  readonly names403?: true;
}

const said = (reader: NetworkReader, what: unknown): object =>
  (reader.at ?? ((code: unknown) => ({ code })))(what);
const inState = (said: unknown) => ({ state: said });
const inRefusal = (said: unknown) => ({ refusal: { code: said } });

const NETWORK_READERS: readonly NetworkReader[] = [
  {
    name: 'the account deletion',
    call: () => TestBed.inject(AccountDeletionApi).delete(),
    word: 'refused',
  },
  {
    name: 'the activity journal',
    call: () =>
      TestBed.inject(ActivityApi).entries('c1', {
        page: 1,
        itemsPerPage: 10,
        q: '',
        actorIds: [],
        entityTypes: [],
        entityId: null,
        intervals: {},
        direction: 'desc',
      }),
  },
  { name: 'the sign-in', call: () => TestBed.inject(AuthApi).me() },
  {
    name: 'the password reset',
    call: () => TestBed.inject(PasswordResetApi).forgot('a@b.tn', 'fr'),
    word: 'refused',
  },
  { name: 'the closing', call: () => TestBed.inject(ClosingApi).read('c1') },
  {
    name: "the company's members",
    call: () => TestBed.inject(CompanyApi).members('c1'),
    // A 403 is the step-up asked or a role refused; a 422 an address no account answers to.
    names403: true,
    word: 'unknown_user',
  },
  { name: "the company's profile", call: () => TestBed.inject(CompanyApi).profile('c1') },
  {
    name: "the company's establishments",
    call: () => TestBed.inject(CompanyApi).establishments('c1'),
  },
  { name: "the company's security", call: () => TestBed.inject(CompanySecurityApi).read('c1') },
  {
    name: 'the document design preview',
    call: () => TestBed.inject(DocumentDesignApi).preview('c1', DEFAULT_DESIGN),
    at: inState,
    word: 'failed',
    names403: true,
  },
  { name: 'the modules', call: () => TestBed.inject(ModulesApi).list('c1') },
  {
    name: 'the roles',
    call: () => TestBed.inject(RolesApi).list('c1'),
    word: 'unknown_permission',
  },
  { name: 'the customers', call: () => TestBed.inject(CustomersApi).customer('c1', 'x1') },
  { name: 'the delivery notes', call: () => TestBed.inject(DeliveryNotesApi).options('c1') },
  { name: 'the expenses', call: () => TestBed.inject(ExpensesApi).options('c1') },
  { name: 'the fiscal settings', call: () => TestBed.inject(FiscalApi).units('c1') },
  { name: 'the imports', call: () => TestBed.inject(ImportApi).recent('c1') },
  { name: 'the stock', call: () => TestBed.inject(InventoryApi).options('c1') },
  { name: 'an invitation', call: () => TestBed.inject(InvitationApi).offer('t1') },
  { name: 'the invoices', call: () => TestBed.inject(InvoicesApi).options('c1') },
  { name: 'the subscriptions', call: () => TestBed.inject(SubscriptionApi).waiting() },
  {
    name: 'the platform',
    call: () => TestBed.inject(PlatformApi).withdrawOwnerInvitation('c1', 'i1'),
    word: 'refused',
  },
  {
    name: 'the price lists',
    call: () => TestBed.inject(PriceListsApi).get('c1', 'p1'),
    names403: true,
  },
  {
    name: "a product's photos",
    call: () => TestBed.inject(ProductPhotosApi).list('c1', 'p1'),
    at: inRefusal,
    word: 'not_a_picture',
  },
  { name: 'the products', call: () => TestBed.inject(ProductsApi).options('c1') },
  {
    name: "a product's barcodes",
    call: () => TestBed.inject(ProductsApi).replaceBarcodes('c1', 'p1', [], 1),
    at: inRefusal,
  },
  { name: 'the purchases', call: () => TestBed.inject(PurchasesApi).order('c1', 'o1') },
  { name: 'the quotes', call: () => TestBed.inject(QuotesApi).quote('c1', 'q1') },
  {
    name: 'the recurring expenses',
    call: () => TestBed.inject(RecurringExpensesApi).list('c1'),
  },
  { name: 'the recurring invoices', call: () => TestBed.inject(RecurringApi).list('c1') },
  {
    name: 'the custom fields',
    call: () => TestBed.inject(CustomFieldsApi).list('c1', 'customer'),
  },
  {
    name: 'the signup',
    call: () => TestBed.inject(SignupApi).availability(),
    word: 'refused',
  },
  { name: 'the vendors', call: () => TestBed.inject(VendorsApi).vendor('c1', 'v1') },
];

const GATEWAYS = [502, 503, 504].flatMap((status) =>
  NETWORK_READERS.map((reader) => ({ ...reader, status })),
);

describe('a feature reader deciding the network', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TextsParts, useValue: { open: () => Promise.resolve() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it.each(NETWORK_READERS)('from $name with no answer at all is the network', async (reader) => {
    const asked = reader.call();
    http.expectOne(() => true).error(new ProgressEvent('error'));
    await expect(asked).rejects.toMatchObject(said(reader, 'network'));
  });

  it.each(GATEWAYS)(
    'from $name through a gateway answering $status is the network',
    async (reader) => {
      const asked = reader.call();
      http.expectOne(() => true).flush(null, { status: reader.status, statusText: 'Gateway' });
      await expect(asked).rejects.toMatchObject(said(reader, 'network'));
    },
  );

  it.each(NETWORK_READERS)('from $name answering 500 is not the network', async (reader) => {
    const asked = reader.call().catch((error: unknown) => error);
    http.expectOne(() => true).flush(null, { status: 500, statusText: 'Server Error' });
    expect(await asked).not.toMatchObject(said(reader, 'network'));
  });

  it('from the sign-in keeps a 503 that names its refusal, the demo being busy', async () => {
    const asked = TestBed.inject(AuthApi).me();
    http.expectOne(() => true).flush({ error: 'demo_busy' }, { status: 503, statusText: 'Busy' });
    await expect(asked).rejects.toMatchObject({ code: 'demo_busy' });
  });
});

/**
 * The readers still saying a status they do not name in a word of their own (`invalid`, `refused`), taken wave by wave
 * as ruled: this list only shrinks, and is gone once every reader says such a status through `unnamedRefusal`.
 */
const NOT_YET: ReadonlySet<string> = new Set(['the sign-in']);

const UNNAMED_READERS = NETWORK_READERS.filter((reader) => !NOT_YET.has(reader.name));

describe('a feature reader given a status it does not name', () => {
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TextsParts, useValue: { open: () => Promise.resolve() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('waits only on readers of the table', () => {
    const names = new Set(NETWORK_READERS.map((reader) => reader.name));
    expect([...NOT_YET].filter((name) => !names.has(name))).toEqual([]);
  });

  it.each(UNNAMED_READERS)('from $name answering 500 keeps its status', async (reader) => {
    const asked = reader.call();
    http.expectOne(() => true).flush(null, { status: 500, statusText: 'Server Error' });
    await expect(asked).rejects.toMatchObject(said(reader, { unknown: '500' }));
  });

  it.each(UNNAMED_READERS.filter((reader) => reader.names403 === undefined))(
    'from $name refusing with a code it has no words for keeps that code',
    async (reader) => {
      const asked = reader.call();
      http
        .expectOne(() => true)
        .flush({ error: 'brand_new' }, { status: 403, statusText: 'Forbidden' });
      await expect(asked).rejects.toMatchObject(said(reader, { unknown: 'brand_new' }));
    },
  );

  it.each(UNNAMED_READERS)(
    'from $name refusing what was sent keeps its own word',
    async (reader) => {
      const asked = reader.call();
      http.expectOne(() => true).flush(null, { status: 422, statusText: 'Unprocessable' });
      await expect(asked).rejects.toMatchObject(said(reader, reader.word ?? 'invalid'));
    },
  );
});
