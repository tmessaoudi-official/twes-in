// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateCompiler,
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { PluralCompiler } from '../shared/i18n/plural-compiler';
import { LiveChanges } from '../shared/realtime/live-changes';
import { RecordDeletes } from '../shared/records/record-deletes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { failureToasts, provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { RecurringApi, RecurringRefused } from './recurring-api';
import { RecurringPage } from './recurring-page';
import type { RecurringInvoiceRow } from './recurring-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      schedule: {
        every: {
          month: '{every, plural, =1 {Chaque mois} other {Tous les {{every}} mois}}',
          year: '{every, plural, =1 {Chaque année} other {Tous les {{every}} ans}}',
        },
        on: {
          last_weekday: '{{every}}, le dernier {{weekday}}',
          date: '{{every}}, le {{day}}',
        },
        in_month: '{{on}}, en {{month}}',
      },
      recurring: {
        states: { active: 'Active', paused: 'En pause', ended: 'Terminée' },
        errors: { not_found: 'Cette facture récurrente n’existe plus.' },
      },
    });
  }
}

const rent: RecurringInvoiceRow = {
  id: 'r1',
  modelInvoiceId: 'i1',
  modelNumber: 'F-2026-0007',
  customerName: 'Atelier Ben Salah',
  schedule: {
    unit: 'month',
    every: 2,
    anchor: 'last_weekday',
    monthDay: null,
    ordinal: null,
    weekday: 5,
  },
  startsOn: '2026-10-31',
  endsOn: null,
  paused: false,
  nextOn: '2026-11-30',
  drafted: 1,
  lastInvoiceId: 'i9',
  version: 3,
};

const over: RecurringInvoiceRow = {
  ...rent,
  id: 'r2',
  customerName: 'Café du Port',
  schedule: { unit: 'year', every: 1, anchor: 'date', monthDay: 31, ordinal: null, weekday: null },
  nextOn: null,
  drafted: 2,
  lastInvoiceId: null,
};

describe('RecurringPage', () => {
  const api = { list: vi.fn(), revise: vi.fn() };
  const deletes = { remove: vi.fn() };
  const live = { reloadOn: vi.fn() };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
    hasModule: () => true,
  };
  let fixture: ComponentFixture<RecurringPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const inMenu = (testId: string): HTMLElement | null =>
    document.body.querySelector(
      `.cdk-overlay-container [data-testid="${testId}"]`,
    ) as HTMLElement | null;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(RecurringPage);
    await settle();
    await Promise.resolve();
    await settle();
  }

  beforeEach(() => {
    api.list.mockReset().mockResolvedValue([rent, over]);
    api.revise.mockReset().mockResolvedValue({ ...rent, paused: true });
    deletes.remove.mockReset().mockResolvedValue(true);
    live.reloadOn.mockReset();
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [RecurringPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
          compiler: provideTranslateCompiler(PluralCompiler),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: RecurringApi, useValue: api },
        { provide: LiveChanges, useValue: live },
        { provide: RecordDeletes, useValue: deletes },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('lists each with the invoice it copies, when in words, the next draft and where it stands', async () => {
    await open();

    expect(api.list).toHaveBeenCalledWith('c1');
    const row = q('recurring-r1')?.textContent ?? '';
    expect(row).toContain('Atelier Ben Salah');
    expect(row).toContain('F-2026-0007');
    expect(row).toContain('Tous les 2 mois, le dernier vendredi');
    expect(q('recurring-r2')?.textContent).toContain('Chaque année, le 31, en octobre');
    expect(row).toContain('Active');
    expect(q('recurring-r2')?.textContent).toContain('Terminée');
    // It reads again when another tab or member changes one.
    expect(live.reloadOn).toHaveBeenCalledWith(
      ['recurring_invoice'],
      expect.any(Function),
      expect.anything(),
    );
  });

  it('sits beside the invoices as a tab of Factures', async () => {
    await open();

    expect(q('invoices-tab')?.getAttribute('href')).toBe('/invoices');
    expect(q('recurring-tab')?.getAttribute('href')).toBe('/invoices/recurring');
  });

  it('pauses one, keeping its schedule and last day, and says so', async () => {
    await open();

    q('row-action-pause-r1')!.click();
    await settle();

    expect(api.revise).toHaveBeenCalledWith('c1', 'r1', {
      schedule: rent.schedule,
      endsOn: null,
      paused: true,
      version: 3,
    });
    await vi.waitFor(() => expect(successToasts()).toEqual(['recurring.paused']));
    expect(q('recurring-r1')?.textContent).toContain('En pause');
    // One that is over has nothing left to pause.
    expect(q('row-action-pause-r2')).toBeNull();
  });

  it('deletes one asking nothing, named by its customer, as a record the toast takes back', async () => {
    await open();

    q('row-more-r1')!.click();
    await settle();
    inMenu('row-menu-delete-r1')!.click();
    await settle();

    expect(document.querySelector('[data-testid="confirm-run"]')).toBeNull();
    expect(deletes.remove).toHaveBeenCalledWith('c1', 'recurring_invoice', [
      { id: 'r1', label: 'Atelier Ben Salah' },
    ]);
    await settle();
    expect(q('recurring-r1')).toBeNull();
    expect(live.reloadOn.mock.calls[0]?.[0]).toContain('recurring_invoice');
  });

  it('keeps the row when the delete was refused', async () => {
    deletes.remove.mockResolvedValue(false);
    await open();

    q('row-more-r1')!.click();
    await settle();
    inMenu('row-menu-delete-r1')!.click();
    await settle();

    expect(q('recurring-r1')).not.toBeNull();
  });

  it('says what the API refused', async () => {
    api.revise.mockRejectedValue(new RecurringRefused('not_found'));
    await open();

    q('row-action-pause-r1')!.click();
    await settle();

    await vi.waitFor(() => expect(q('recurring-error')?.textContent).toContain('n’existe plus'));
  });

  /** A draft made by the run since the list was read moves the version: the pause is refused and the list read again. */
  it('reads the list again and says nothing was saved when the pause was refused as made over a version saved since', async () => {
    api.revise.mockRejectedValue(new RecurringRefused('record_changed'));
    await open();
    api.list.mockClear().mockResolvedValue([{ ...rent, version: 4, drafted: 2 }, over]);

    q('row-action-pause-r1')!.click();
    await settle();

    await vi.waitFor(() => expect(failureToasts()).toEqual(['live.saved_since']));
    expect(api.list).toHaveBeenCalledWith('c1');
    expect(q('recurring-error')).toBeNull();
    expect(successToasts()).toEqual([]);
  });

  it('offers a reader no change', async () => {
    auth.hasPermission.mockReturnValue(false);
    await open();

    expect(q('row-action-pause-r1')).toBeNull();
    expect(q('row-more-r1')).toBeNull();
  });
});
