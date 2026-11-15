// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialog } from '@angular/material/dialog';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Feedback } from '../../shared/feedback/feedback';
import { FormatFacade } from '../../shared/i18n/format-facade';
import { StepUp } from '../../shared/step-up/step-up';
import { provideStillAppearance } from '../../shared/testing/appearance';
import { provideQuietFeedback, type RecordedFeedback } from '../../shared/testing/feedback';
import { announceSaved } from '../../shared/testing/live';
import { type MemberDevice, MemberDevicesApi, MemberDevicesRefused } from './member-devices-api';
import { MemberDevicesDialog } from './member-devices-dialog';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      members: {
        devices: {
          title: 'Appareils de {{name}}',
          intro: 'Où {{name}} est connecté.',
          none: '{{name}} n’est connecté nulle part.',
          unknown: 'Appareil inconnu',
          seen: '{{address}} · vu {{when}}',
          end: 'Déconnecter',
          end_all: 'Tout déconnecter',
          close: 'Fermer',
          errors: {
            network: 'Serveur injoignable.',
            owner_only: 'Seul le propriétaire.',
            step_up_required: 'Confirmez que c’est vous.',
          },
        },
      },
    });
  }
}

const FIREFOX = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';
const device = (id: string, agent = FIREFOX): MemberDevice => ({
  id,
  device: agent,
  address: '203.0.113.7',
  createdAt: '2026-11-15T08:00:00+00:00',
  lastSeenAt: '2026-11-15T09:00:00+00:00',
});

