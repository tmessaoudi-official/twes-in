// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { StepUp } from '../shared/step-up/step-up';
import { RecordedFeedback } from '../shared/testing/feedback';
import { CompanyClosingActions } from './company-closing-actions';
import { CompanyClosingApi, CompanyClosingRefused } from './company-closing-api';

describe('CompanyClosingActions', () => {
  const company = { id: 'c1', name: 'Quincaillerie Ben Salah' };
  const terms = { retainUntil: '2036-12-31', reopenUntil: '2026-11-02T10:00:00+01:00' };
  const api = { closeDown: vi.fn(), reopen: vi.fn() };
  const auth = { refresh: vi.fn(async () => undefined) };
  let answer: boolean | undefined;
  const dialog = { open: vi.fn(() => ({ afterClosed: () => of(answer) })) };
  /** The proof given or not when the API asks for it, as `StepUp.retrying` does. */
  let proves = true;
  const stepUp = {
    retrying: vi.fn(
      async <T>(call: () => Promise<T>, asked: (error: unknown) => boolean): Promise<T> => {
        try {
          return await call();
        } catch (error) {
          if (!asked(error) || !proves) throw error;
          return call();
        }
      },
    ),
  };
  let actions: CompanyClosingActions;
  let feedback: RecordedFeedback;
  let navigate: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    vi.clearAllMocks();
    answer = true;
    proves = true;
    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        { provide: CompanyClosingApi, useValue: api },
        { provide: AuthFacade, useValue: auth },
        { provide: MatDialog, useValue: dialog },
        { provide: StepUp, useValue: stepUp },
        { provide: Feedback, useClass: RecordedFeedback },
        {
          provide: FormatFacade,
          useValue: { day: (v: string) => `day ${v}`, moment: (v: string) => `moment ${v}` },
        },
      ],
    });
    actions = TestBed.inject(CompanyClosingActions);
    feedback = TestBed.inject(Feedback) as RecordedFeedback;
    navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
  });

  it('asks « Définitif à terme » with the company’s name to type, and closes nothing when kept', async () => {
    answer = false;

    expect(await actions.close(company)).toBe(false);

    const asked = dialog.open.mock.calls[0] as unknown as [
      unknown,
      { data: Record<string, unknown> },
    ];
    expect(asked[1].data).toMatchObject({
      kind: 'definitif_a_terme',
      typed: { name: 'Quincaillerie Ben Salah' },
      messageParams: { company: 'Quincaillerie Ben Salah' },
    });
    expect(api.closeDown).not.toHaveBeenCalled();
  });

  it('closes it once proved, follows the session where the person is, and says both dates', async () => {
    api.closeDown
      .mockRejectedValueOnce(new CompanyClosingRefused('step_up_required'))
      .mockResolvedValueOnce(terms);

    expect(await actions.close(company)).toBe(true);

    expect(api.closeDown).toHaveBeenCalledTimes(2);
    expect(auth.refresh).toHaveBeenCalled();
    // Still read in the shell: the person stays on their page, and the shell's notice says the closing.
    expect(navigate).not.toHaveBeenCalled();
    const said = feedback.said.at(-1);
    expect(said?.key).toBe('company.closing.done');
    expect(said?.params).toEqual({
      company: 'Quincaillerie Ben Salah',
      reopen: `moment ${terms.reopenUntil}`,
      retain: `day ${terms.retainUntil}`,
    });
    expect(said?.effect).toBe('definitif_a_terme');
  });

  it('offers « Rouvrir » in its toast, which takes the closing back', async () => {
    api.closeDown.mockResolvedValue(terms);
    api.reopen.mockResolvedValue(undefined);
    await actions.close(company);

    feedback.said.at(-1)?.action?.run();
    await vi.waitFor(() => expect(api.reopen).toHaveBeenCalledWith('c1'));
    await vi.waitFor(() => expect(feedback.said.at(-1)?.key).toBe('company.closing.reopened'));
  });

  it('says nothing more when the person gives up the proof: its dialog was the answer', async () => {
    proves = false;
    api.closeDown.mockRejectedValue(new CompanyClosingRefused('step_up_required'));

    expect(await actions.close(company)).toBe(false);

    expect(feedback.said.filter((each) => each.kind === 'failure')).toEqual([]);
    expect(navigate).not.toHaveBeenCalled();
  });

  it.each([
    ['owner_only', 'company.closing.owner_only'],
    ['refused', 'company.closing.failed'],
  ] as const)('says why it was kept open (%s)', async (code, key) => {
    api.closeDown.mockRejectedValue(new CompanyClosingRefused(code));

    expect(await actions.close(company)).toBe(false);

    expect(feedback.said.filter((each) => each.kind === 'failure').map((each) => each.key)).toEqual(
      [key],
    );
  });

  it('reopens, reads the session again and says so; a reopening too late says the closing is final', async () => {
    api.reopen.mockResolvedValueOnce(undefined);
    expect(await actions.reopen(company)).toBe(true);
    expect(auth.refresh).toHaveBeenCalled();
    expect(feedback.said.at(-1)?.key).toBe('company.closing.reopened');

    api.reopen.mockRejectedValueOnce(new CompanyClosingRefused('reopen_period_over'));
    expect(await actions.reopen(company)).toBe(false);
    expect(feedback.said.filter((each) => each.kind === 'failure').map((each) => each.key)).toEqual(
      ['company.closing.reopen_period_over'],
    );
  });
});
