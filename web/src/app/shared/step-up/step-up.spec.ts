// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { of } from 'rxjs';
import { StepUp } from './step-up';

class Asked extends Error {}

// docs/SPEC.md § 7, the step-up list: a change the API refuses until the person proves who they are again.
describe('StepUp.retrying', () => {
  let answer: boolean | undefined;
  let intros: string[];

  function stepUp(): StepUp {
    intros = [];
    TestBed.configureTestingModule({
      providers: [
        {
          provide: MatDialog,
          useValue: {
            open: (_component: unknown, config: { data: { intro: string } }) => {
              intros.push(config.data.intro);
              return { afterClosed: () => of(answer) };
            },
          },
        },
      ],
    });
    return TestBed.inject(StepUp);
  }

  const asked = (error: unknown) => error instanceof Asked;

  it('runs the change once and asks nothing when the API takes it', async () => {
    const step = stepUp();
    const call = vi.fn(async () => 'saved');

    expect(await step.retrying(call, asked, 'step_up.intro_sensitive')).toBe('saved');
    expect([call.mock.calls.length, intros]).toEqual([1, []]);
  });

  it('asks for the proof when the API wants one, then runs the change again', async () => {
    answer = true;
    const step = stepUp();
    const call = vi
      .fn<() => Promise<string>>()
      .mockRejectedValueOnce(new Asked())
      .mockResolvedValueOnce('saved');

    expect(await step.retrying(call, asked, 'step_up.intro_sensitive')).toBe('saved');
    expect([call.mock.calls.length, intros]).toEqual([2, ['step_up.intro_sensitive']]);
  });

  it('lets the refusal through, without trying again, when the person does not give the proof', async () => {
    answer = false;
    const step = stepUp();
    const refusal = new Asked();
    const call = vi.fn(async () => {
      throw refusal;
    });

    await expect(step.retrying(call, asked, 'step_up.intro_sensitive')).rejects.toBe(refusal);
    expect(call).toHaveBeenCalledTimes(1);
  });

  it('asks nothing for any other refusal', async () => {
    const step = stepUp();
    const other = new Error('invalid');

    await expect(
      step.retrying(
        async () => {
          throw other;
        },
        asked,
        'step_up.intro_sensitive',
      ),
    ).rejects.toBe(other);
    expect(intros).toEqual([]);
  });
});
