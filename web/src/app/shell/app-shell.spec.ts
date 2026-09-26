// SPDX-License-Identifier: AGPL-3.0-or-later

import { BreakpointObserver } from '@angular/cdk/layout';
import { Component, computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { BehaviorSubject, map, of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import type { SignedInState } from '../auth/auth-types';
import { CompanyFacade } from '../company/company-facade';
import { NotificationsFacade } from '../notifications/notifications-facade';
import { Feedback } from '../shared/feedback/feedback';
import { RequestActivity } from '../shared/feedback/request-activity';
import { RecordedFeedback } from '../shared/testing/feedback';
import { LanguageFacade } from '../shared/i18n/language-facade';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { AppShell } from './app-shell';
import type { ScreenAction } from '../shared/actions/screen-action';
import { ScreenActions } from '../shared/actions/screen-actions';
import { DEFAULT_SHORTCUTS } from '../shared/actions/shortcuts';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import { ShortcutsSheet } from '../shared/actions/shortcuts-sheet';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { ProductScanCard, type ProductScanCardData } from '../products/product-scan-card';
import { ProductOnView } from '../products/product-on-view';
import { Camera } from '../shared/scan/camera';
import { CustomerView } from '../shared/customer-view/customer-view';
import { PhonePairing } from '../shared/scan/phone-pairing';
import { PhonePairingDialog } from '../shared/scan/phone-pairing-dialog';
import { ScanBus } from '../shared/scan/scan-bus';
import { SCAN_GAP_MS } from '../shared/scan/scan-wedge';
import { CommandPalette } from './command-palette';
import type { Command } from './commands';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      app: { name: 'twes-in' },
      auth: { logout: 'Se déconnecter' },
      roles: { owner: 'Propriétaire' },
      customers: { new_title: 'Nouveau client' },
      invoices: { new_title: 'Nouvelle facture' },
      modules: { register: 'Caisse', reports: 'Rapports' },
      coming: { register: { create: 'Nouvelle vente au comptoir' } },
      nav: {
        home: 'Accueil',
        members: 'Membres',
        customers: 'Clients',
        products: 'Produits',
        vendors: 'Fournisseurs',
        expenses: 'Dépenses',
        design: 'Design',
        invoices: 'Factures',
        watch: 'À surveiller',
        delivery_notes: 'Bons de livraison',
        short: { delivery_notes: 'Livraisons' },
        sections: { sell: 'Vendre', manage: 'Gérer', team: 'Équipe' },
      },
      shell: {
        menu: 'Menu',
        user_menu: 'Compte',
        language: 'Langue',
        scheme: 'Thème',
        more: 'Plus',
        collapse_menu: 'Réduire le menu',
        expand_menu: 'Déployer le menu',
        settings: 'Paramètres',
        create: 'Créer',
        soon: 'Bientôt',
        commands: {
          open: 'Rechercher',
          find: 'Rechercher un client, une facture, un produit…',
          search: 'Rechercher…',
        },
      },
      appearance: {
        scheme_menu: 'Thème : {{current}}',
        language_menu: 'Langue : {{current}}',
        schemes: { auto: 'Automatique', light: 'Clair', dark: 'Sombre' },
      },
    });
  }
}

const owner: SignedInState = {
  user: {
    id: '1',
    email: 'amel@example.test',
    displayName: 'Amel Ben Salah',
    locale: 'fr',
    isPlatformOperator: false,
  },
  company: {
    id: 'c1',
    name: 'Demo',
    countryCode: 'TN',
    currency: 'TND',
    locale: 'fr',
    timezone: 'Africa/Tunis',
    status: 'active',
    role: 'owner',
    access: 'full' as const,
    subscription: null,
  },
  permissions: ['*'],
  modules: ['customers'],
  mfa: { enrolled: false, required: false, totp: false, passkeys: 0 },
  // The API's catalogue (row 150): the menu's planned modules come from here, never from a list of the web's own.
  plannedModules: [
    { key: 'register', planned: 'v1' },
    { key: 'reports', planned: 'v1' },
  ],
};

/** A viewport of a given width: answers `(max-width: …)` and `(min-width: …)` queries as a browser would. */
function viewport(width: BehaviorSubject<number>) {
  const matches = (query: string, px: number) => {
    const [, kind, value] = /\((max|min)-width:\s*([\d.]+)px\)/.exec(query) ?? [];
    return kind === 'max' ? px <= Number(value) : px >= Number(value);
  };
  return {
    observe: (queries: string | string[]) =>
      width.pipe(
        map((px) => {
          const list = Array.isArray(queries) ? queries : [queries];
          const breakpoints = Object.fromEntries(list.map((q) => [q, matches(q, px)]));
          return { matches: Object.values(breakpoints).some(Boolean), breakpoints };
        }),
      ),
    isMatched: (query: string) => matches(query, width.value),
  };
}

@Component({ template: '' })
class BlankPage {}

/** Counts how many times it was built, which is how a rebuilt screen is told from a kept one. */
@Component({ template: '' })
class CountingPage {
  static built = 0;
  constructor() {
    CountingPage.built += 1;
  }
}

/** Lets every queued scan be worked through. */
const settled = () => new Promise((resolve) => setTimeout(resolve));

/** Longer than the gap between two keys of a scan, which a shortcut waits out before it runs. */
function pause(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, SCAN_GAP_MS + 10));
}

/**
 * Declares actions the way a screen does — a signal, from an injection context — so the shell reads them through
 * the real registry rather than a stand-in that could agree with a broken one.
 */
function declareScreenActions(actions: readonly ScreenAction[]): void {
  const source = signal(actions);
  TestBed.runInInjectionContext(() => TestBed.inject(ScreenActions).declare(source));
}

