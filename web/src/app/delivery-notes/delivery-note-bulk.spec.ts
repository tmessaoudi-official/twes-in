// SPDX-License-Identifier: AGPL-3.0-or-later

import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { TestBed } from '@angular/core/testing';
import { Router } from '@angular/router';
import { provideTranslateService } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { DeliveryNoteBulk } from './delivery-note-bulk';
import { DeliveryNotesRefused } from './delivery-notes-api';
import { DeliveryNotesFacade } from './delivery-notes-facade';
import type {
  InvoiceDraftOption,
  InvoicingCheck,
  InvoicingSkipReason,
} from './delivery-notes-types';

describe('DeliveryNoteBulk', () => {
  let check: InvoicingCheck | Error;
  let drafts: InvoiceDraftOption[] | Error;
  let invoiced: string | Error;

  const facade = {
    invoicingCheck: vi.fn<(companyId: string, ids: readonly string[]) => Promise<InvoicingCheck>>(
      () => (check instanceof Error ? Promise.reject(check) : Promise.resolve(check)),
    ),
    draftsToAddTo: vi.fn<
      (
        companyId: string,
        customerId: string,
        establishmentId: string | null,
      ) => Promise<InvoiceDraftOption[]>
    >(() => (drafts instanceof Error ? Promise.reject(drafts) : Promise.resolve(drafts))),
    invoiceNotes: vi.fn<
      (companyId: string, ids: readonly string[], intoDraftId?: string) => Promise<string>
    >(() => (invoiced instanceof Error ? Promise.reject(invoiced) : Promise.resolve(invoiced))),
  };
  const navigate = vi.fn(() => Promise.resolve(true));

  function create(ids: readonly string[]) {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr' }),
        provideQuietFeedback(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: AuthFacade, useValue: { me: () => null } },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        { provide: DeliveryNotesFacade, useValue: facade },
        { provide: Router, useValue: { navigate } },
      ],
    });
    const fixture = TestBed.createComponent(DeliveryNoteBulk);
    fixture.componentRef.setInput('companyId', 'c1');
    fixture.componentRef.setInput('ids', ids);
    fixture.componentRef.setInput('scale', 3);
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => {
    drafts = [];
    invoiced = 'i1';
  });

  afterEach(() => {
    Object.values(facade).forEach((call) => call.mockClear());
    navigate.mockClear();
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  const row = (id: string, reason: InvoicingSkipReason | null = null) => ({
    id,
    number: reason === 'not_found' ? null : `BL-${id}`,
    customerName: reason === 'not_found' ? null : 'Carthage Conseil',
    included: reason === null,
    reason,
  });
  const checkOf = (...rows: ReturnType<typeof row>[]): InvoicingCheck => ({
    rows,
    included: rows.filter((one) => one.included).length,
    skipped: rows.filter((one) => !one.included).length,
    customerId: rows.some((one) => one.included) ? 'k1' : null,
    establishmentId: rows.some((one) => one.included) ? 'e1' : null,
  });
  const body = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`);
  const said = () => (TestBed.inject(Feedback) as RecordedFeedback).said.map((toast) => toast.key);

  async function settle(): Promise<void> {
    await new Promise((resolve) => setTimeout(resolve));
  }

  async function invoice(fixture: ReturnType<typeof create>): Promise<void> {
    (
      fixture.nativeElement.querySelector(
        '[data-testid="delivery-notes-bulk-invoice"]',
      ) as HTMLElement
    ).click();
    await settle();
    fixture.detectChanges();
  }

  it('puts every selected note on a new invoice straight away when the API takes them all, and opens it', async () => {
    check = checkOf(row('a'), row('b'));
    const fixture = create(['a', 'b']);

    await invoice(fixture);

    expect(facade.invoicingCheck).toHaveBeenCalledExactlyOnceWith('c1', ['a', 'b']);
    expect(facade.draftsToAddTo).toHaveBeenCalledExactlyOnceWith('c1', 'k1', 'e1');
    expect(body('delivery-notes-bulk-skipped')).toBeNull();
    expect(facade.invoiceNotes).toHaveBeenCalledExactlyOnceWith('c1', ['a', 'b'], undefined);
    expect(navigate).toHaveBeenCalledExactlyOnceWith(['/invoices', 'i1']);
  });

  it('names each note left out and why, and invoices the others only once the person says so', async () => {
    check = checkOf(row('a'), row('z', 'other_customer'), row('b'));
    const fixture = create(['a', 'z', 'b']);

    await invoice(fixture);

    expect(body('delivery-notes-bulk-skipped')?.textContent).toContain(
      'delivery_notes.bulk.reasons.other_customer',
    );
    expect(body('delivery-notes-bulk-skipped-z')?.textContent).toContain('BL-z');
    expect(body('delivery-notes-bulk-skipped-a')).toBeNull();
    expect(facade.invoiceNotes).not.toHaveBeenCalled();

    body('delivery-notes-bulk-go')!.click();
    await settle();

    expect(facade.invoiceNotes).toHaveBeenCalledExactlyOnceWith('c1', ['a', 'b'], undefined);
    expect(navigate).toHaveBeenCalledExactlyOnceWith(['/invoices', 'i1']);
  });

  it('invoices nothing when the person stops at the notes left out', async () => {
    check = checkOf(row('a'), row('z', 'draft'));
    const fixture = create(['a', 'z']);

    await invoice(fixture);
    body('delivery-notes-bulk-cancel')!.click();
    await settle();

    expect(facade.draftsToAddTo).not.toHaveBeenCalled();
    expect(facade.invoiceNotes).not.toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('offers only to close when no selected note can be invoiced, naming one it no longer finds', async () => {
    check = checkOf(row('z', 'not_found'), row('y', 'invoiced'));
    const fixture = create(['z', 'y']);

    await invoice(fixture);

    expect(body('delivery-notes-bulk-go')).toBeNull();
    expect(body('delivery-notes-bulk-cancel')?.textContent).toContain('delivery_notes.bulk.close');
    expect(body('delivery-notes-bulk-skipped-z')?.textContent).toContain(
      'delivery_notes.bulk.unknown',
    );
  });

  it('asks where the notes go when the customer has a draft there, and adds them to the one picked', async () => {
    check = checkOf(row('a'), row('b'));
    drafts = [{ id: 'd1', total: '12.000', lineCount: 2, customerReference: null }];
    const fixture = create(['a', 'b']);

    await invoice(fixture);
    expect(facade.invoiceNotes).not.toHaveBeenCalled();
    body('add-to-draft-d1')!.click();
    await settle();

    expect(facade.invoiceNotes).toHaveBeenCalledExactlyOnceWith('c1', ['a', 'b'], 'd1');
    expect(navigate).toHaveBeenCalledExactlyOnceWith(['/invoices', 'i1']);
  });

  it('invoices nothing when the person leaves the question of where the notes go', async () => {
    check = checkOf(row('a'));
    drafts = [{ id: 'd1', total: '12.000', lineCount: 2, customerReference: null }];
    const fixture = create(['a']);

    await invoice(fixture);
    body('add-to-close')!.click();
    await settle();

    expect(facade.invoiceNotes).not.toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });

  it('says the selection could not be checked, and invoices nothing, when the API fails', async () => {
    check = new Error('network');
    const fixture = create(['a']);

    await invoice(fixture);

    expect(facade.invoiceNotes).not.toHaveBeenCalled();
    expect(said()).toEqual(['delivery_notes.bulk.check_failed']);
  });

  it('says why the invoice was refused, by the code the API answered, and stays on the list', async () => {
    check = checkOf(row('a'));
    invoiced = new DeliveryNotesRefused('conflict');
    const fixture = create(['a']);

    await invoice(fixture);

    expect(said()).toEqual(['delivery_notes.errors.conflict']);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('runs once while it is at work, however often the button is pressed', async () => {
    check = checkOf(row('a'));
    const fixture = create(['a']);
    const button = fixture.nativeElement.querySelector(
      '[data-testid="delivery-notes-bulk-invoice"]',
    ) as HTMLButtonElement;

    button.click();
    fixture.detectChanges();
    expect(button.disabled).toBe(true);
    button.click();
    await settle();

    expect(facade.invoicingCheck).toHaveBeenCalledTimes(1);
  });
});
