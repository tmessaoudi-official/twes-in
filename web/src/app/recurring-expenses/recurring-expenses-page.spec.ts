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
import { expensesTabs } from '../expenses/expenses-nav';
import { PluralCompiler } from '../shared/i18n/plural-compiler';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { effectToasts, provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { RecurringExpenseRefused, RecurringExpensesApi } from './recurring-expenses-api';
import { recurringExpenseFailure } from './recurring-expense-form';
import { RecurringExpensesPage } from './recurring-expenses-page';
import type { RecurringExpenseRow } from './recurring-expenses-types';

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
      expenses: {
        recurring: {
          states: { active: 'Active', paused: 'En pause', ended: 'Terminée' },
          errors: { not_found: 'Cette dépense récurrente n’existe plus.' },
          delete_message: 'Plus aucun brouillon ne sera préparé pour {{description}}.',
          variable: 'Montant variable',
        },
      },
    });
  }
}

const rent: RecurringExpenseRow = {
  id: 'r1',
  modelExpenseId: 'e1',
  description: 'Loyer du local',
  payee: 'SCI Les Halles',
  amountGross: '1200.000',
  currency: 'TND',
  schedule: {
    unit: 'month',
    every: 1,
    anchor: 'last_weekday',
    monthDay: null,
    ordinal: null,
    weekday: 5,
  },
  startsOn: '2026-10-30',
  endsOn: null,
  variableAmount: false,
  paused: false,
  nextOn: '2026-11-27',
  drafted: 1,
  lastExpenseId: 'e9',
};

const power: RecurringExpenseRow = {
  ...rent,
  id: 'r2',
  description: 'Électricité',
  payee: 'STEG',
  amountGross: '180.500',
  schedule: { unit: 'month', every: 2, anchor: 'date', monthDay: 10, ordinal: null, weekday: null },
  variableAmount: true,
  nextOn: null,
  drafted: 4,
  lastExpenseId: null,
};

