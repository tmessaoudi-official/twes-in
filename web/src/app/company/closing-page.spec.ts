// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { ActionConfirm } from '../shared/actions/screen-action';
import { todayIn } from '../shared/i18n/format';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { effectToasts, provideQuietFeedback } from '../shared/testing/feedback';
import { ClosingApi, ClosingRefused, type CompanyClosing } from './closing-api';
import { ClosingPage } from './closing-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

describe('ClosingPage', () => {
  let fixture: ComponentFixture<ClosingPage>;
  let answer = true;
  const asked: ActionConfirm[] = [];
  const api = {
    read: vi.fn<() => Promise<CompanyClosing>>(),
    closeThrough: vi.fn<(companyId: string, day: string) => Promise<CompanyClosing>>(),
  };
  const dialog = {
    open: vi.fn((_component: unknown, config: { data: ActionConfirm }) => {
      asked.push(config.data);
      return { afterClosed: () => of(answer) };
    }),
  };

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  async function open(closing: CompanyClosing): Promise<void> {
    api.read.mockResolvedValue(closing);
    fixture = TestBed.createComponent(ClosingPage);
    await settle();
  }

  async function closeThrough(day: string): Promise<void> {
    const input = q('closing-day') as HTMLInputElement;
    input.value = day;
    input.dispatchEvent(new Event('input'));
    (q('closing-close') as HTMLButtonElement).click();
    await settle();
  }

  beforeEach(() => {
    answer = true;
    asked.length = 0;
    api.read.mockReset();
    api.closeThrough.mockReset();
    dialog.open.mockClear();
    TestBed.configureTestingModule({
      imports: [ClosingPage],
      providers: [
        ...provideQuietFeedback(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ClosingApi, useValue: api },
        { provide: MatDialog, useValue: dialog },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1', timezone: 'Africa/Tunis' } }),
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('says nothing is closed, and offers closing through yesterday at the latest', async () => {
    await open({ closedThrough: null, writable: true });

    expect(api.read).toHaveBeenCalledWith('c1');
    expect(q('closing-state')?.textContent).toContain('company.closing.none');
    const input = q('closing-day') as HTMLInputElement;
    expect(input.max).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(input.max < todayIn('Africa/Tunis')).toBe(true);
  });

  it('asks before closing, says it is final, and shows the day closed', async () => {
    await open({ closedThrough: '2026-08-31', writable: true });
    api.closeThrough.mockResolvedValue({ closedThrough: '2026-09-30', writable: true });

    await closeThrough('2026-09-30');

    expect(asked.map((confirm) => confirm.kind)).toEqual(['definitif']);
    expect(api.closeThrough).toHaveBeenCalledWith('c1', '2026-09-30');
    expect(q('closing-state')?.textContent).toContain('company.closing.closed_through');
    expect(effectToasts()).toEqual(['company.closing.closed:definitif']);
  });

  it('closes nothing when the question is answered no', async () => {
    await open({ closedThrough: null, writable: true });
    answer = false;

    await closeThrough('2026-09-30');

    expect(api.closeThrough).not.toHaveBeenCalled();
  });

  it('says why a day was refused', async () => {
    await open({ closedThrough: '2026-09-30', writable: true });
    api.closeThrough.mockRejectedValue(new ClosingRefused('invalid'));

    await closeThrough('2026-09-15');

    expect(q('closing-error')?.textContent).toContain('company.closing.errors.invalid');
  });

  it('shows the closed day to whoever may not close further, without the form', async () => {
    await open({ closedThrough: '2026-09-30', writable: false });

    expect(q('closing-state')).not.toBeNull();
    expect(q('closing-day')).toBeNull();
  });
});
