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
import { Feedback } from '../shared/feedback/feedback';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { Session } from '../shared/session/session';
import { ThemeFacade } from '../shared/theme/theme-facade';
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
        quotes: 'Devis et commandes',
        zakat: 'Zakat',
      },
      coming: {
        version: { v1: 'Version 1', later: 'Plus tard' },
        quotes: { does: 'Un devis que le client accepte devient la commande.' },
        zakat: { does: 'Calculer la zakat de l’entreprise.' },
      },
      shell: { soon: 'Bientôt' },
      company: {
        modules: {
          title: 'Modules',
          planned: 'Ce qui arrive',
          depends_on: 'Nécessite :',
          notify: 'Me prévenir',
          notified: 'Vous serez prévenu',
          unnotify: 'Ne plus me prévenir',
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
const quotes: ModuleRow = {
  key: 'quotes',
  labelKey: 'modules.quotes',
  dependencies: ['customers', 'invoices'],
  permissions: [],
  enabled: false,
  planned: 'v1',
};
const zakat: ModuleRow = {
  key: 'zakat',
  labelKey: 'modules.zakat',
  dependencies: [],
  permissions: [],
  enabled: false,
  planned: 'later',
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
    refresh: vi.fn(async () => undefined),
    switch: vi.fn(),
    setInterest: vi.fn(),
    clearError: vi.fn(),
  };
  const showComing = signal(true);
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
    showComing.set(true);
    error.set(null);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.switch.mockReset().mockResolvedValue(true);
    facade.setInterest.mockReset().mockResolvedValue(true);
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
        { provide: ThemeFacade, useValue: { showComing } },
        { provide: Session, useExisting: AuthFacade },
        ...provideQuietFeedback(),
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

  // docs/SPEC.md § 7, 2026-09-26 10:08 (row 150): the complete product, what is not built yet said « Bientôt ».
  it('lists the planned modules apart, each saying what it will do, when and what it needs, its switch off for good', async () => {
    modules.set([customers, invoices, quotes, zakat]);
    await open();

    expect(q('modules-list')?.querySelector('[data-testid="module-quotes"]')).toBeNull();
    const planned = q('modules-planned')!;
    expect(planned.textContent).toContain('Ce qui arrive');
    const row = q('module-quotes')!;
    expect(planned.contains(row)).toBe(true);
    const text = row.textContent?.replace(/\s+/g, ' ') ?? '';
    for (const words of [
      'Devis et commandes',
      'Bientôt',
      'Un devis que le client accepte',
      'Version 1',
      'Nécessite : Clients, Factures',
    ]) {
      expect(text).toContain(words);
    }
    expect(q('module-zakat')?.textContent).toContain('Plus tard');
    expect(switchOf('quotes').disabled).toBe(true);
    expect(switchOf('quotes').getAttribute('aria-checked')).toBe('false');
    switchOf('quotes').click();
    expect(facade.switch).not.toHaveBeenCalled();

    showComing.set(false);
    await settle();
    expect(q('modules-planned')).toBeNull();
    expect(q('module-customers')).not.toBeNull();
  });

  // « Me prévenir » (row 150): the company asks to be told when a planned module arrives, and can stop asking.
  it('asks to be told when a planned module arrives, says so, and can stop asking', async () => {
    modules.set([
      customers,
      invoices,
      { ...quotes, interested: false },
      { ...zakat, interested: true },
    ]);
    await open();
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;

    expect(q('module-notify-quotes')?.textContent).toContain('Me prévenir');
    expect(q('module-notified-quotes')).toBeNull();
    expect(q('module-notify-customers')).toBeNull();
    (q('module-notify-quotes') as HTMLButtonElement).click();
    await settle();
    expect(facade.setInterest).toHaveBeenCalledWith('c1', 'quotes', true);
    expect(said).toEqual([
      {
        kind: 'success',
        key: 'company.modules.notify_saved',
        params: { label: 'Devis et commandes' },
      },
    ]);

    expect(q('module-notified-zakat')?.textContent).toContain('Vous serez prévenu');
    expect(q('module-notify-zakat')?.textContent).toContain('Ne plus me prévenir');
    (q('module-notify-zakat') as HTMLButtonElement).click();
    await settle();
    expect(facade.setInterest).toHaveBeenCalledWith('c1', 'zakat', false);
    expect(said[1]).toEqual({
      kind: 'success',
      key: 'company.modules.unnotify_saved',
      params: { label: 'Zakat' },
    });
  });

  it('says nothing when the API refused « Me prévenir », and offers it to no reader', async () => {
    modules.set([customers, invoices, { ...quotes, interested: false }]);
    facade.setInterest.mockResolvedValue(false);
    await open();

    (q('module-notify-quotes') as HTMLButtonElement).click();
    await settle();
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);

    auth.hasPermission.mockReturnValue(false);
    await open();
    expect(q('module-notify-quotes')).toBeNull();
  });
});
