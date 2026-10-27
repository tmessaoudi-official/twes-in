// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, type UrlTree } from '@angular/router';
import { AuthFacade } from '../auth/auth-facade';
import { NOT_ALLOWED_ROUTE, gatedPage, permissionGuard, RouteGates } from './permission-guard';

@Component({ template: '' })
class Blank {}

const VENDORS = [
  {
    key: 'vendors',
    labelKey: 'nav.vendors',
    route: '/vendors',
    permission: 'vendor.read',
    module: 'vendors',
  },
];
const OWNERS_ONLY = [
  {
    key: 'erase',
    labelKey: 'nav.data_erasure',
    route: '/erase',
    permission: 'company.settings',
    role: 'owner',
  },
];

interface State {
  company: { id: string; name: string; role: string; access: string } | null;
  permissions: string[];
  modules: string[];
}

describe('permissionGuard', () => {
  const state = signal<State | null>(null);
  const auth = {
    me: state.asReadonly(),
    hasModule: (module: string) => state()?.modules.includes(module) ?? false,
    grantedByRole: (permission: string) =>
      (state()?.permissions ?? []).some((held) => held === '*' || held === permission),
    hasPermission: (permission: string) =>
      auth.grantedByRole(permission) &&
      (state()?.company?.access !== 'read_only' || permission.endsWith('.read')),
  };
  const company = (role: string, access = 'full') => ({ id: 'c1', name: 'Acme', role, access });
  const vendorsGuard = permissionGuard(VENDORS, '/vendors');
  const ownersGuard = permissionGuard(OWNERS_ONLY, '/erase');

  beforeEach(() => {
    state.set({ company: company('admin'), permissions: ['vendor.read'], modules: ['vendors'] });
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: '', component: Blank },
          { path: 'vendors', component: Blank, canActivate: [vendorsGuard] },
          { path: 'vendors/:id', component: Blank, canActivate: [vendorsGuard] },
          { path: 'erase', component: Blank, canActivate: [ownersGuard] },
          { path: 'not-allowed', component: Blank },
        ]),
        { provide: AuthFacade, useValue: auth },
      ],
    });
  });

  const decide = (page: (typeof VENDORS)[number], url: string): true | string => {
    const answer = TestBed.inject(RouteGates).admit(page, url);
    return answer === true ? true : TestBed.inject(Router).serializeUrl(answer as UrlTree);
  };

  it('fails at start when the route it names is not declared, or needs nothing', () => {
    expect(() => permissionGuard(VENDORS, '/vendor')).toThrow(/\/vendor/);
    expect(() => permissionGuard([{ labelKey: 'nav.stock', route: '/stock' }], '/stock')).toThrow(
      /\/stock/,
    );
  });

  it('reads the declaration itself, which the refusal page finds again by its route', () => {
    expect(gatedPage('/vendors')).toBe(VENDORS[0]);
    expect(gatedPage('/nowhere')).toBeNull();
  });

  it('opens the page to whoever the menu shows it to', () => {
    expect(decide(VENDORS[0], '/vendors')).toBe(true);
  });

  it('sends whoever may not read it to the page that says so, keeping where they were going', () => {
    state.set({ company: company('clerk'), permissions: ['sale.write'], modules: ['vendors'] });

    expect(decide(VENDORS[0], '/vendors/v1')).toBe(
      `${NOT_ALLOWED_ROUTE}?page=%2Fvendors&from=%2Fvendors%2Fv1`,
    );
    expect(TestBed.inject(RouteGates).refusal(VENDORS[0])).toBe('role');
  });

  it('tells a page the subscription narrows from one the role refuses', () => {
    state.set({
      company: company('owner', 'read_only'),
      permissions: ['*'],
      modules: ['vendors'],
    });

    expect(TestBed.inject(RouteGates).refusal(OWNERS_ONLY[0])).toBe('subscription');
    expect(TestBed.inject(RouteGates).refusal(VENDORS[0])).toBeNull();
  });

  it('keeps a page declared for one role from another, whatever it is granted', () => {
    state.set({ company: company('admin'), permissions: ['*'], modules: [] });

    expect(TestBed.inject(RouteGates).refusal(OWNERS_ONLY[0])).toBe('role');
  });

  it('sends to the page that says so when the module is off, never home without a word', () => {
    state.set({ company: company('admin'), permissions: ['*'], modules: [] });

    expect(decide(VENDORS[0], '/vendors')).toBe(
      `${NOT_ALLOWED_ROUTE}?page=%2Fvendors&from=%2Fvendors`,
    );
  });

  it('leaves an open page that switching company no longer allows (hunt P-2)', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/vendors/v1');
    expect(router.url).toBe('/vendors/v1');

    // Switching company rebuilds the screen without navigating: only the session changes.
    state.set({ company: company('clerk'), permissions: ['sale.write'], modules: ['vendors'] });
    TestBed.tick();
    await vi.waitFor(() =>
      expect(router.url).toBe(`${NOT_ALLOWED_ROUTE}?page=%2Fvendors&from=%2Fvendors%2Fv1`),
    );
  });

  it('leaves it for the page that says so when the module is switched off while it is open', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/vendors');

    state.set({ company: company('admin'), permissions: ['*'], modules: [] });
    TestBed.tick();
    await vi.waitFor(() =>
      expect(router.url).toBe(`${NOT_ALLOWED_ROUTE}?page=%2Fvendors&from=%2Fvendors`),
    );
  });

  it('decides nothing while the session is being read again', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/vendors');

    state.set(null);
    TestBed.tick();
    await new Promise((resolve) => setTimeout(resolve));
    expect(router.url).toBe('/vendors');
  });
});
