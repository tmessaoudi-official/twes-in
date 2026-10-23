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
import { LanguageFacade } from '../shared/i18n/language-facade';
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
import { RecurringExpenseRefused, RecurringExpensesApi } from './recurring-expenses-api';
import { recurringExpenseFailure } from './recurring-expense-form';
import { RecurringExpensesPage } from './recurring-expenses-page';
import type { RecurringExpenseRow } from './recurring-expenses-types';
import { applyFilters } from '../shared/list/list-view';

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
  version: 3,
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
  const api = { list: vi.fn(), revise: vi.fn() };
  const deletes = { remove: vi.fn() };
  const live = { reloadOn: vi.fn() };
  let company: { id: string; name: string; timezone: string; countryCode?: string } = {
    id: 'c1',
    name: 'Acme',
    timezone: 'Africa/Tunis',
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company }),
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
    company = { id: 'c1', name: 'Acme', timezone: 'Africa/Tunis' };
    api.list.mockReset().mockResolvedValue([rent, power]);
    api.revise
      .mockReset()
      .mockImplementation((_c: string, _id: string, revision: object) =>
        Promise.resolve({ ...rent, ...revision }),
      );
    deletes.remove.mockReset().mockResolvedValue(true);
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

  it('names a yearly one’s month as the screen writes its dates: in Tunisian Arabic, « جانفي »', async () => {
    company = { ...company, countryCode: 'TN' };
    await TestBed.inject(LanguageFacade).use('ar');
    api.list.mockResolvedValue([
      {
        ...rent,
        schedule: {
          unit: 'year',
          every: 1,
          anchor: 'date',
          monthDay: 5,
          ordinal: null,
          weekday: null,
        },
        startsOn: '2027-01-05',
      },
    ]);
    await open();

    expect(q('recurring-expense-r1')?.textContent ?? '').toContain('جانفي');
  });

  it('narrows by several states at once: « Active » and « En pause » together', async () => {
    await open();
    const filters = fixture.componentInstance['list']().filters ?? [];
    const held: RecurringExpenseRow = { ...rent, id: 'r3', paused: true };

    expect(filters.map((filter) => [filter.id, filter.multiple])).toEqual([['state', true]]);
    expect(
      applyFilters([rent, power, held], filters, { state: 'active,paused' }).map((row) => row.id),
    ).toEqual(['r1', 'r3']);
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
      version: 3,
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
        version: 3,
      }),
    );
    await vi.waitFor(() => expect(successToasts()).toEqual(['expenses.recurring.revised']));
  });

  describe('when it was saved since it was read', () => {
    const changed = new RecurringExpenseRefused('record_changed');

    function type(testId: string, value: string): void {
      const input = anywhere(testId) as HTMLInputElement;
      input.value = value;
      input.dispatchEvent(new Event('input'));
    }

    /** A draft made by the run moves the version: the pause is refused and the list read again. */
    it('reads the list again and says nothing was saved when a pause is refused', async () => {
      api.revise.mockRejectedValueOnce(changed);
      await open();
      api.list.mockClear().mockResolvedValue([{ ...rent, version: 4, drafted: 2 }, power]);

      await rowAction('pause', 'r1');

      await vi.waitFor(() => expect(failureToasts()).toEqual(['live.saved_since']));
      expect(api.list).toHaveBeenCalledWith('c1');
      expect(q('recurring-expenses-error')).toBeNull();
      expect(successToasts()).toEqual([]);
    });

    it('asks again over the version saved, keeping what was typed and taking what they changed', async () => {
      api.revise.mockRejectedValueOnce(changed);
      await open();
      api.list.mockResolvedValue([
        { ...rent, version: 4, endsOn: '2027-06-30', paused: true },
        power,
      ]);

      await rowAction('revise', 'r1');
      type('field-every', '3');
      anywhere('recurring-expense-dialog-confirm')!.click();
      await settle();

      await vi.waitFor(() => expect(failureToasts()).toEqual(['live.saved_since']));
      await vi.waitFor(() => expect((anywhere('field-every') as HTMLInputElement).value).toBe('3'));
      expect(anywhere('field-conflict-every')).toBeNull();
      anywhere('recurring-expense-dialog-confirm')!.click();
      await settle();

      await vi.waitFor(() =>
        expect(api.revise).toHaveBeenLastCalledWith('c1', 'r1', {
          schedule: { ...rent.schedule, every: 3 },
          endsOn: '2027-06-30',
          variableAmount: false,
          paused: true,
          version: 4,
        }),
      );
      await vi.waitFor(() => expect(successToasts()).toEqual(['expenses.recurring.revised']));
    });

    it('offers theirs where both changed the same field', async () => {
      api.revise.mockRejectedValueOnce(changed);
      await open();
      api.list.mockResolvedValue([
        { ...rent, version: 4, schedule: { ...rent.schedule, every: 2 } },
        power,
      ]);

      await rowAction('revise', 'r1');
      type('field-every', '3');
      anywhere('recurring-expense-dialog-confirm')!.click();
      await settle();

      await vi.waitFor(() => expect(anywhere('field-conflict-every')).not.toBeNull());
      expect((anywhere('field-every') as HTMLInputElement).value).toBe('3');
      anywhere('field-take-theirs-every')!.click();
      await settle();
      expect((anywhere('field-every') as HTMLInputElement).value).toBe('2');
      expect(anywhere('field-conflict-every')).toBeNull();
      anywhere('recurring-expense-dialog-confirm')!.click();
      await settle();

      await vi.waitFor(() =>
        expect(api.revise).toHaveBeenLastCalledWith(
          'c1',
          'r1',
          expect.objectContaining({ schedule: { ...rent.schedule, every: 2 }, version: 4 }),
        ),
      );
    });
  });

  it('deletes one asking nothing, named by its description, as a record the toast takes back', async () => {
    await open();

    await rowAction('delete', 'r1');
    await settle();

    expect(anywhere('confirm-run')).toBeNull();
    expect(deletes.remove).toHaveBeenCalledWith('c1', 'recurring_expense', [
      { id: 'r1', label: 'Loyer du local' },
    ]);
    expect(q('recurring-expense-r1')).toBeNull();
    expect(live.reloadOn.mock.calls[0]?.[0]).toContain('recurring_expense');
  });

  it('keeps the row when the delete was refused', async () => {
    deletes.remove.mockResolvedValue(false);
    await open();

    await rowAction('delete', 'r1');
    await settle();

    expect(q('recurring-expense-r1')).not.toBeNull();
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
    expect(recurringExpenseFailure('monthDay')).toBe('schedule.errors.monthDay');
    expect(recurringExpenseFailure('startsOn')).toBe('expenses.recurring.errors.startsOn');
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
