// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
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
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { StepUp } from '../shared/step-up/step-up';
import { effectToasts, provideQuietFeedback } from '../shared/testing/feedback';
import { ThemeFacade } from '../shared/theme/theme-facade';
import {
  DataErasureApi,
  ErasureRefused,
  type Erasure,
  type ErasurePreview,
} from './data-erasure-api';
import { DataErasurePage, ERASURE_PAGE_IDLE_MS } from './data-erasure-page';
import type { ErasurePreviewLine } from './erasure-preview-dialog';
import { PendingErasure } from './pending-erasure';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const PREVIEW: ErasurePreview = {
  parts: [
    { part: 'stock_map', counts: { floors: 1, places: 6, structures: 9 } },
    { part: 'drafts', counts: { drafts: 17, quotes: 23 } },
  ],
  pending: null,
};
const ERASURE: Erasure = {
  id: 'e1',
  parts: ['stock_map'],
  counts: { stock_map: { floors: 1, places: 6, structures: 9 } },
  erasedAt: '2026-10-10T12:00:00+00:00',
  effectiveAt: '2026-10-11T12:00:00+00:00',
  state: 'pending',
  kinds: ['venue_area'],
};

describe('DataErasurePage', () => {
  let fixture: ComponentFixture<DataErasurePage>;
  let proved = true;
  let answer = true;
  const shown: (readonly ErasurePreviewLine[])[] = [];
  const showComing = signal(true);
  const api = {
    preview: vi.fn<(companyId: string) => Promise<ErasurePreview>>(),
    erase: vi.fn<(companyId: string, parts: readonly string[]) => Promise<Erasure>>(),
    pending: vi.fn<(companyId: string) => Promise<Erasure | null>>(),
    undo: vi.fn(),
  };
  const stepUp = { request: vi.fn(async () => proved) };
  const dialog = {
    open: vi.fn((_component: unknown, config: { data: readonly ErasurePreviewLine[] }) => {
      shown.push(config.data);
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

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(DataErasurePage);
    await settle();
  }

  async function confirmIdentity(): Promise<void> {
    q('erasure-confirm')!.click();
    await settle();
  }

  async function choose(part: string): Promise<void> {
    (q(`erasure-choose-${part}`)!.querySelector('input') as HTMLInputElement).click();
    await settle();
  }

  beforeEach(() => {
    proved = true;
    answer = true;
    shown.length = 0;
    showComing.set(true);
    api.preview.mockReset().mockResolvedValue(PREVIEW);
    api.erase.mockReset();
    api.pending.mockReset().mockResolvedValue(null);
    stepUp.request.mockClear();
    dialog.open.mockClear();
    TestBed.configureTestingModule({
      imports: [DataErasurePage],
      providers: [
        ...provideQuietFeedback(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: DataErasureApi, useValue: api },
        { provide: MatDialog, useValue: dialog },
        { provide: StepUp, useValue: stepUp },
        { provide: ThemeFacade, useValue: { showComing } },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({
              user: { id: 'u1' },
              company: { id: 'c1', name: 'Carthage', timezone: 'Africa/Tunis', role: 'owner' },
            }),
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('stays closed, counting nothing, until the person proves who they are', async () => {
    await open();

    expect(q('erasure-locked')).not.toBeNull();
    expect(q('erasure-review')).toBeNull();
    expect(api.preview).not.toHaveBeenCalled();

    proved = false;
    await confirmIdentity();
    expect(stepUp.request).toHaveBeenCalledWith('step_up.intro_erasure');
    expect(api.preview).not.toHaveBeenCalled();
    expect(q('erasure-locked')).not.toBeNull();
  });

  it('once proved, says what each part would take, with what is coming listed « Bientôt »', async () => {
    await open();
    await confirmIdentity();

    expect(api.preview).toHaveBeenCalledWith('c1');
    expect(q('erasure-confirmed')).not.toBeNull();
    expect(q('erasure-part-stock_map')).not.toBeNull();
    expect(q('erasure-part-drafts')).not.toBeNull();
    expect(q('erasure-coming-products')?.textContent).toContain('shell.soon');
    expect((q('erasure-review') as HTMLButtonElement).disabled).toBe(true);

    showComing.set(false);
    await settle();
    expect(q('erasure-coming-products')).toBeNull();
  });

  it('shows the dry run counted again, erases on « Effacer », and says it can be undone', async () => {
    await open();
    await confirmIdentity();
    await choose('stock_map');
    api.erase.mockResolvedValue(ERASURE);
    // Counted again for the dry run, then once more after the erasure, which the API now names as waiting.
    api.preview.mockResolvedValueOnce(PREVIEW).mockResolvedValue({ ...PREVIEW, pending: ERASURE });

    q('erasure-review')!.click();
    await settle();

    expect(api.preview).toHaveBeenCalledTimes(3);
    expect(shown).toEqual([
      [
        {
          label: 'data_erasure.parts.stock_map.title',
          summary: 'data_erasure.parts.stock_map.short',
        },
      ],
    ]);
    expect(api.erase).toHaveBeenCalledWith('c1', ['stock_map']);
    expect(effectToasts()).toEqual(['data_erasure.erased:annulable']);
    expect(TestBed.inject(PendingErasure).erasure()).toEqual(ERASURE);
  });

  it('erases nothing when the dry run is answered « Retour »', async () => {
    await open();
    await confirmIdentity();
    await choose('drafts');
    answer = false;

    q('erasure-review')!.click();
    await settle();

    expect(api.erase).not.toHaveBeenCalled();
  });

  it('asks for the proof again when it grew old on the page, then erases once', async () => {
    await open();
    await confirmIdentity();
    await choose('stock_map');
    api.erase
      .mockRejectedValueOnce(new ErasureRefused('step_up_required'))
      .mockResolvedValueOnce(ERASURE);

    q('erasure-review')!.click();
    await settle();

    expect(stepUp.request).toHaveBeenCalledTimes(2);
    expect(api.erase).toHaveBeenCalledTimes(2);
    expect(effectToasts()).toEqual(['data_erasure.erased:annulable']);
  });

  it('waits while another erasure may still be undone', async () => {
    api.preview.mockResolvedValue({ ...PREVIEW, pending: ERASURE });
    await open();
    await confirmIdentity();

    expect(q('erasure-waiting')).not.toBeNull();
    expect((q('erasure-review') as HTMLButtonElement).disabled).toBe(true);
  });

  it('closes again after ten minutes with nothing done on it', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    try {
      await open();
      await confirmIdentity();
      vi.advanceTimersByTime(ERASURE_PAGE_IDLE_MS - 1000);
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab' }));
      vi.advanceTimersByTime(ERASURE_PAGE_IDLE_MS - 1000);
      await settle();
      expect(q('erasure-confirmed')).not.toBeNull();

      vi.advanceTimersByTime(2000);
      await settle();
      expect(q('erasure-locked')).not.toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });
});
