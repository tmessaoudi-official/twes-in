// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { StepUp } from '../shared/step-up/step-up';
import { effectToasts, provideQuietFeedback, RecordedFeedback } from '../shared/testing/feedback';
import {
  SupportAccessApi,
  SupportAccessRefused,
  type SupportAccessState,
} from './support-access-api';
import { DEFAULT_SUPPORT_HOURS, SupportAccessPage } from './support-access-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

const CLOSED: SupportAccessState = { startsAt: null, endsAt: null, hours: [1, 24, 72] };
const OPEN: SupportAccessState = {
  startsAt: '2026-10-12T09:00:00+00:00',
  endsAt: '2026-10-13T09:00:00+00:00',
  hours: [1, 24, 72],
};

describe('SupportAccessPage', () => {
  let fixture: ComponentFixture<SupportAccessPage>;
  const api = {
    read: vi.fn<(companyId: string) => Promise<SupportAccessState>>(),
    grant: vi.fn<(companyId: string, hours: number) => Promise<SupportAccessState>>(),
    end: vi.fn<(companyId: string) => Promise<void>>(),
  };
  const refresh = vi.fn(async () => undefined);

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(SupportAccessPage);
    await settle();
  }

  beforeEach(() => {
    api.read.mockReset().mockResolvedValue(CLOSED);
    api.grant.mockReset().mockResolvedValue(OPEN);
    api.end.mockReset().mockResolvedValue(undefined);
    refresh.mockClear();
    TestBed.configureTestingModule({
      imports: [SupportAccessPage],
      providers: [
        ...provideQuietFeedback(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: SupportAccessApi, useValue: api },
        { provide: StepUp, useValue: stepUp },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({
              user: { id: 'u1' },
              company: { id: 'c1', name: 'Carthage', timezone: 'Africa/Tunis', role: 'owner' },
            }),
            refresh,
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('offers an hour, a day or three, a day first, and opens the company for the window chosen', async () => {
    await open();

    expect(q('support-access-closed')).not.toBeNull();
    expect(DEFAULT_SUPPORT_HOURS).toBe(24);
    const checked = q('support-access-hours-24')?.querySelector('input') as HTMLInputElement;
    expect(checked.checked).toBe(true);

    (q('support-access-hours-72')!.querySelector('input') as HTMLInputElement).click();
    await settle();
    q('support-access-grant')!.click();
    await settle();

    expect(api.grant).toHaveBeenCalledExactlyOnceWith('c1', 72);
    expect(q('support-access-open')).not.toBeNull();
    expect(effectToasts()).toEqual(['support_access.granted:corrigeable']);
    // The banner reads the session, which says until when.
    expect(refresh).toHaveBeenCalledOnce();
  });

  it('closes an open window when the owner asks', async () => {
    api.read.mockResolvedValueOnce(OPEN).mockResolvedValueOnce(CLOSED);
    await open();
    expect(q('support-access-open')).not.toBeNull();

    q('support-access-end')!.click();
    await settle();

    expect(api.end).toHaveBeenCalledExactlyOnceWith('c1');
    expect(q('support-access-closed')).not.toBeNull();
    expect(effectToasts()).toEqual(['support_access.ended:corrigeable']);
  });

  it('says that only the owner opens it, to anyone else who reaches the page', async () => {
    api.read.mockRejectedValue(new SupportAccessRefused('owner_only'));
    await open();

    expect(q('support-access-owner-only')).not.toBeNull();
    expect(q('support-access-grant')).toBeNull();
  });

  // docs/SPEC.md § 7, the step-up list: opening the company to support waits for the owner to prove who they are again.
  it('asks the owner for the proof the API wants, then opens the window', async () => {
    stepUp.request.mockReset().mockResolvedValue(true);
    api.grant.mockReset();
    api.grant
      .mockRejectedValueOnce(new SupportAccessRefused('step_up_required'))
      .mockResolvedValueOnce(OPEN);
    await open();

    q('support-access-grant')!.click();
    await settle();

    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_change');
    expect(api.grant).toHaveBeenCalledTimes(2);
    expect(q('support-access-open')).not.toBeNull();
  });

  it('says nothing changed when the owner does not give the proof', async () => {
    stepUp.request.mockReset().mockResolvedValue(false);
    api.grant.mockReset().mockRejectedValue(new SupportAccessRefused('step_up_required'));
    await open();

    q('support-access-grant')!.click();
    await settle();

    expect(api.grant).toHaveBeenCalledOnce();
    expect(q('support-access-closed')).not.toBeNull();
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;
    expect(said.map(({ kind, key }) => `${kind}:${key}`)).toEqual([
      'failure:support_access.refused.step_up_required',
    ]);
  });
});