describe('MemberDevicesDialog', () => {
  const api = { list: vi.fn(), end: vi.fn(), endAll: vi.fn() };
  /** What the confirmation answers, and whether the owner proves who is at the screen when asked. */
  let confirmed: boolean | undefined;
  let proves: boolean;
  const dialog = { open: vi.fn(() => ({ afterClosed: () => of(confirmed) })), openDialogs: [] };
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

  beforeEach(async () => {
    confirmed = true;
    proves = true;
    dialog.open.mockClear();
    stepUp.retrying.mockClear();
    api.list.mockReset().mockResolvedValue([device('a'), device('b', 'curl/8')]);
    api.end.mockReset().mockResolvedValue(undefined);
    api.endAll.mockReset().mockResolvedValue(2);
    TestBed.configureTestingModule({
      imports: [MemberDevicesDialog],
      providers: [
        provideStillAppearance(),
        provideQuietFeedback(),
        { provide: MemberDevicesApi, useValue: api },
        { provide: StepUp, useValue: stepUp },
        { provide: FormatFacade, useValue: { moment: (value: string) => value } },
        { provide: MAT_DIALOG_DATA, useValue: { companyId: 'c1', userId: 'u2', name: 'Nadia' } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    });
    // The dialog's own module provides MatDialog to it, so the double replaces it everywhere.
    TestBed.overrideProvider(MatDialog, { useValue: dialog });
    await TestBed.compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(MemberDevicesDialog);
    await fixture.whenStable();
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    const press = async (id: string) => {
      query<HTMLButtonElement>(id)!.click();
      await fixture.whenStable();
      fixture.detectChanges();
    };
    return { fixture, query, press };
  }

  const said = () => (TestBed.inject(Feedback) as RecordedFeedback).said;

  it('lists where the member is signed in to this company, each by browser and address', async () => {
    const { query } = await render();

    expect(api.list).toHaveBeenCalledWith('c1', 'u2');
    expect(query('member-devices-title')?.textContent).toContain('Appareils de Nadia');
    expect(query('member-device-0-label')?.textContent).toContain('Firefox · Linux');
    expect(query('member-device-0')?.textContent).toContain('203.0.113.7');
    expect(query('member-device-1-label')?.textContent).toContain('Appareil inconnu');
    expect(query('member-devices-end-all')).not.toBeNull();
  });

  it('says the member is signed in nowhere, offering nothing to end', async () => {
    api.list.mockResolvedValue([]);
    const { query } = await render();

    expect(query('member-devices-none')?.textContent).toContain('Nadia n’est connecté nulle part.');
    expect(query('member-devices-end-all')).toBeNull();
  });

  it('offers ending them all only when there is more than one', async () => {
    api.list.mockResolvedValue([device('a')]);
    const { query } = await render();

    expect(query('member-device-0-end')).not.toBeNull();
    expect(query('member-devices-end-all')).toBeNull();
  });

  it('signs one out without a question, says so as corrigeable and reads the list again', async () => {
    const { press } = await render();

    await press('member-device-0-end');

    expect(api.end).toHaveBeenCalledWith('c1', 'u2', 'a');
    expect(dialog.open).not.toHaveBeenCalled();
    expect(api.list).toHaveBeenCalledTimes(2);
    expect(said()).toEqual([
      expect.objectContaining({
        key: 'members.devices.ended',
        params: { name: 'Nadia', device: 'Firefox · Linux' },
        effect: 'corrigeable',
      }),
    ]);
  });

  it('asks the owner to prove who is at the screen, and signs out once proved', async () => {
    api.end
      .mockRejectedValueOnce(new MemberDevicesRefused('step_up_required'))
      .mockResolvedValueOnce(undefined);
    const { press } = await render();

    await press('member-device-0-end');

    expect(api.end).toHaveBeenCalledTimes(2);
    expect(said()).toEqual([expect.objectContaining({ key: 'members.devices.ended' })]);
  });

  it('says nothing changed when the proof is not given, and offers no retry of the list', async () => {
    proves = false;
    api.end.mockRejectedValue(new MemberDevicesRefused('step_up_required'));
    const { press, query } = await render();

    await press('member-device-0-end');

    expect(said()).toEqual([]);
    expect(query('member-devices-error')?.textContent).toContain('Confirmez que c’est vous.');
    expect(query('member-devices-retry')).toBeNull();
    expect(query('member-device-0')).not.toBeNull();
  });

  it('asks before signing them all out, and ends nothing when kept', async () => {
    confirmed = false;
    const { press } = await render();

    await press('member-devices-end-all');

    expect(dialog.open).toHaveBeenCalledOnce();
    expect(api.endAll).not.toHaveBeenCalled();
  });

  it('signs them all out once confirmed, saying how many', async () => {
    const { press } = await render();

    await press('member-devices-end-all');

    expect(dialog.open).toHaveBeenCalledWith(
      expect.anything(),
      expect.objectContaining({
        data: expect.objectContaining({ kind: 'corrigeable', messageParams: { name: 'Nadia' } }),
      }),
    );
    expect(api.endAll).toHaveBeenCalledWith('c1', 'u2');
    expect(said()).toEqual([
      expect.objectContaining({
        key: 'members.devices.all_ended',
        params: { name: 'Nadia', count: 2 },
        effect: 'corrigeable',
      }),
    ]);
  });

  it('says a refusal in its own words', async () => {
    api.end.mockRejectedValue(new MemberDevicesRefused('owner_only'));
    const { press, query } = await render();

    await press('member-device-0-end');

    expect(query('member-devices-error')?.textContent).toContain('Seul le propriétaire.');
    expect(said()).toEqual([]);
  });

  it('reads the list again when another owner signs the member out, keeping a refusal said', async () => {
    api.end.mockRejectedValue(new MemberDevicesRefused('owner_only'));
    const { fixture, press, query } = await render();
    await press('member-device-0-end');
    api.list.mockResolvedValue([device('b', 'curl/8')]);

    await announceSaved(
      'user',
      'u2',
      { id: 'u1', name: 'Owner' },
      'account.sessions_ended_by_owner',
    );
    await fixture.whenStable();
    fixture.detectChanges();

    expect(query('member-device-0-label')?.textContent).toContain('Appareil inconnu');
    expect(query('member-devices-error')?.textContent).toContain('Seul le propriétaire.');
  });

  it('says the list could not be read, and reads it again on « Réessayer »', async () => {
    api.list.mockRejectedValueOnce(new MemberDevicesRefused('network'));
    const { press, query } = await render();

    expect(query('member-devices-error')?.textContent).toContain('Serveur injoignable.');
    expect(query('member-device-0')).toBeNull();
    await press('member-devices-retry');

    expect(api.list).toHaveBeenCalledTimes(2);
    expect(query('member-devices-error')).toBeNull();
    expect(query('member-device-0')).not.toBeNull();
  });
});
