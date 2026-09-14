// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { ModulesError } from './modules-api';
import { ModulesFacade } from './modules-facade';
import { ModulesPage } from './modules-page';
import type { ModuleRow } from './modules-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      modules: {
        customers: 'Clients',
        invoices: 'Factures',
        products: 'Produits',
        deliveries: 'Livraisons',
      },
      company: {
        modules: {
          title: 'Modules',
          depends_on: 'Nécessite :',
          errors: {
            still_needed: 'Ces modules en ont encore besoin :',
            needs_modules: 'Activez d’abord :',
          },
        },
      },
    });
  }
}

const customers: ModuleRow = {
  key: 'customers',
  labelKey: 'modules.customers',
  dependencies: [],
  permissions: ['customer.read'],
  enabled: true,
};
const invoices: ModuleRow = {
  key: 'invoices',
  labelKey: 'modules.invoices',
  dependencies: ['customers'],
  permissions: [],
  enabled: true,
};

describe('ModulesPage', () => {
  const modules = signal<readonly ModuleRow[]>([customers, invoices]);
  const error = signal<ModulesError | null>(null);
  const facade = {
    modules: modules.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    switch: vi.fn(),
    clearError: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<ModulesPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const switchOf = (key: string): HTMLButtonElement =>
    q(`module-toggle-${key}`)!.querySelector('button[role="switch"]') as HTMLButtonElement;

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(ModulesPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    modules.set([customers, invoices]);
    error.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.switch.mockReset().mockResolvedValue(true);
    facade.clearError.mockReset();
    auth.hasPermission.mockReset().mockReturnValue(true);
    TestBed.configureTestingModule({
      imports: [ModulesPage],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ModulesFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
      ],
    });
  });

  it('lists the modules, whether each is on, and what each needs', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1');
    expect(q('module-customers')?.textContent).toContain('Clients');
    expect(switchOf('customers').getAttribute('aria-checked')).toBe('true');
    expect(q('module-invoices')?.textContent).toContain('Nécessite :');
    expect(q('module-invoices-needs')?.textContent).toContain('Clients');
    expect(q('module-customers-needs')).toBeNull();
    expect(auth.hasPermission).toHaveBeenCalledWith('company.settings');
  });

  it('switches a module off', async () => {
    await open();

    switchOf('invoices').click();
    await settle();

    expect(facade.switch).toHaveBeenCalledWith('c1', 'invoices', false);
  });

  it('names the modules still needing one it could not switch off, and leaves its switch on', async () => {
    modules.set([
      customers,
      invoices,
      {
        key: 'deliveries',
        labelKey: 'modules.deliveries',
        dependencies: ['customers'],
        permissions: [],
        enabled: false,
      },
    ]);
    facade.switch.mockImplementation(async () => {
      error.set('still_needed');
      return false;
    });
    await open();

    switchOf('customers').click();
    await settle();

    expect(q('modules-error')?.textContent).toContain('Ces modules en ont encore besoin :');
    expect(q('modules-error')?.textContent).toContain('Factures');
    expect(q('modules-error')?.textContent).not.toContain('Livraisons');
    expect(switchOf('customers').getAttribute('aria-checked')).toBe('true');
  });

  it('names what a module needs when it could not be switched on', async () => {
    modules.set([
      { ...customers, enabled: false },
      {
        key: 'products',
        labelKey: 'modules.products',
        dependencies: [],
        permissions: [],
        enabled: true,
      },
      { ...invoices, dependencies: ['customers', 'products'], enabled: false },
    ]);
    facade.switch.mockImplementation(async () => {
      error.set('needs_modules');
      return false;
    });
    await open();

    switchOf('invoices').click();
    await settle();

    expect(facade.switch).toHaveBeenCalledWith('c1', 'invoices', true);
    expect(q('modules-error')?.textContent).toContain('Activez d’abord :');
    expect(q('modules-error')?.textContent).toContain('Clients');
    expect(q('modules-error')?.textContent).not.toContain('Produits');
    expect(switchOf('invoices').getAttribute('aria-checked')).toBe('false');
  });

  it('shows a reader the modules without a way to switch them', async () => {
    auth.hasPermission.mockReturnValue(false);
    await open();

    expect(switchOf('customers').disabled).toBe(true);
  });
});
