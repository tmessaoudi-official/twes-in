// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { CompanyFacade } from '../company/company-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { StepUp } from '../shared/step-up/step-up';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { refusedByTheDemo } from '../shared/testing/said-answers';
import { AccountDeletion } from './account-deletion';
import { AccountDeletionApi, AccountDeletionRefused } from './account-deletion-api';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

describe('AccountDeletion', () => {
  const api = { delete: vi.fn() };
  const stepUp = { request: vi.fn() };
  const dialog = { open: vi.fn() };
  const auth = { sessionEnded: vi.fn(), isPlatformOperator: vi.fn() };
  const companies = { switchTo: vi.fn() };
  const router = { navigateByUrl: vi.fn() };
  let answer: boolean;

  beforeEach(async () => {
    answer = true;
    api.delete.mockReset().mockResolvedValue('2026-10-20T15:00:00+00:00');
    stepUp.request.mockReset().mockResolvedValue(true);
    dialog.open.mockReset().mockImplementation(() => ({ afterClosed: () => of(answer) }));
    auth.sessionEnded.mockReset();
    auth.isPlatformOperator.mockReset().mockReturnValue(false);
    companies.switchTo.mockReset().mockResolvedValue(true);
    router.navigateByUrl.mockReset().mockResolvedValue(true);
    await TestBed.configureTestingModule({
      imports: [AccountDeletion],
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        { provide: AccountDeletionApi, useValue: api },
        { provide: StepUp, useValue: stepUp },
        { provide: MatDialog, useValue: dialog },
        { provide: AuthFacade, useValue: auth },
        { provide: CompanyFacade, useValue: companies },
        { provide: Router, useValue: router },
        { provide: FormatFacade, useValue: { day: (value: string) => `day(${value})` } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  function render() {
    const fixture = TestBed.createComponent(AccountDeletion);
    fixture.detectChanges();
    return fixture;
  }

  async function press(fixture: ReturnType<typeof render>, testId: string): Promise<void> {
    (fixture.nativeElement as HTMLElement)
      .querySelector<HTMLButtonElement>(`[data-testid="${testId}"]`)!
      .click();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  function said(): RecordedFeedback['said'] {
    return (TestBed.inject(Feedback) as RecordedFeedback).said;
  }

  it('asks first, says it is final after a delay, asks the word typed, proves who is there, then ends the session and says until when', async () => {
    const fixture = render();
    await press(fixture, 'account-delete');

    expect(dialog.open.mock.calls[0]![1]).toMatchObject({
      data: { kind: 'definitif_a_terme', typed: { word: 'account.deletion.typed_word' } },
      autoFocus: '[data-testid="confirm-typed"]',
    });
    expect(stepUp.request).toHaveBeenCalledWith('account.deletion.step_up');
    expect(api.delete).toHaveBeenCalledOnce();
    expect(auth.sessionEnded).toHaveBeenCalledOnce();
    expect(router.navigateByUrl).toHaveBeenCalledWith('/login');
    expect(said()).toEqual([
      {
        kind: 'success',
        key: 'account.deletion.done',
        params: { day: 'day(2026-10-20T15:00:00+00:00)' },
        effect: 'definitif_a_terme',
      },
    ]);
  });

  it('does nothing when the question is answered « Garder »', async () => {
    answer = false;
    await press(render(), 'account-delete');

    expect(stepUp.request).not.toHaveBeenCalled();
    expect(api.delete).not.toHaveBeenCalled();
  });

  it('does nothing when the proof is given up', async () => {
    stepUp.request.mockResolvedValue(false);
    await press(render(), 'account-delete');

    expect(api.delete).not.toHaveBeenCalled();
    expect(auth.sessionEnded).not.toHaveBeenCalled();
  });

  it('asks for the proof again once when the API found it too old, then deletes', async () => {
    api.delete
      .mockRejectedValueOnce(new AccountDeletionRefused('step_up_required'))
      .mockResolvedValueOnce('2026-10-20T15:00:00+00:00');
    await press(render(), 'account-delete');

    expect(stepUp.request).toHaveBeenCalledTimes(2);
    expect(api.delete).toHaveBeenCalledTimes(2);
    expect(auth.sessionEnded).toHaveBeenCalledOnce();
  });

  it('names each company a sole owner keeps, and leads to handing it over or closing it there', async () => {
    api.delete.mockRejectedValue(
      new AccountDeletionRefused('sole_owner', [
        { id: 'c1', name: 'Atelier' },
        { id: 'c2', name: 'Boutique' },
      ]),
    );
    const fixture = render();
    await press(fixture, 'account-delete');

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('[data-testid="account-delete-sole-owner"]')).not.toBeNull();
    expect(
      element.querySelector('[data-testid="account-delete-company-0"]')?.textContent,
    ).toContain('Atelier');
    expect(
      element.querySelector('[data-testid="account-delete-company-1"]')?.textContent,
    ).toContain('Boutique');
    expect(auth.sessionEnded).not.toHaveBeenCalled();

    await press(fixture, 'account-delete-company-1-hand-over');
    expect(companies.switchTo).toHaveBeenLastCalledWith('c2');
    expect(router.navigateByUrl).toHaveBeenLastCalledWith('/members');

    await press(fixture, 'account-delete-company-0-close');
    expect(companies.switchTo).toHaveBeenLastCalledWith('c1');
    expect(router.navigateByUrl).toHaveBeenLastCalledWith('/company/profile');
  });

  it('stays where it is when switching to the company is given up', async () => {
    api.delete.mockRejectedValue(
      new AccountDeletionRefused('sole_owner', [{ id: 'c1', name: 'A' }]),
    );
    companies.switchTo.mockResolvedValue(false);
    const fixture = render();
    await press(fixture, 'account-delete');
    await press(fixture, 'account-delete-company-0-close');

    expect(router.navigateByUrl).not.toHaveBeenCalled();
  });

  // § 7 2026-10-13 16:43: the button that deletes is drawn as danger, whatever else the page shows.
  it('draws its button in the danger colour', () => {
    const button = (render().nativeElement as HTMLElement).querySelector(
      '[data-testid="account-delete"]',
    );
    expect(button?.classList).toContain('twes-danger');
  });

  // Lens 1: told before the question and the proof, not after them.
  it('tells an operator at once, offering no button that the API would refuse', () => {
    auth.isPlatformOperator.mockReturnValue(true);
    const element = render().nativeElement as HTMLElement;

    expect(element.querySelector('[data-testid="account-delete-operator"]')).not.toBeNull();
    expect(element.querySelector('[data-testid="account-delete"]')).toBeNull();
  });

  it('tells an operator their account is not deleted from here', async () => {
    api.delete.mockRejectedValue(new AccountDeletionRefused('platform_operator'));
    const fixture = render();
    await press(fixture, 'account-delete');

    expect(
      (fixture.nativeElement as HTMLElement).querySelector(
        '[data-testid="account-delete-operator"]',
      ),
    ).not.toBeNull();
    expect(said()).toEqual([]);
  });

  it('says it failed, keeping the session, for any other refusal', async () => {
    api.delete.mockRejectedValue(new AccountDeletionRefused('network'));
    await press(render(), 'account-delete');

    expect(said()).toEqual([
      { kind: 'failure', key: 'account.deletion.failed', params: undefined },
    ]);
    expect(auth.sessionEnded).not.toHaveBeenCalled();
  });

  it('says nothing more when the demo interceptor has already said the refusal', async () => {
    api.delete.mockRejectedValue(refusedByTheDemo(new AccountDeletionRefused('network')));
    await press(render(), 'account-delete');

    expect(said()).toEqual([]);
    expect(auth.sessionEnded).not.toHaveBeenCalled();
  });
});