describe('RecurringExpensesPage', () => {
  const api = { list: vi.fn(), revise: vi.fn(), delete: vi.fn() };
  const live = { reloadOn: vi.fn() };
  const auth = {
    me: () => ({
      user: { id: 'u1' },
      company: { id: 'c1', name: 'Acme', timezone: 'Africa/Tunis' },
    }),
    hasPermission: vi.fn(),
    hasModule: vi.fn(),
  };
  let fixture: ComponentFixture<RecurringExpensesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const anywhere = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(RecurringExpensesPage);
    await settle();
    await Promise.resolve();
    await settle();
  }

  /** A row's action, a button when it shows as one, else from its « ⋮ ». */
  async function rowAction(id: string, rowId: string): Promise<void> {
    const button = q(`row-action-${id}-${rowId}`);
    if (button !== null) {
      button.click();
    } else {
      q(`row-more-${rowId}`)!.click();
      await settle();
      anywhere(`row-menu-${id}-${rowId}`)!.click();
    }
    await settle();
  }

  beforeEach(() => {
    api.list.mockReset().mockResolvedValue([rent, power]);
    api.revise
      .mockReset()
      .mockImplementation((_c: string, _id: string, revision: object) =>
        Promise.resolve({ ...rent, ...revision }),
      );
    api.delete.mockReset().mockResolvedValue(undefined);
    live.reloadOn.mockReset();
    auth.hasPermission.mockReset().mockReturnValue(true);
    auth.hasModule.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [RecurringExpensesPage],
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
        { provide: RecurringExpensesApi, useValue: api },
        { provide: LiveChanges, useValue: live },
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

  it('lists each with what it copies, who is paid, how often in words, the next draft and where it stands', async () => {
    await open();

    expect(api.list).toHaveBeenCalledWith('c1');
    const row = q('recurring-expense-r1')?.textContent ?? '';
    expect(row).toContain('Loyer du local');
    expect(row).toContain('SCI Les Halles');
    expect(row).toContain('Chaque mois, le dernier vendredi');
    expect(row).toContain('Active');
    const other = q('recurring-expense-r2')?.textContent ?? '';
    expect(other).toContain('Tous les 2 mois, le 10');
    expect(other).toContain('Montant variable');
    expect(other).toContain('Terminée');
    // It reads again when another tab or member changes one, or a draft it made.
    expect(live.reloadOn).toHaveBeenCalledWith(
      ['recurring_expense', 'expense'],
      expect.any(Function),
      expect.anything(),
    );
  });

  it('sits between the expenses and their categories as a tab of Dépenses', async () => {
    await open();

    expect(q('expenses-tab')?.getAttribute('href')).toBe('/expenses');
    expect(q('recurring-expenses-tab')?.getAttribute('href')).toBe('/expenses/recurring');
    expect(q('expense-categories-link')?.getAttribute('href')).toBe('/expenses/categories');
    // Without the module, the expenses keep their two tabs alone.
    expect(expensesTabs(false).map((tab) => tab.testId)).toEqual([
      'expenses-tab',
      'expense-categories-link',
    ]);
  });

  it('pauses one, keeping its schedule, last day and amount kind, and says so', async () => {
    await open();

    await rowAction('pause', 'r1');

    expect(api.revise).toHaveBeenCalledWith('c1', 'r1', {
      schedule: rent.schedule,
      endsOn: null,
      variableAmount: false,
      paused: true,
    });
    await vi.waitFor(() => expect(successToasts()).toEqual(['expenses.recurring.paused']));
    expect(q('recurring-expense-r1')?.textContent).toContain('En pause');
    // One that is over has nothing left to pause, nor to revise.
    expect(q('row-action-pause-r2')).toBeNull();
    expect(q('row-action-revise-r2')).toBeNull();
  });

  it('revises one through the dialog, its pause kept as it is', async () => {
    await open();

    await rowAction('revise', 'r1');
    const every = anywhere('field-every') as HTMLInputElement;
    every.value = '3';
    every.dispatchEvent(new Event('input'));
    anywhere('recurring-expense-dialog-confirm')!.click();
    await settle();

    await vi.waitFor(() =>
      expect(api.revise).toHaveBeenCalledWith('c1', 'r1', {
        schedule: { ...rent.schedule, every: 3 },
        endsOn: null,
        variableAmount: false,
        paused: false,
      }),
    );
    await vi.waitFor(() => expect(successToasts()).toEqual(['expenses.recurring.revised']));
  });

  it('deletes one only once asked, naming it, as a definitive action', async () => {
    await open();

    await rowAction('delete', 'r1');
    expect(anywhere('confirm-message')?.textContent).toContain('Loyer du local');
    expect(api.delete).not.toHaveBeenCalled();
    anywhere('confirm-run')!.click();
    await settle();

    expect(api.delete).toHaveBeenCalledWith('c1', 'r1');
    await vi.waitFor(() =>
      expect(effectToasts()).toEqual(['expenses.recurring.deleted:definitif']),
    );
    await settle();
    expect(q('recurring-expense-r1')).toBeNull();
  });

  it('says what the API refused', async () => {
    api.revise.mockRejectedValue(new RecurringExpenseRefused('not_found'));
    await open();

    await rowAction('pause', 'r1');

    await vi.waitFor(() =>
      expect(q('recurring-expenses-error')?.textContent).toContain('n’existe plus'),
    );
  });

  it('names a refused field in its own words, and the rest in the general line', () => {
    expect(recurringExpenseFailure('monthDay')).toBe('expenses.recurring.errors.monthDay');
    expect(recurringExpenseFailure('unit')).toBe('expenses.recurring.errors.invalid');
    expect(recurringExpenseFailure(null)).toBe('expenses.recurring.errors.invalid');
  });

  it('offers a reader no change', async () => {
    auth.hasPermission.mockReturnValue(false);
    await open();

    expect(q('row-action-pause-r1')).toBeNull();
    expect(q('row-action-revise-r1')).toBeNull();
    expect(q('row-more-r1')?.textContent ?? '').not.toContain('delete');
  });
});
