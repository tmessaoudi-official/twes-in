// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { NotAllowedPage } from './not-allowed-page';
import { permissionGuard } from './permission-guard';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      titles: { not_allowed: 'Page non autorisée' },
      nav: { suppliers: 'Fournisseurs', new_supplier: 'Nouveau fournisseur' },
      not_allowed: {
        role: 'Votre rôle dans {{company}} ne permet pas d’ouvrir « {{page}} ».',
        role_any: 'Votre rôle dans {{company}} ne permet pas d’ouvrir cette page.',
        subscription:
          'L’abonnement de {{company}} ne permet pas d’ouvrir « {{page}} » en ce moment.',
        ask: 'Si vous en avez besoin, demandez à un propriétaire de {{company}} de changer votre rôle.',
        subscription_ask:
          'Seul un propriétaire de {{company}} peut régler l’abonnement\u00a0: prévenez-le.',
        module: 'Le module de «\u00a0{{page}}\u00a0» est désactivé dans {{company}}.',
        module_ask:
          'Si vous en avez besoin, demandez à qui gère {{company}} de l’activer dans Paramètres › Modules.',
        see_subscription: 'Voir l’abonnement',
        home: 'Retour à l’accueil',
      },
    });
  }
}

@Component({ template: '' })
class Blank {}

// The guard's declaration, registered as app.routes registers the real ones.
const SUPPLIERS = [
  { labelKey: 'nav.suppliers', route: '/suppliers', permission: 'vendor.read', module: 'vendors' },
  {
    labelKey: 'nav.new_supplier',
    route: '/suppliers/new',
    permission: 'vendor.write',
    module: 'vendors',
  },
] as const;
permissionGuard(SUPPLIERS, '/suppliers');
permissionGuard(SUPPLIERS, '/suppliers/new');

describe('NotAllowedPage', () => {
  const state = signal({
    company: { id: 'c1', name: 'Atelier Ben Salah', role: 'clerk', access: 'full' },
    permissions: ['sale.write'],
    modules: [] as string[],
  });
  const auth = {
    me: state.asReadonly(),
    hasModule: (module: string) => !state().modules.includes(`off:${module}`),
    grantedByRole: (permission: string) =>
      state().permissions.some((held) => held === '*' || held === permission),
    hasPermission: (permission: string) =>
      auth.grantedByRole(permission) &&
      (state().company.access !== 'read_only' || permission.endsWith('.read')),
  };
  let fixture: ComponentFixture<NotAllowedPage>;

  async function render(page: string | undefined, from: string | undefined): Promise<HTMLElement> {
    TestBed.configureTestingModule({
      imports: [NotAllowedPage],
      providers: [
        provideRouter([
          { path: '', component: Blank },
          { path: 'suppliers', component: Blank },
          { path: 'suppliers/:id', component: Blank },
        ]),
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        { provide: AuthFacade, useValue: auth },
      ],
    });
    fixture = TestBed.createComponent(NotAllowedPage);
    if (page !== undefined) fixture.componentRef.setInput('page', page);
    if (from !== undefined) fixture.componentRef.setInput('from', from);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const text = (root: HTMLElement, testId: string) =>
    root.querySelector(`[data-testid="${testId}"]`)?.textContent?.trim() ?? null;

  beforeEach(() => {
    state.set({
      company: { id: 'c1', name: 'Atelier Ben Salah', role: 'clerk', access: 'full' },
      permissions: ['sale.write'],
      modules: [],
    });
  });

  it('names the page and the company, says the role keeps it closed and who can change that', async () => {
    const root = await render('/suppliers', '/suppliers/v1');

    expect(text(root, 'not-allowed-reason')).toBe(
      'Votre rôle dans Atelier Ben Salah ne permet pas d’ouvrir « Fournisseurs ».',
    );
    expect(text(root, 'not-allowed-ask')).toContain('un propriétaire de Atelier Ben Salah');
    expect(root.querySelector('[data-testid="not-allowed-home"]')?.getAttribute('href')).toBe('/');
    expect(root.querySelector('[data-testid="not-allowed-subscription"]')).toBeNull();
  });

  it('says it is the subscription when the role grants the page and the company is read-only', async () => {
    state.set({
      company: { id: 'c1', name: 'Atelier Ben Salah', role: 'owner', access: 'read_only' },
      permissions: ['*'],
      modules: [],
    });
    const root = await render('/suppliers/new', '/suppliers/new');

    expect(text(root, 'not-allowed-reason')).toBe(
      'L’abonnement de Atelier Ben Salah ne permet pas d’ouvrir « Nouveau fournisseur » en ce moment.',
    );
    expect(
      root.querySelector('[data-testid="not-allowed-subscription"]')?.getAttribute('href'),
    ).toBe('/company/subscription');
    expect(root.querySelector('[data-testid="not-allowed-ask"]')).toBeNull();
  });

  it('offers the subscription only to whoever may open it, and tells the others to warn an owner', async () => {
    state.set({
      company: { id: 'c1', name: 'Atelier Ben Salah', role: 'clerk', access: 'read_only' },
      permissions: ['vendor.read', 'vendor.write'],
      modules: [],
    });
    const root = await render('/suppliers/new', '/suppliers/new');

    expect(text(root, 'not-allowed-reason')).toBe(
      'L’abonnement de Atelier Ben Salah ne permet pas d’ouvrir « Nouveau fournisseur » en ce moment.',
    );
    expect(root.querySelector('[data-testid="not-allowed-subscription"]')).toBeNull();
    expect(text(root, 'not-allowed-ask')).toBe(
      'Seul un propriétaire de Atelier Ben Salah peut régler l’abonnement\u00a0: prévenez-le.',
    );
  });

  it('says the module is off, not the role, and who can switch it on', async () => {
    state.set({
      company: { id: 'c1', name: 'Atelier Ben Salah', role: 'admin', access: 'full' },
      permissions: ['*'],
      modules: ['off:vendors'],
    });
    const root = await render('/suppliers', '/suppliers');

    expect(text(root, 'not-allowed-reason')).toBe(
      'Le module de «\u00a0Fournisseurs\u00a0» est désactivé dans Atelier Ben Salah.',
    );
    expect(text(root, 'not-allowed-ask')).toContain('Paramètres › Modules');
    expect(root.querySelector('[data-testid="not-allowed-subscription"]')).toBeNull();
  });

  it('names no page it does not know, and still offers the way home', async () => {
    const root = await render('/nowhere', '/nowhere');

    expect(text(root, 'not-allowed-reason')).toBe(
      'Votre rôle dans Atelier Ben Salah ne permet pas d’ouvrir cette page.',
    );
    expect(root.querySelector('[data-testid="not-allowed-home"]')).not.toBeNull();
  });

  it('takes the person on to the page once the session opens it, after a company switch', async () => {
    await render('/suppliers', '/suppliers/v1');
    const router = TestBed.inject(Router);

    state.set({
      company: { id: 'c2', name: 'Quincaillerie', role: 'admin', access: 'full' },
      permissions: ['vendor.read'],
      modules: [],
    });
    TestBed.tick();

    await vi.waitFor(() => expect(router.url).toBe('/suppliers/v1'));
  });

  it('never goes on to an address outside the application', async () => {
    state.set({
      company: { id: 'c2', name: 'Quincaillerie', role: 'admin', access: 'full' },
      permissions: ['vendor.read'],
      modules: [],
    });
    await render('/suppliers', '//evil.example/x');

    await vi.waitFor(() => expect(TestBed.inject(Router).url).toBe('/suppliers'));
  });
});
