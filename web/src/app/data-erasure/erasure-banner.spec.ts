// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { DataErasureApi, ErasureRefused, type Erasure } from './data-erasure-api';
import { ErasureBanner } from './erasure-banner';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const ERASURE: Erasure = {
  id: 'e1',
  parts: ['stock_map', 'drafts'],
  counts: {},
  erasedAt: '2026-10-10T12:00:00+00:00',
  effectiveAt: '2026-10-11T12:00:00+00:00',
  state: 'pending',
  kinds: ['venue_area', 'quote'],
};

describe('ErasureBanner', () => {
  let fixture: ComponentFixture<ErasureBanner>;
  const api = {
    pending: vi.fn<(companyId: string) => Promise<Erasure | null>>(),
    undo: vi.fn<(companyId: string, id: string) => Promise<Erasure>>(),
  };

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve));
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.pending.mockReset();
    api.undo.mockReset();
    TestBed.configureTestingModule({
      imports: [ErasureBanner],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: DataErasureApi, useValue: api },
        {
          provide: AuthFacade,
          useValue: { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', role: 'owner' } }) },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('says nothing while no erasure waits', async () => {
    api.pending.mockResolvedValue(null);
    fixture = TestBed.createComponent(ErasureBanner);
    await settle();

    expect(api.pending).toHaveBeenCalledWith('c1');
    expect(q('erasure-banner')).toBeNull();
  });

  it('offers the undo, puts everything back, and tells this tab’s screens what came back', async () => {
    api.pending.mockResolvedValue(ERASURE);
    fixture = TestBed.createComponent(ErasureBanner);
    await settle();
    expect(q('erasure-banner')?.textContent).toContain('data_erasure.banner.text');
    const heard = vi.spyOn(TestBed.inject(LiveChanges), 'changedHere');
    api.undo.mockResolvedValue({ ...ERASURE, state: 'undone' });

    q('erasure-undo')!.click();
    await settle();

    expect(api.undo).toHaveBeenCalledWith('c1', 'e1');
    expect(heard).toHaveBeenCalledWith(['venue_area', 'quote'], 'data.erasure_undone');
    expect(successToasts()).toEqual(['data_erasure.undone']);
    expect(q('erasure-banner')).toBeNull();
  });

  it('keeps the banner and says why when something made since stands in the way', async () => {
    api.pending.mockResolvedValue(ERASURE);
    fixture = TestBed.createComponent(ErasureBanner);
    await settle();
    api.undo.mockRejectedValue(new ErasureRefused('erasure_conflict', 'venue_area'));

    q('erasure-undo')!.click();
    await settle();

    expect(q('erasure-banner')).not.toBeNull();
    expect(successToasts()).toEqual([]);
  });
});
