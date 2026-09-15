// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { SettingsArea } from './settings-area';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      nav: {
        members: 'Membres',
        taxes: 'Taxes',
        units: 'Unités',
        settings: 'Valeurs par défaut',
        company_profile: 'Profil de la société',
        company_security: 'Sécurité',
        establishments: 'Établissements',
        numbering: 'Numérotation',
        custom_fields: 'Champs personnalisés',
        modules: 'Modules',
        sections: {
          company: 'Société',
          fiscal: 'Fiscalité',
          team: 'Équipe',
          customisation: 'Personnalisation',
        },
      },
      shell: {
        navigation: 'Navigation principale',
        settings_navigation: 'Paramètres de la société',
      },
    });
  }
}

describe('SettingsArea', () => {
  const permissions = signal<readonly string[]>([]);
  const auth = {
    hasPermission: (permission: string) => permissions().includes(permission),
    hasModule: () => true,
  };

  beforeEach(async () => {
    permissions.set(['company.settings', 'fiscal.read', 'user.read']);
    await TestBed.configureTestingModule({
      imports: [SettingsArea],
      providers: [
        provideRouter([]),
        { provide: AuthFacade, useValue: auth },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(SettingsArea);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const byTestId = (id: string) => el.querySelector<HTMLElement>(`[data-testid="${id}"]`);
    const groups = () =>
      [...el.querySelectorAll<HTMLElement>('mat-nav-list')].map((list) => {
        const heading = el.querySelector(`#${list.getAttribute('aria-labelledby')}`);
        return [
          heading?.id,
          heading?.textContent?.trim(),
          [...list.querySelectorAll('a')].map((a) => a.getAttribute('data-testid')),
        ];
      });
    return { fixture, el, byTestId, groups };
  }

  it('groups the settings in one navigation of their own, beside the page', async () => {
    const { el, byTestId, groups } = await render();

    const nav = byTestId('settings-nav');
    expect(nav?.tagName).toBe('NAV');
    expect(nav?.getAttribute('aria-label')).toBe('Paramètres de la société');
    expect(groups()).toEqual([
      [
        'settings-section-company',
        'Société',
        [
          'nav-company-profile',
          'nav-company-security',
          'nav-establishments',
          'nav-numbering',
          'nav-settings',
        ],
      ],
      ['settings-section-fiscal', 'Fiscalité', ['nav-taxes', 'nav-units']],
      ['settings-section-team', 'Équipe', ['nav-members']],
      ['settings-section-customisation', 'Personnalisation', ['nav-custom-fields', 'nav-modules']],
    ]);
    expect(byTestId('nav-members')?.getAttribute('href')).toBe('/members');
    expect(el.querySelector('router-outlet')).not.toBeNull();
  });

  it('shows a member only the settings they may open', async () => {
    permissions.set(['user.read']);
    const { groups } = await render();
    expect(groups()).toEqual([['settings-section-team', 'Équipe', ['nav-members']]]);
  });
});
