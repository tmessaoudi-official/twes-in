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
    return of({ auth: { scene: { paid: 'Payée', payment: 'Paiement reçu' } } });
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

describe('SignedOutLayout', () => {
  const name = signal('nova-pay');
  const tagline = signal('Rien ne se perd.');

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Host],
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
});
