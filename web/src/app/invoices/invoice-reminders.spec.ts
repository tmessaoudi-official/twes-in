// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { InvoiceReminders } from './invoice-reminders';
import { InvoicesRefused } from './invoices-api';
import { RemindersApi } from './reminders-api';
import type { InvoiceReminderRow } from './invoices-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const first: InvoiceReminderRow = {
  id: 'r1',
  stage: 1,
  daysLate: 8,
  reachedOn: '2026-10-01',
  lateFeeInvoiceId: null,
};
const second: InvoiceReminderRow = {
  id: 'r2',
  stage: 2,
  daysLate: 16,
  reachedOn: '2026-10-09',
  lateFeeInvoiceId: 'fee1',
};

describe('InvoiceReminders', () => {
  let fixture: ComponentFixture<InvoiceReminders>;
  const api = { list: vi.fn<() => Promise<InvoiceReminderRow[]>>() };

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function show(invoiceId = 'f1'): Promise<void> {
    fixture.componentRef.setInput('invoiceId', invoiceId);
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.list.mockReset();
    TestBed.configureTestingModule({
      imports: [InvoiceReminders],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: RemindersApi, useValue: api },
        { provide: AuthFacade, useValue: { me: () => null } },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(InvoiceReminders);
    fixture.componentRef.setInput('companyId', 'c1');
  });

  it('shows nothing for an invoice that reached no stage', async () => {
    api.list.mockResolvedValue([]);
    await show();

    expect(api.list).toHaveBeenCalledWith('c1', 'f1');
    expect(q('invoice-reminders')).toBeNull();
  });

  it('lists each stage reached, and leads to the late fee a stage drafted', async () => {
    api.list.mockResolvedValue([first, second]);
    await show();

    expect(q('invoice-reminders')).not.toBeNull();
    expect(q('invoice-reminder-1')?.textContent).toContain('invoices.reminders.stage');
    expect(q('invoice-reminder-1-fee')).toBeNull();
    const fee = q('invoice-reminder-2-fee');
    expect(fee?.getAttribute('href')).toBe('/invoices/fee1');
    expect(q('invoice-reminders-note')?.textContent).toContain('invoices.reminders.note');
  });

  it('reads again for another invoice, and says when it could not read', async () => {
    api.list.mockResolvedValue([first]);
    await show('f1');
    api.list.mockRejectedValue(new InvoicesRefused('network'));
    await show('f2');

    expect(api.list).toHaveBeenLastCalledWith('c1', 'f2');
    expect(q('invoice-reminder-1')).toBeNull();
    expect(q('invoice-reminders-error')).not.toBeNull();
  });
});
