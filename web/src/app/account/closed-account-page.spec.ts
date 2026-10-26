// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, RouterLink } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
  TranslatePipe,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { SignedOutLayout } from '../auth/signed-out-layout';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { provideStillAppearance } from '../shared/testing/appearance';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { ClosedAccountPage } from './closed-account-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ legal: { back: 'Retour' } });
  }
}

/** The account page itself has its own spec; here it stands in, so this one reads the frame around it. */
@Component({ selector: 'app-account-page', template: '<p data-testid="account-page"></p>' })
class AccountPageStub {}

describe('ClosedAccountPage', () => {
  const closed = signal(true);
  const me = signal<object | null>({ company: { id: 'c1' } });
  const auth = {
    me,
    companyClosed: () => closed(),
    refresh: vi.fn(async () => undefined),
  };

  beforeEach(async () => {
    closed.set(true);
    await TestBed.configureTestingModule({
      imports: [ClosedAccountPage],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        provideStillAppearance(),
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    })
      .overrideComponent(ClosedAccountPage, {
        set: { imports: [SignedOutLayout, AccountPageStub, RouterLink, TranslatePipe] },
      })
      .compileComponents();
  });

  it('draws « Mon compte » outside the shell, with the way back to the waiting page', async () => {
    const fixture = TestBed.createComponent(ClosedAccountPage);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;

    expect(el.querySelector('[data-testid="account-page"]')).not.toBeNull();
    const back = el.querySelector<HTMLAnchorElement>('[data-testid="closed-account-back"]');
    expect(back?.getAttribute('href')).toBe('/awaiting-approval');
    expect(back?.textContent).toContain('Retour');
  });

  it('goes into the shell’s own « Mon compte » once the company opens', async () => {
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const fixture = TestBed.createComponent(ClosedAccountPage);
    await fixture.whenStable();
    expect(navigate).not.toHaveBeenCalled();

    closed.set(false);
    me.set({ company: { id: 'c1' } });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(navigate).toHaveBeenCalledWith('/account');
  });
});
