// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Brand } from '../shared/brand/brand';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { provideQuietFeedback } from '../shared/testing/feedback';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { SignedOutLayout } from './signed-out-layout';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ auth: { scene: { paid: 'Soldée', payment: 'Paiement reçu' } } });
  }
}

@Component({
  imports: [SignedOutLayout],
  template: `<app-signed-out-layout>
    <h1>Connexion</h1>
    <p twesAuthFooter data-testid="footer-line">API</p>
  </app-signed-out-layout>`,
})
class Host {}

@Component({
  imports: [SignedOutLayout],
  template: `<app-signed-out-layout plain>
    <h1 data-testid="plain-title">Abonnement</h1>
  </app-signed-out-layout>`,
})
class PlainHost {}

describe('SignedOutLayout', () => {
  const name = signal('nova-pay');
  const tagline = signal('Rien ne se perd.');

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Host, PlainHost],
      providers: [
        { provide: Brand, useValue: { name, tagline } },
        provideQuietFeedback(),
        { provide: ThemeFacade, useValue: { preference: signal('auto'), setScheme: vi.fn() } },
        { provide: LanguageFacade, useValue: { current: signal('fr'), use: vi.fn() } },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    return fixture.nativeElement as HTMLElement;
  }

  it('shows the installation brand above the page, from the brand port', async () => {
    const el = await render();

    expect(el.querySelector('header [role="img"]')?.getAttribute('aria-label')).toBe('nova-pay');
    expect(el.querySelector('[data-testid="brand-tagline"]')?.textContent?.trim()).toBe(
      'Rien ne se perd.',
    );
    expect(el.querySelector('main h1')?.textContent).toBe('Connexion');
    expect(el.querySelector('footer [data-testid="footer-line"]')).not.toBeNull();
  });

  it('keeps the decorative scene away from assistive technology', async () => {
    const el = await render();

    const scene = el.querySelector('[data-testid="auth-scene"]');
    expect(scene?.textContent).toContain('Paiement reçu');
    expect(scene?.getAttribute('aria-hidden')).toBe('true');
    expect(scene?.closest('main')).toBeNull();
  });

  it('offers the language and the colour scheme before anyone signs in, outside the page card', async () => {
    const el = await render();

    const language = el.querySelector('[data-testid="language-menu"]');
    const scheme = el.querySelector('[data-testid="scheme-menu"]');
    expect(language).not.toBeNull();
    expect(scheme).not.toBeNull();
    expect(scheme?.closest('.twes-auth-card')).toBeNull();
    expect(scheme?.closest('[aria-hidden="true"]')).toBeNull();
  });

  it('gives a page that brings its own cards the width, with no sign-in card around it', async () => {
    // The sign-in card is 26.25rem and holds one column of fields. A whole page inside it lays its form out in two
    // columns anyway — `sm:` is a viewport breakpoint, not the card's width — and the inputs collapse to nothing:
    // a locked company could see the form and not type in it (2026-09-17).
    const fixture = TestBed.createComponent(PlainHost);
    await fixture.whenStable();
    const title = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-testid="plain-title"]',
    );

    expect(title?.closest('.twes-auth-card')).toBeNull();
    expect(title?.closest('.twes-auth-page')).not.toBeNull();
  });

  it('drops the scene behind a plain page, which is as wide as the space the scene wants', async () => {
    // The scene's documents are placed around a card of 26.25rem. A page twice that runs under them: the heading
    // came out behind an invoice and a delivery note lay over the form (2026-09-17, read off the e2e screenshot).
    const fixture = TestBed.createComponent(PlainHost);
    await fixture.whenStable();

    expect(
      (fixture.nativeElement as HTMLElement).querySelector('[data-testid="auth-scene"]'),
    ).toBeNull();
  });
});
