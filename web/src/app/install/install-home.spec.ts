// SPDX-License-Identifier: AGPL-3.0-or-later

import { isSignal, signal, type WritableSignal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Brand } from '../shared/brand/brand';
import { TextsParts } from '../shared/i18n/texts-part';
import { AppInstall, type InstallOffer } from '../shared/install/app-install';
import { PageMemoryStorage } from '../shared/settings/settings-facade';
import { WINDOW_CLASS, type WindowClass } from '../shared/ui/window-class';
import { InstallHome } from './install-home';
import { INSTALL_LATER_KEY, INSTALL_STORAGE } from './install-later';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      install: {
        card: {
          title: 'Installer {{name}} sur ce téléphone',
          text: 'Il s’ouvrira depuis l’écran d’accueil.',
          install: 'Installer',
          later: 'Plus tard',
        },
      },
    });
  }
}

describe('InstallHome', () => {
  const install = vi.fn();
  const textsOpen = vi.fn(() => Promise.resolve());

  async function render(
    windowClass: WindowClass,
    offer: InstallOffer | null | WritableSignal<InstallOffer | null>,
    storage = new PageMemoryStorage(),
  ) {
    install.mockReset();
    textsOpen.mockClear();
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ fallbackLang: 'fr', lang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        {
          provide: AppInstall,
          useValue: { offer: isSignal(offer) ? offer : signal(offer), install },
        },
        { provide: WINDOW_CLASS, useValue: signal(windowClass) },
        { provide: INSTALL_STORAGE, useValue: storage },
        { provide: TextsParts, useValue: { open: textsOpen } },
        { provide: Brand, useValue: { name: signal('twes-in') } },
      ],
    });
    const fixture = TestBed.createComponent(InstallHome);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    return {
      settle: async () => {
        fixture.detectChanges();
        await fixture.whenStable();
        fixture.detectChanges();
      },
      card: () => element.querySelector('[data-testid="home-install"]'),
      click: async (testId: string) => {
        element.querySelector<HTMLButtonElement>(`[data-testid="${testId}"]`)?.click();
        fixture.detectChanges();
        await fixture.whenStable();
      },
      storage,
    };
  }

  it('offers to install on a phone that can, its words opened by the card itself', async () => {
    const { card, click } = await render('compact', 'prompt');

    expect(textsOpen).toHaveBeenCalledWith('install');
    expect(card()?.textContent).toContain('Installer twes-in sur ce téléphone');
    await click('install-now');
    expect(install).toHaveBeenCalledTimes(1);
  });

  it.each([
    ['a wider window, where the menu offers it', 'medium' as const, 'prompt' as const],
    ['a browser that cannot install, or it is installed', 'compact' as const, null],
  ])('draws nothing on %s', async (_case, windowClass, offer) => {
    const { card } = await render(windowClass, offer);

    expect(card()).toBeNull();
    expect(textsOpen).not.toHaveBeenCalled();
  });

  it('opens its words once the browser hands its offer over, after the home was drawn', async () => {
    const offer = signal<InstallOffer | null>(null);
    const { card, settle } = await render('compact', offer);
    expect(textsOpen).not.toHaveBeenCalled();

    offer.set('prompt');
    await settle();

    expect(textsOpen).toHaveBeenCalledTimes(1);
    expect(card()?.textContent).toContain('Installer twes-in sur ce téléphone');
    offer.set(null);
    await settle();
    offer.set('prompt');
    await settle();
    expect(textsOpen).toHaveBeenCalledTimes(1);
  });

  it('is put aside for good on this phone with « Plus tard »', async () => {
    const { card, click, storage } = await render('compact', 'guide');

    await click('install-later');

    expect(card()).toBeNull();
    expect(storage.getItem(INSTALL_LATER_KEY)).toBe('true');
    expect(install).not.toHaveBeenCalled();
  });

  it('stays away once put aside, without even fetching its words', async () => {
    const storage = new PageMemoryStorage();
    storage.setItem(INSTALL_LATER_KEY, 'true');
    const { card } = await render('compact', 'prompt', storage);

    expect(card()).toBeNull();
    expect(textsOpen).not.toHaveBeenCalled();
  });
});