describe('AppShell', () => {
  const pairing = {
    state: signal<{ id: string; url: string; phone: 'waiting' | 'connected' } | null>(null),
    open: vi.fn(async () => undefined),
    end: vi.fn(),
  };
  const customerActive = signal(false);
  const customerView = {
    active: customerActive.asReadonly(),
    toggle: () => customerActive.update((on) => !on),
    off: () => customerActive.set(false),
  };
  const width = new BehaviorSubject(1280);
  const me = signal<SignedInState | null>(owner);
  const permissions = signal<readonly string[]>(['user.read']);
  const modules = signal<readonly string[]>(['customers']);
  const auth = {
    me: me.asReadonly(),
    subscription: computed(() => me()?.company?.subscription ?? null),
    access: computed(() => me()?.company?.access ?? 'full'),
    logout: vi.fn(async () => undefined),
    sessionEnded: vi.fn(),
    hasPermission: (permission: string) => permissions().includes(permission),
    hasModule: (module: string) => modules().includes(module),
  };
  const companies = {
    companies: signal([]).asReadonly(),
    current: computed(() => me()?.company ?? null),
    canSwitch: signal(false).asReadonly(),
    switching: signal(false).asReadonly(),
    load: vi.fn(async () => []),
    switchTo: vi.fn(),
  };
  const theme = {
    scheme: signal<'light' | 'dark'>('light'),
    preference: signal<'auto' | 'light' | 'dark'>('auto'),
    setScheme: vi.fn(),
    density: signal<'comfortable' | 'compact'>('comfortable'),
    toggleDensity: vi.fn(),
    sidebar: signal<'expanded' | 'rail'>('expanded'),
    showComing: signal(true),
    settingsSidebar: signal<'expanded' | 'rail'>('rail'),
    toggleSidebar: vi.fn(),
  };
  const language = { current: signal('fr'), use: vi.fn(async () => undefined) };
  const sessionExpired = signal(false);
  const activity = {
    busy: signal(false),
    slow: signal(false),
    unavailable: signal(false),
    retryIn: signal(null),
    offline: signal(false),
    sessionExpired,
    retryNow: vi.fn(),
    acknowledgeExpiry: vi.fn(() => sessionExpired.set(false)),
  };

  beforeEach(async () => {
    me.set(owner);
    customerActive.set(false);
    permissions.set(['user.read']);
    modules.set(['customers']);
    theme.sidebar.set('expanded');
    theme.settingsSidebar.set('rail');
    theme.showComing.set(true);
    sessionExpired.set(false);
    width.next(1280);
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [AppShell],
      providers: [
        // Two addresses to move between: one inside the settings area and one outside it.
        provideRouter([
          { path: 'members', component: BlankPage },
          { path: 'account', component: BlankPage },
          { path: 'invoices', component: BlankPage },
          { path: 'customers', component: CountingPage },
        ]),
        { provide: BreakpointObserver, useValue: viewport(width) },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: CompanyFacade, useValue: companies },
        { provide: ThemeFacade, useValue: theme },
        { provide: LanguageFacade, useValue: language },
        { provide: RequestActivity, useValue: activity },
        { provide: Feedback, useClass: RecordedFeedback },
        { provide: Camera, useValue: { available: () => true } },
        { provide: PhonePairing, useValue: pairing },
        { provide: CustomerView, useValue: customerView },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        {
          provide: NotificationsFacade,
          useValue: {
            items: signal([]).asReadonly(),
            unread: signal(0).asReadonly(),
            error: signal(false).asReadonly(),
            connect: vi.fn(),
            disconnect: vi.fn(),
            refresh: vi.fn(async () => undefined),
            markRead: vi.fn(),
            markAllRead: vi.fn(),
          },
        },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(AppShell);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const byTestId = (id: string) =>
      document.querySelector<HTMLElement>(`[data-testid="${id}"]`) ?? null;
    const click = async (id: string) => {
      const target = byTestId(id);
      if (!target) {
        throw new Error(`no element with data-testid="${id}"`);
      }
      target.click();
      await fixture.whenStable();
    };
    return { fixture, el, byTestId, click };
  }

  it('carries the subscription notice above the page when a period is ending', async () => {
    me.set({
      ...owner,
      company: {
        ...owner.company!,
        subscription: {
          stage: 'grace' as const,
          coveredUntil: '2026-09-15T23:59:59+01:00',
          graceEndsAt: '2026-09-22T23:59:59+01:00',
          daysLeft: 5,
        },
      },
    });

    const { byTestId } = await render();

    expect(byTestId('subscription-notice')).not.toBeNull();
    me.set(owner);
  });

  it('leads the rail with the working company, where the product name was', async () => {
    // docs/SPEC.md § 7, 2026-09-25 09:03 and the round-6 rail board: the company a person acts for heads the rail.
    const { byTestId } = await render();
    const company = byTestId('rail-company');
    expect(company?.closest('[data-testid="shell-nav"]')).not.toBeNull();
    expect(company?.textContent).toContain('Demo');
    expect(company?.textContent).toContain('TND');
    expect(byTestId('brand')).toBeNull();
    expect(byTestId('nav-home')?.textContent).toContain('Accueil');
    // Each settings page lives in the settings area, not in the sidebar, which has one way in to all of them.
    expect(byTestId('nav-members')).toBeNull();
  });

  it('groups the rail under Vendre and Gérer, each under its own heading', async () => {
    permissions.set(['customer.read', 'company.read']);
    const { byTestId } = await render();
    const sell = document.querySelector('[aria-labelledby="nav-section-sell"]');
    const manage = document.querySelector('[aria-labelledby="nav-section-manage"]');
    expect(document.getElementById('nav-section-sell')?.textContent).toContain('Vendre');
    expect(document.getElementById('nav-section-manage')?.textContent).toContain('Gérer');
    expect(sell?.contains(byTestId('nav-home'))).toBe(true);
    expect(sell?.contains(byTestId('nav-customers'))).toBe(true);
    expect(manage?.contains(byTestId('nav-watch'))).toBe(true);
  });

  it('keeps Notifications, Paramètres and the member at the foot of the rail, in that order', async () => {
    const { byTestId } = await render();
    const nav = byTestId('shell-nav')!;
    const order = ['notification-bell', 'nav-settings', 'user-menu'].map((id) => byTestId(id));
    for (const control of order) expect(nav.contains(control)).toBe(true);
    expect(
      order[0]!.compareDocumentPosition(order[1]!) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    expect(
      order[1]!.compareDocumentPosition(order[2]!) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy();
    // The member row says who and in what role, the way the board draws it.
    expect(byTestId('user-menu')?.textContent).toContain('Amel Ben Salah');
    expect(byTestId('user-menu')?.textContent).toContain('Propriétaire');
    expect(byTestId('user-menu')?.textContent).toContain('AS');
  });

  it('offers what the person may create from « Créer », and the C key opens it outside a field', async () => {
    permissions.set(['customer.read', 'customer.write']);
    const { byTestId, el } = await render();
    const create = byTestId('create-open');
    expect(create?.closest('[data-testid="shell-nav"]')).not.toBeNull();
    expect(create?.textContent).toContain('Créer');
    expect(create?.getAttribute('aria-keyshortcuts')).toBe('c');

    // Typed into a field it is a letter, and it never opens anything.
    const input = document.createElement('input');
    el.appendChild(input);
    input.dispatchEvent(new KeyboardEvent('keydown', { key: 'c', bubbles: true }));
    await pause();
    input.remove();
    expect(document.querySelector('[data-testid="create-new-customer"]')).toBeNull();

    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'c', bubbles: true }));
    await pause();
    expect(byTestId('create-new-customer')?.textContent).toContain('Nouveau client');
    // Only what this person may create in this company: invoices are off for it.
    expect(byTestId('create-new-invoice')).toBeNull();
    // What a planned module will create comes after a divider, marked, and opens its page (row 150).
    const planned = byTestId('create-new-register');
    expect(planned?.getAttribute('href')).toBe('/coming/register');
    expect(planned?.querySelector('[data-testid="soon"]')?.textContent).toContain('Bientôt');
    expect(byTestId('create-new-customer')?.querySelector('[data-testid="soon"]')).toBeNull();
    expect(planned?.previousElementSibling?.tagName.toLowerCase()).toBe('mat-divider');
  });

  it('answers the keys this person chose rather than the ruled ones (row 125)', async () => {
    permissions.set(['customer.read', 'customer.write']);
    TestBed.inject(SettingsFacade).set(PRESENTATION.shortcuts, {
      ...DEFAULT_SHORTCUTS,
      create: 'k',
    });
    const { byTestId } = await render();
    expect(byTestId('create-open')?.getAttribute('aria-keyshortcuts')).toBe('k');
    expect(byTestId('create-open')?.querySelector('kbd')?.textContent).toBe('K');

    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'c', bubbles: true }));
    await pause();
    expect(byTestId('create-new-customer')).toBeNull();

    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', bubbles: true }));
    await pause();
    expect(byTestId('create-new-customer')).not.toBeNull();
  });

  it('shows the vision’s entries not built yet, marked « Bientôt », and hides them all when asked', async () => {
    // docs/SPEC.md § 7, 2026-09-25 11:17: the whole vision shows, marked, and one preference hides it.
    const { fixture, byTestId } = await render();
    const register = byTestId('nav-register');
    expect(register?.getAttribute('href')).toBe('/coming/register');
    expect(register?.textContent).toContain('Caisse');
    expect(register?.querySelector('[data-testid="soon"]')?.textContent).toContain('Bientôt');
    expect(byTestId('nav-home')?.querySelector('[data-testid="soon"]')).toBeNull();

    theme.showComing.set(false);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('nav-register')).toBeNull();
    expect(byTestId('nav-home')).not.toBeNull();
  });

  it('draws the planned modules the API lists, and none it no longer does', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('nav-register')).not.toBeNull();

    me.set({ ...owner, plannedModules: [{ key: 'reports', planned: 'v1' }] });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('nav-register')).toBeNull();
  });

  it('keeps what is not built yet off the phone’s bar, which holds only working destinations', async () => {
    permissions.set([]);
    modules.set([]);
    width.next(390);
    const { byTestId } = await render();
    const labels = [...(byTestId('bottom-bar')?.querySelectorAll('a') ?? [])].map((a) =>
      a.querySelector('span')?.textContent?.trim(),
    );
    expect(labels).toEqual(['Accueil']);
  });

  it('draws no « Créer » for somebody who may create nothing', async () => {
    permissions.set([]);
    const { byTestId } = await render();
    expect(byTestId('create-open')).toBeNull();
  });

  it('reaches the settings from the sidebar and from nowhere else', async () => {
    // Design review finding 8: a gear in the top bar and an entry in the sidebar opened the same area. The rail is
    // what shows you are in settings (finding 7), so the entry belongs there and the gear goes.
    const { byTestId } = await render();
    const entry = byTestId('nav-settings');
    expect(entry?.closest('[data-testid="shell-nav"]')).not.toBeNull();
    expect(entry?.getAttribute('href')).toBe('/members');
    expect(entry?.textContent).toContain('Paramètres');
    expect(byTestId('settings-gear')).toBeNull();
  });

  it('puts the language and the scheme in the member’s menu at every width', async () => {
    // The top bar gave them its room (direction § 4.1): the rail's member row carries them from 1200 px as on a phone.
    const { click, byTestId } = await render();
    expect(byTestId('language-menu')).toBeNull();
    expect(byTestId('scheme-menu')).toBeNull();

    await click('user-menu');
    expect(byTestId('account-scheme-auto')?.getAttribute('aria-checked')).toBe('true');
    expect(byTestId('account-settings')).toBeNull();
    // « Mon compte » (docs/SPEC.md § 7, 2026-09-25 17:22): the person's own account, first of the menu.
    expect(byTestId('account-page-link')?.getAttribute('href')).toBe('/account');
    await click('account-scheme-dark');
    expect(theme.setScheme).toHaveBeenCalledWith('dark');
  });

  it('folds the language and the scheme into the account menu on a phone', async () => {
    width.next(390);
    const { click, byTestId } = await render();
    expect(byTestId('language-menu')).toBeNull();
    expect(byTestId('scheme-menu')).toBeNull();

    await click('user-menu');
    expect(byTestId('account-scheme-auto')?.getAttribute('aria-checked')).toBe('true');
    await click('account-language-en');
    expect(language.use).toHaveBeenCalledWith('en');

    await click('user-menu');
    await click('account-scheme-dark');
    expect(theme.setScheme).toHaveBeenCalledWith('dark');
  });

  // docs/SPEC.md § 7, 2026-09-26 08:52 (row 146): the search is out of the menu, at the top bar's centre, always there.
  it('puts the search at the top bar’s centre, out of the menu, saying what it finds', async () => {
    const { fixture, byTestId, el } = await render();
    const search = byTestId('command-open');
    expect(search?.closest('[data-testid="shell-nav"]')).toBeNull();
    expect(search?.closest('[data-testid="top-bar-centre"]')).not.toBeNull();
    expect(search?.textContent).toContain('Rechercher…');
    expect(search?.textContent).toContain('Ctrl K');
    expect(search?.getAttribute('aria-label')).toBe('Rechercher');
    expect(el.querySelectorAll('[data-testid^="command-open"]')).toHaveLength(1);

    // With the menu folded to a rail of icons, the search stays where it is, whole.
    width.next(900);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('command-open')?.closest('[data-testid="top-bar-centre"]')).not.toBeNull();
    expect(byTestId('command-open')?.textContent).toContain('Rechercher…');
  });

  it('keeps a slim bar above the page, its scanning controls at the end', async () => {
    permissions.set(['product.read']);
    const { fixture, byTestId, el } = await render();
    const bar = el.querySelector('.twes-shell-bar');
    expect(bar?.contains(byTestId('camera-open'))).toBe(true);
    expect(bar?.contains(byTestId('phone-pair'))).toBe(true);
    expect(bar?.contains(byTestId('user-menu'))).toBe(false);

    // With no product to scan the phone has nothing to do, and the bar keeps only the camera.
    permissions.set([]);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(el.querySelector('.twes-shell-bar [data-testid="phone-pair"]')).toBeNull();
  });

  it('names every rail control that shows no words once folded, with a tooltip', async () => {
    permissions.set(['customer.write', 'customer.read', 'user.read']);
    theme.sidebar.set('rail');
    const { byTestId } = await render();
    for (const id of ['sidebar-toggle', 'create-open', 'nav-settings']) {
      expect(byTestId(id)?.classList.contains('mat-mdc-tooltip-trigger'), id).toBe(true);
    }
  });

  it('keeps a short label under each destination’s icon once folded, as at 1024 px', async () => {
    // docs/SPEC.md § 7, 2026-09-24 22:51 (row 123): the 80 px rail keeps a short label under each icon.
    permissions.set(['delivery_note.read', 'user.read']);
    modules.set(['delivery_notes']);
    theme.sidebar.set('rail');
    const { byTestId } = await render();
    const home = byTestId('nav-home');
    expect(home?.querySelector('.twes-rail-short')?.textContent?.trim()).toBe('Accueil');
    expect(home?.querySelector('.sr-only')).toBeNull();
    expect(home?.classList.contains('mat-mdc-tooltip-trigger')).toBe(false);
    expect(
      byTestId('nav-delivery-notes')?.querySelector('.twes-rail-short')?.textContent?.trim(),
    ).toBe('Livraisons');
    // What is not built yet still says so, folded: a dot to see, the word to hear.
    const register = byTestId('nav-register');
    expect(register?.querySelector('[data-testid="soon"]')?.textContent).toContain('Bientôt');
    expect(register?.querySelector('.twes-soon-dot')).not.toBeNull();
    // Each label is Material's title, never the default slot, which draws a row two lines tall (84 px measured).
    for (const id of ['nav-home', 'nav-register']) {
      expect(
        byTestId(id)?.querySelector('.mat-mdc-list-item-unscoped-content .twes-rail-short'),
        id,
      ).toBeNull();
      expect(
        byTestId(id)?.querySelector('.mdc-list-item__primary-text.twes-rail-short'),
        id,
      ).not.toBeNull();
    }
  });

  // 2026-09-26 sidebar review: the folded rail keeps its sections apart, and « Mon compte » says where the person is.
  it('keeps each section apart by a line once folded, its name still read', async () => {
    modules.set(['customers', 'inventory']);
    permissions.set(['customer.read', 'user.read', 'stock.read']);
    theme.sidebar.set('rail');
    const { el } = await render();
    const headings = [...el.querySelectorAll<HTMLElement>('.twes-rail-overline')];
    expect(headings.length).toBeGreaterThan(1);
    for (const heading of headings) {
      expect(heading.classList.contains('sr-only'), heading.textContent ?? '').toBe(false);
      expect(heading.classList.contains('is-divider')).toBe(true);
      expect(heading.querySelector('.sr-only')?.textContent?.trim()).not.toBe('');
    }
  });

  it('marks the member as where the person is on « Mon compte »', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('user-menu')?.getAttribute('aria-current')).toBeNull();

    await TestBed.inject(Router).navigateByUrl('/account');
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('user-menu')?.getAttribute('aria-current')).toBe('page');
    expect(byTestId('user-menu')?.classList.contains('is-current')).toBe(true);
  });

  it('rebuilds the open screen when the company changes, so it never shows the last one’s rows', async () => {
    // The session refreshes on a switch, but a page that loaded its data in its constructor kept showing the
    // previous company's until the browser was refreshed (developer, 2026-09-20).
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/customers');
    const { fixture } = await render();
    CountingPage.built = 0;
    fixture.detectChanges();
    await fixture.whenStable();
    const before = CountingPage.built;

    me.set({ ...owner, company: { ...owner.company!, id: 'c2', name: 'Autre' } });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(CountingPage.built).toBe(before + 1);
  });

  it('keeps the screen it has when the session refreshes without changing company', async () => {
    // Rebuilding on every refresh would throw away a half-filled form each time the shell re-reads the session.
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/customers');
    const { fixture } = await render();
    CountingPage.built = 0;
    fixture.detectChanges();
    await fixture.whenStable();
    const before = CountingPage.built;

    me.set({ ...owner, user: { ...owner.user, displayName: 'Amel B. Salah' } });
    fixture.detectChanges();
    await fixture.whenStable();

    expect(CountingPage.built).toBe(before);
  });

  it('opens the settings on the first settings page the user may see', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('nav-settings')?.getAttribute('href')).toBe('/members');

    permissions.set(['user.read', 'company.settings']);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('nav-settings')?.getAttribute('href')).toBe('/company/profile');
  });

  it('keeps the full menu beside the settings list, as the approved board draws it', async () => {
    // The round-6 settings board shows the labelled rail beside the area's own list; the area still takes the whole
    // width, with no gutter and no cap, so its list docks against the rail.
    theme.settingsSidebar.set('expanded');
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/members');
    const { el, byTestId, fixture } = await render();

    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
    const main = el.querySelector('main');
    expect(main?.className).not.toContain('max-w-6xl');
    expect(main?.getAttribute('data-settings')).toBe('true');

    await router.navigateByUrl('/invoices');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(el.querySelector('main')?.className).toContain('max-w-6xl');
    expect(el.querySelector('main')?.getAttribute('data-settings')).toBe('false');
  });

  it('lets the menu be folded inside settings, remembered apart from the rest', async () => {
    theme.settingsSidebar.set('expanded');
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/members');
    const { byTestId, click, fixture } = await render();

    await click('sidebar-toggle');
    expect(theme.toggleSidebar).toHaveBeenCalledWith(true);

    theme.settingsSidebar.set('rail');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');

    await router.navigateByUrl('/invoices');
    fixture.detectChanges();
    await fixture.whenStable();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
  });

  it('writes the [ key to the menu the person is looking at', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/members');
    const { fixture } = await render();

    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true }));
    expect(theme.toggleSidebar).toHaveBeenLastCalledWith(true);

    await router.navigateByUrl('/invoices');
    fixture.detectChanges();
    await fixture.whenStable();
    // A hand needs far longer than a scanner between two keys; two presses within SCAN_GAP_MS read as a scan.
    await new Promise((resolve) => setTimeout(resolve, SCAN_GAP_MS + 10));
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true }));
    expect(theme.toggleSidebar).toHaveBeenLastCalledWith(false);
  });

  it('shows the working company at the head of a phone’s bar, with the search, the bell and the member', async () => {
    width.next(390);
    const { byTestId, el } = await render();

    const company = byTestId('company-name');
    expect(company?.closest('header')).not.toBeNull();
    const leading = el.querySelector('[data-testid="top-bar-leading"]');
    expect(leading?.contains(company as Node)).toBe(true);
    expect(company?.className).toContain('truncate');
    for (const id of ['command-open', 'notification-bell', 'user-menu']) {
      expect(byTestId(id)?.closest('header'), id).not.toBeNull();
    }
    expect(byTestId('rail-company')).toBeNull();
  });

  it('keeps the settings under Plus on a phone, not in the account menu as well', async () => {
    // On a phone the drawer IS "Plus", so the sidebar entry is already the one way in; a second entry in the
    // account menu is the pair of controls finding 8 removed, one window class down.
    width.next(390);
    const { click, byTestId } = await render();
    expect(byTestId('settings-gear')).toBeNull();
    // The list rather than a page: at this width the two do not fit side by side.
    expect(byTestId('nav-settings')?.getAttribute('href')).toBe('/company');
    await click('user-menu');
    expect(byTestId('account-settings')).toBeNull();
  });

  it('keeps every part of the shell inside a landmark, each named once', async () => {
    const { el } = await render();

    expect(el.querySelector('[data-testid="rail-company"]')?.closest('nav')).not.toBeNull();
    expect(el.querySelector('[data-testid="user-menu"]')?.closest('nav')).not.toBeNull();
    const lists = [...el.querySelectorAll<HTMLElement>('mat-nav-list')];
    expect(lists.length).toBeGreaterThan(0);
    const names = lists.map(
      (list) =>
        list.getAttribute('aria-label') ??
        el.querySelector(`#${list.getAttribute('aria-labelledby')}`)?.textContent?.trim(),
    );
    expect(names).toEqual(['Vendre', 'Paramètres']);
  });

  it('hides an entry whose permission the user lacks', async () => {
    permissions.set([]);
    const { byTestId } = await render();
    expect(byTestId('nav-home')).not.toBeNull();
    expect(byTestId('settings-gear')).toBeNull();
  });

  it("shows a module's entries only while the company has the module on", async () => {
    permissions.set(['customer.read']);
    const { fixture, byTestId } = await render();
    expect(byTestId('nav-customers')?.textContent).toContain('Clients');

    modules.set([]);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('nav-customers')).toBeNull();
    expect(byTestId('nav-home')).not.toBeNull();
  });

  it('collapses the sidebar to a rail from its own foot, every entry still named', async () => {
    const { fixture, byTestId, click } = await render();
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
    expect(byTestId('sidebar-toggle')?.closest('[data-testid="shell-nav"]')).not.toBeNull();
    expect(byTestId('sidebar-toggle')?.getAttribute('aria-label')).toBe('Réduire le menu');
    expect(byTestId('nav-home')?.querySelector('.twes-rail-short')).toBeNull();

    await click('sidebar-toggle');
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(1);

    theme.sidebar.set('rail');
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');
    expect(byTestId('sidebar-toggle')?.getAttribute('aria-label')).toBe('Déployer le menu');
    expect(byTestId('nav-home')?.querySelector('.twes-rail-short')?.textContent).toContain(
      'Accueil',
    );
  });

  it('toggles the sidebar with the [ key, but not while typing or with a shortcut', async () => {
    const { el } = await render();
    const press = (target: EventTarget, init: KeyboardEventInit = {}) =>
      target.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true, ...init }));

    press(document.body);
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(1);

    const input = document.createElement('input');
    el.appendChild(input);
    press(input);
    press(document.body, { ctrlKey: true });
    press(document.body, { metaKey: true });
    input.remove();
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(1);

    // AltGr types [ on a French PC keyboard and reports Ctrl and Alt together; Option does on a French Mac, Alt alone.
    press(document.body, { ctrlKey: true, altKey: true });
    // A hand needs far longer than a scanner between two keys; two presses within SCAN_GAP_MS read as a scan.
    await new Promise((resolve) => setTimeout(resolve, SCAN_GAP_MS + 10));
    press(document.body, { altKey: true });
    expect(theme.toggleSidebar).toHaveBeenCalledTimes(3);
  });

  it('lays the navigation out by window width: labelled from 1200 px, an 80 px rail with short labels below', async () => {
    const { fixture, byTestId } = await render();
    expect(byTestId('shell-nav')?.getAttribute('data-window')).toBe('expanded');
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('expanded');
    expect(byTestId('bottom-bar')).toBeNull();

    width.next(900);
    fixture.detectChanges();
    await fixture.whenStable();

    expect(byTestId('shell-nav')?.getAttribute('data-window')).toBe('medium');
    // The setting says expanded, but a medium window has no room for labels: the rail is not a choice there.
    expect(byTestId('shell-nav')?.getAttribute('data-sidebar')).toBe('rail');
    expect(byTestId('nav-home')?.querySelector('.twes-rail-short')?.textContent).toContain(
      'Accueil',
    );
    expect(byTestId('sidebar-toggle')).toBeNull();
    expect(byTestId('bottom-bar')).toBeNull();
  });

  it("ignores the [ key below 1200 px, where the rail is not the person's choice", async () => {
    width.next(900);
    await render();
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key: '[', bubbles: true }));
    expect(theme.toggleSidebar).not.toHaveBeenCalled();
  });

  it('puts Accueil, Factures, Créer, Clients and Plus in a bar at the bottom of a phone', async () => {
    // The round-6 phone boards: the two most used destinations, « Créer » in the thumb's middle, then Clients.
    permissions.set(['invoice.read', 'customer.read', 'customer.write', 'product.read']);
    modules.set(['invoices', 'customers', 'products']);
    width.next(390);
    const { byTestId, click } = await render();

    const bar = byTestId('bottom-bar');
    expect(bar?.tagName).toBe('NAV');
    const items = [...(bar?.children ?? [])].map((item) => item.textContent?.replace(/\s+/g, ''));
    expect(items).toEqual([
      'homeAccueil',
      'receipt_longFactures',
      'addCréer',
      'contactsClients',
      'menuPlus',
    ]);
    expect(byTestId('bottom-nav-home')?.getAttribute('href')).toBe('/');
    expect(byTestId('create-open')?.closest('[data-testid="bottom-bar"]')).toBe(bar);
    expect(byTestId('shell-nav')?.getAttribute('data-window')).toBe('compact');
    expect(byTestId('sidebar-toggle')).toBeNull();

    await click('create-open');
    expect(byTestId('create-new-customer')).not.toBeNull();
    (document.querySelector('.cdk-overlay-backdrop') as HTMLElement | null)?.click();
    await settled();

    // Everything else is one tap away: Plus opens the full drawer.
    await click('menu-toggle');
    expect(document.querySelector('mat-sidenav')?.classList.contains('mat-drawer-opened')).toBe(
      true,
    );
  });

  it('fills the phone bar from the next destinations when a module is off', async () => {
    permissions.set(['customer.read', 'product.read']);
    modules.set(['customers', 'products']);
    width.next(390);
    const { byTestId } = await render();
    const labels = [...(byTestId('bottom-bar')?.querySelectorAll('a') ?? [])].map((a) =>
      a.querySelector('span')?.textContent?.trim(),
    );
    expect(labels).toEqual(['Accueil', 'Clients', 'Produits']);
  });

  it('opens the command palette with Ctrl K or ⌘ K, offering only what the user may do', async () => {
    permissions.set(['customer.read', 'customer.write', 'expense.write']);
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);
    const press = (init: KeyboardEventInit) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'k', bubbles: true, cancelable: true, ...init }),
      );

    press({ ctrlKey: true });
    expect(open).toHaveBeenCalledTimes(1);
    const [component, config] = open.mock.calls[0] as [unknown, { data: { commands: Command[] } }];
    expect(component).toBe(CommandPalette);
    // Expenses is off for this company: its creation is not offered even with the permission.
    // Then what is not built yet, from the API's catalogue, marked (row 150).
    expect(config.data.commands.map((command) => command.key)).toEqual([
      'new-customer',
      'goto-home',
      'goto-customers',
      'new-register',
      'goto-register',
      'goto-reports',
    ]);
    expect(
      config.data.commands.filter((command) => command.group !== 'screen' && command.coming),
    ).toHaveLength(3);

    press({ metaKey: true });
    expect(open).toHaveBeenCalledTimes(2);
    // AltGr+K is a character on some layouts, not a shortcut; a bare k is typing.
    press({ ctrlKey: true, altKey: true });
    press({});
    expect(open).toHaveBeenCalledTimes(2);
  });

  it('opens the shortcut sheet with ?, listing what this page and the shell answer', async () => {
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    document.body.dispatchEvent(
      new KeyboardEvent('keydown', { key: '?', bubbles: true, cancelable: true }),
    );

    expect(open).toHaveBeenCalledTimes(1);
    expect(open.mock.calls[0][0]).toBe(ShortcutsSheet);
  });

  it('runs what the screen on view declared for a key, and ignores that key while typing', async () => {
    const { el } = await render();
    let ran = 0;
    declareScreenActions([
      { id: 'save', label: 'invoices.save', shortcut: 's', run: () => (ran += 1) },
    ]);
    const press = (target: EventTarget) =>
      target.dispatchEvent(
        new KeyboardEvent('keydown', { key: 's', bubbles: true, cancelable: true }),
      );

    press(document.body);
    // A shortcut waits out the scan gap: until then the key could be the first of a scanned code.
    await pause();
    expect(ran).toBe(1);

    // The same key inside a field is the letter S, which is the reason this whole check exists.
    const input = document.createElement('input');
    el.appendChild(input);
    press(input);
    input.remove();
    await pause();
    expect(ran).toBe(1);

    // And a key no screen declared is nobody's: it must not be swallowed.
    const other = new KeyboardEvent('keydown', { key: 'z', bubbles: true, cancelable: true });
    document.body.dispatchEvent(other);
    expect(other.defaultPrevented).toBe(false);
  });

  it('runs no shortcut for the first letter of a scanned code', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    await render();
    let issued = 0;
    declareScreenActions([
      { id: 'issue', label: 'invoices.issue', next: true, run: () => (issued += 1) },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    // A supplier's own code is free text: this one starts with the key that issues an invoice.
    [...'E-4711', 'Enter'].forEach((key) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
      ),
    );
    await pause();

    expect(issued).toBe(0);
    expect(open).toHaveBeenCalledTimes(1);
  });

  it('runs the next step with E, asking first when its button would (docs/SPEC.md § 7, 2026-09-24 22:51)', async () => {
    await render();
    let saved = 0;
    let issued = 0;
    declareScreenActions([
      { id: 'save', label: 'invoices.save', shortcut: 's', primary: true, run: () => (saved += 1) },
      {
        id: 'issue',
        label: 'invoices.issue',
        next: true,
        confirm: {
          kind: 'corrigeable' as const,
          title: 't',
          message: 'm',
          confirmLabel: 'c',
          keepLabel: 'k',
        },
        run: () => (issued += 1),
      },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(true),
    } as never);
    const e = new KeyboardEvent('keydown', { key: 'E', bubbles: true, cancelable: true });

    document.body.dispatchEvent(e);
    expect(e.defaultPrevented).toBe(true);
    await pause();

    expect(open.mock.calls[0][0]).toBe(ConfirmDialog);
    expect([saved, issued]).toEqual([0, 1]);
  });

  it('leaves E to nobody on a screen whose state has no next step', async () => {
    await render();
    declareScreenActions([
      { id: 'save', label: 'invoices.save', shortcut: 's', primary: true, run: () => undefined },
    ]);
    const e = new KeyboardEvent('keydown', { key: 'e', bubbles: true, cancelable: true });
    document.body.dispatchEvent(e);

    expect(e.defaultPrevented).toBe(false);
  });

  it('opens a new document with N on its own list, and nowhere else', async () => {
    permissions.set(['customer.read', 'customer.write']);
    const { fixture } = await render();
    const router = TestBed.inject(Router);
    const press = () => {
      const n = new KeyboardEvent('keydown', { key: 'n', bubbles: true, cancelable: true });
      document.body.dispatchEvent(n);
      return n;
    };

    // On the invoices list with invoices off for this company, N has nothing to create.
    await router.navigateByUrl('/invoices');
    fixture.detectChanges();
    const go = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    expect(press().defaultPrevented).toBe(false);
    await pause();
    expect(go).not.toHaveBeenCalled();

    go.mockRestore();
    await router.navigateByUrl('/customers');
    fixture.detectChanges();
    const again = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);
    expect(press().defaultPrevented).toBe(true);
    await pause();
    expect(again).toHaveBeenCalledWith('/customers/new');
  });

  it('opens the search with /, held like any bare key since a code may carry one', async () => {
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);
    const slash = new KeyboardEvent('keydown', { key: '/', bubbles: true, cancelable: true });

    document.body.dispatchEvent(slash);
    // Taken from the browser at once: Firefox would open its quick-find on it.
    expect(slash.defaultPrevented).toBe(true);
    expect(open).not.toHaveBeenCalled();
    await pause();

    expect(open.mock.calls[0][0]).toBe(CommandPalette);
  });

  it('opens what a scan names when a scanner types a code with no field focused', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    const { el } = await render();
    let saved = 0;
    declareScreenActions([
      { id: 'save', label: 'products.save', shortcut: 's', run: () => (saved += 1) },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);
    // A wedge types the whole code and Enter within a millisecond or two of each other.
    const scan = (target: EventTarget, code: string) =>
      [...code, 'Enter'].forEach((key) =>
        target.dispatchEvent(
          new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
        ),
      );

    // docs/SPEC.md § 7, 2026-09-25 10:13: the card learns which product's page is on view.
    TestBed.inject(ProductOnView).show({ id: 'p7', reference: 'ART-007' });
    scan(document.body, '0s123');

    await vi.waitFor(() => expect(open).toHaveBeenCalledTimes(1));
    const [component, config] = open.mock.calls[0] as [unknown, { data: ProductScanCardData }];
    expect(component).toBe(ProductScanCard);
    expect(config.data.code).toBe('0s123');
    expect(config.data.onView).toEqual({ id: 'p7', reference: 'ART-007' });
    // The s inside the code is part of the scan, not the screen's save.
    expect(saved).toBe(0);

    // Inside a field the scan is the field's.
    const input = document.createElement('input');
    el.appendChild(input);
    scan(input, '3017620422003');
    input.remove();
    await settled();
    expect(open).toHaveBeenCalledTimes(1);
  });

  it('reads a scan whose characters a French keyboard types with AltGr, as a GS1 prefix is', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    // AltGr reports Ctrl and Alt together and TYPES the character: "]" is AltGr ) on AZERTY.
    [...']C10113017620422000', 'Enter'].forEach((key) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', {
          key,
          bubbles: true,
          cancelable: true,
          ...(key === ']' ? { ctrlKey: true, altKey: true } : {}),
        }),
      ),
    );

    await vi.waitFor(() => expect(open).toHaveBeenCalledTimes(1));
    expect((open.mock.calls[0] as [unknown, { data: { code: string } }])[1].data.code).toBe(
      ']C10113017620422000',
    );
  });

  it('lends a phone as a scanner to somebody who reads the products, and lets it go when the shell goes', async () => {
    permissions.set(['product.read']);
    modules.set(['products']);
    const { fixture, click } = await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    await click('phone-pair');

    expect(open).toHaveBeenCalledWith(PhonePairingDialog, expect.anything());
    fixture.destroy();
    expect(pairing.end).toHaveBeenCalled();
  });

  // docs/SPEC.md § 7, 2026-09-23 slice 5: one click hides, on this tab, what a customer must not read.
  it('turns customer view on in one click, says so above the page, and turns it off from there', async () => {
    permissions.set(['product.read', 'product.cost.read']);
    const { click, byTestId, fixture } = await render();
    expect(byTestId('customer-view-banner')).toBeNull();
    expect(byTestId('customer-view-toggle')?.getAttribute('aria-pressed')).toBe('false');

    await click('customer-view-toggle');

    expect(customerActive()).toBe(true);
    expect(byTestId('customer-view-toggle')?.getAttribute('aria-pressed')).toBe('true');
    expect(byTestId('customer-view-banner')).not.toBeNull();

    await click('customer-view-leave');
    fixture.detectChanges();
    expect(customerActive()).toBe(false);
    expect(byTestId('customer-view-banner')).toBeNull();
  });

  it('offers no customer view to somebody with no cost to hide', async () => {
    permissions.set(['product.read']);
    const { byTestId } = await render();

    expect(byTestId('customer-view-toggle')).toBeNull();
  });

  it('offers no phone to somebody who may not read the products', async () => {
    permissions.set(['customer.read']);
    const { byTestId } = await render();

    expect(byTestId('phone-pair')).toBeNull();
  });

  it('opens nothing on a scan for somebody who may not read the products', async () => {
    permissions.set(['customer.read']);
    modules.set(['products', 'customers']);
    await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    [...'3017620422003', 'Enter'].forEach((key) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true }),
      ),
    );

    await settled();
    expect(open).not.toHaveBeenCalled();
  });

  it('gives a scan to the screen that acts on it, counted as typed before it, and Ctrl Z takes it back', async () => {
    const { fixture } = await render();
    const seen: number[] = [];
    let undone = 0;
    TestBed.runInInjectionContext(() =>
      TestBed.inject(ScanBus).handle((scan) => {
        seen.push(scan.times);
        return Promise.resolve({ kind: 'done', key: 'scan.added', undo: () => (undone += 1) });
      }),
    );
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open');
    const press = (key: string, init: KeyboardEventInit = {}) =>
      document.body.dispatchEvent(
        new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init }),
      );

    // Typed by hand: the keys are far apart, as a person's are.
    press('5');
    await pause();
    press('x');
    fixture.detectChanges();
    expect(TestBed.inject(ScanBus).multiplier()).toBe(5);
    expect(fixture.nativeElement.querySelector('[data-testid="scan-count"]')).not.toBeNull();

    [...'3017620422003', 'Enter'].forEach((key) => press(key));
    await vi.waitFor(() => expect(seen).toEqual([5]));
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="scan-count"]')).toBeNull();
    expect(open).not.toHaveBeenCalled();

    press('z', { ctrlKey: true });
    expect(undone).toBe(1);
  });

  it('asks before a declared key runs something destructive, exactly as its button would', async () => {
    await render();
    let ran = 0;
    declareScreenActions([
      {
        id: 'cancel',
        label: 'invoices.cancel',
        shortcut: 'v',
        destructive: true,
        confirm: {
          kind: 'definitif' as const,
          title: 't',
          message: 'm',
          confirmLabel: 'c',
          keepLabel: 'k',
        },
        run: () => (ran += 1),
      },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(false),
    } as never);

    document.body.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'v', bubbles: true, cancelable: true }),
    );
    await pause();

    expect(open.mock.calls[0][0]).toBe(ConfirmDialog);
    expect(ran).toBe(0);
  });

  it('leads the palette with what the screen on view can do, before creating and going', async () => {
    permissions.set(['customer.read', 'customer.write']);
    await render();
    declareScreenActions([
      { id: 'issue', label: 'invoices.issue', run: () => undefined },
      // Refused for now — offering a line that answers nothing when chosen reads as a broken palette.
      { id: 'pay', label: 'invoices.pay', disabled: true, run: () => undefined },
    ]);
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    document.body.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'k', bubbles: true, cancelable: true, ctrlKey: true }),
    );

    const [, config] = open.mock.calls[0] as [unknown, { data: { commands: Command[] } }];
    expect(config.data.commands.map((command) => command.key)).toEqual([
      'screen-issue',
      'new-customer',
      'goto-home',
      'goto-customers',
      'new-register',
      'goto-register',
      'goto-reports',
    ]);
  });

  it('opens the command palette from the search in the top bar', async () => {
    const { click, byTestId } = await render();
    const open = vi.spyOn(TestBed.inject(MatDialog), 'open').mockReturnValue({
      afterClosed: () => of(undefined),
    } as never);

    expect(byTestId('command-open')?.closest('header')).not.toBeNull();
    expect(byTestId('command-open')?.getAttribute('aria-keyshortcuts')).toBe('/ Control+K Meta+K');
    // Named even on a phone, where its visible label and shortcut are hidden to save room.
    expect(byTestId('command-open')?.getAttribute('aria-label')).toBe('Rechercher');
    await click('command-open');

    expect(open).toHaveBeenCalledTimes(1);
  });

  it('sends the person back to sign in, saying why, when their session ends while the page is open', async () => {
    const { fixture } = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    sessionExpired.set(true);
    await fixture.whenStable();

    expect(auth.sessionEnded).toHaveBeenCalledOnce();
    expect(navigate).toHaveBeenCalledWith('/login?expired=1');
    expect(sessionExpired()).toBe(false);
  });

  it('forgets a session end that arrived after the last redirect, so a fresh sign-in is not sent straight back', async () => {
    // Several requests refused at once: the first sends the person away, a later one lands once the shell is gone.
    sessionExpired.set(true);
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    await render();

    expect(navigate).not.toHaveBeenCalledWith('/login?expired=1');
    expect(auth.sessionEnded).not.toHaveBeenCalled();
    expect(sessionExpired()).toBe(false);
  });

  it('names the signed-in user on the account menu', async () => {
    const { byTestId } = await render();
    expect(byTestId('user-menu')?.textContent).toContain('AS');
    expect(byTestId('user-menu')?.getAttribute('aria-label')).toContain('Amel Ben Salah');
  });

  it('signs out from the account menu and goes to the login page', async () => {
    const { click } = await render();
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);

    await click('user-menu');
    await click('logout');

    expect(auth.logout).toHaveBeenCalledTimes(1);
    expect(navigate).toHaveBeenCalledWith('/login');
  });

  it('switches to compact density from the account menu', async () => {
    const { click } = await render();
    await click('user-menu');
    await click('density-toggle');
    expect(theme.toggleDensity).toHaveBeenCalledTimes(1);
  });
});
