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
import { refusedByTheDemo } from '../shared/testing/said-answers';
import { UnsavedChanges } from '../shared/form/unsaved-changes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

// The proof dialog is answered by the test; `retrying` itself is StepUp's own.
const stepUp = Object.assign(Object.create(StepUp.prototype) as StepUp, {
  request: vi.fn<(intro?: string) => Promise<boolean>>(),
});

const CLOSED: SupportAccessState = {
  startsAt: null,
  endsAt: null,
  reason: null,
  hours: [1, 24, 72],
  reasonMax: 500,
};
// A window open around the moment the spec runs: one ended is shown closed.
const OPEN: SupportAccessState = {
  startsAt: new Date(Date.now() - 3_600_000).toISOString(),
  endsAt: new Date(Date.now() + 23 * 3_600_000).toISOString(),
  reason: null,
  hours: [1, 24, 72],
  reasonMax: 500,
};

describe('SupportAccessPage', () => {
  let fixture: ComponentFixture<SupportAccessPage>;
  const api = {
    read: vi.fn<(companyId: string) => Promise<SupportAccessState>>(),
    grant:
      vi.fn<
        (companyId: string, hours: number, reason: string | null) => Promise<SupportAccessState>
      >(),
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

    expect(api.grant).toHaveBeenCalledExactlyOnceWith('c1', 72, null);
    expect(q('support-access-open')).not.toBeNull();
    expect(effectToasts()).toEqual(['support_access.granted:corrigeable']);
    // The banner reads the session, which says until when.
    expect(refresh).toHaveBeenCalledOnce();
  });

  it('sends what is wrong with the window, counts it unsaved until sent, and shows it while open', async () => {
    // Workflow hunt SUP-1: the operator opened the company with no idea where to look.
    const said = 'La facture du 3 mars s’imprime sans le timbre';
    api.grant.mockResolvedValue({ ...OPEN, reason: said });
    await open();
    const unsaved = TestBed.inject(UnsavedChanges);

    const field = q('support-access-reason') as HTMLTextAreaElement;
    expect(field.getAttribute('maxlength')).toBe('500');
    field.value = said;
    field.dispatchEvent(new Event('input'));
    await settle();
    expect(unsaved.count()).toBe(1);
    expect(q('support-access-reason-count')?.textContent).toContain(`${said.length} / 500`);

    q('support-access-grant')!.click();
    await settle();

    expect(api.grant).toHaveBeenCalledExactlyOnceWith('c1', 24, said);
    expect(q('support-access-reason-said')?.textContent).toContain(said);
    expect(field.value).toBe('');
    expect(unsaved.count()).toBe(0);
  });

  it('sends no reason for one left blank', async () => {
    await open();
    const field = q('support-access-reason') as HTMLTextAreaElement;
    field.value = '   ';
    field.dispatchEvent(new Event('input'));
    await settle();

    q('support-access-grant')!.click();
    await settle();

    expect(api.grant).toHaveBeenCalledExactlyOnceWith('c1', 24, null);
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

  it('says the window closed when it ends by itself, on a page left open, without asking the API', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      const ends = new Date(Date.now() + 10 * 60_000).toISOString();
      api.read.mockResolvedValue({ ...OPEN, endsAt: ends });
      await open();
      expect(q('support-access-open')).not.toBeNull();
      expect(q('support-access-end')).not.toBeNull();

      await vi.advanceTimersByTimeAsync(10 * 60_000 + 5);
      fixture.detectChanges();

      expect(q('support-access-open')).toBeNull();
      expect(q('support-access-end')).toBeNull();
      expect(q('support-access-closed')).not.toBeNull();
      expect(api.read).toHaveBeenCalledOnce();
    } finally {
      vi.useRealTimers();
    }
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

  it("adds nothing of its own to the demo's refusal, which the toast has said", async () => {
    api.grant.mockReset().mockRejectedValue(refusedByTheDemo(new SupportAccessRefused('network')));
    await open();

    q('support-access-grant')!.click();
    await settle();

    expect(q('support-access-closed')).not.toBeNull();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);
  });
});
