// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatDialog } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { SignedInState } from '../auth/auth-types';
import { formatDay, formatMoment } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { NotificationBell } from './notification-bell';
import { NotificationsFacade } from './notifications-facade';
import type { InboxEntry } from './notifications-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      notifications: {
        bell: 'Notifications, {{count}} non lues',
        title: 'Notifications',
        empty: 'Aucune notification',
        empty_unread: 'Rien de non lu',
        mark_all: 'Tout marquer comme lu',
        close: 'Fermer',
        filter: 'Afficher',
        filter_all: 'Toutes',
        filter_unread: 'Non lues',
        unread: 'Non lue',
        today: 'Aujourd’hui',
        yesterday: 'Hier',
        types: {
          membership_added: 'Vous avez rejoint {{company}}',
          invitation_accepted: '{{display_name}} a rejoint l’entreprise',
          unknown: 'Nouvelle notification',
          module_arrived: '« {{label}} » est arrivé pour {{company}}',
        },
      },
      modules: { quotes: 'Devis et commandes' },
    });
  }
}

@Component({ template: '' })
class Blank {}

const inCompany = (id: string): SignedInState =>
  ({
    user: { id: 'u1', email: 'a@b.c', displayName: 'A', locale: 'fr', isPlatformOperator: false },
    company: {
      id,
      name: id,
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
  }) satisfies SignedInState;

// The clock reads 2026-09-14 10:00 in Tunis.
const NOW = new Date('2026-09-14T09:00:00Z');

const unreadEntry: InboxEntry = {
  id: 'n1',
  type: 'membership.added',
  payload: { company: 'Acme' },
  companyId: null,
  createdAt: '2026-09-14T08:55:00+00:00',
  readAt: null,
};

describe('NotificationBell', () => {
  const me = signal<SignedInState | null>(inCompany('c1'));
  const items = signal<readonly InboxEntry[]>([]);
  const unread = signal(0);
  const permitted = signal(true);
  const facade = {
    items: items.asReadonly(),
    unread: unread.asReadonly(),
    error: signal(false).asReadonly(),
    connect: vi.fn(),
    disconnect: vi.fn(),
    refresh: vi.fn(async () => undefined),
    markRead: vi.fn(async () => undefined),
    markAllRead: vi.fn(async () => undefined),
  };

  beforeEach(async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(NOW);
    me.set(inCompany('c1'));
    items.set([]);
    unread.set(0);
    permitted.set(true);
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [NotificationBell],
      providers: [
        provideRouter([{ path: 'members', component: Blank }]),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        {
          provide: AuthFacade,
          useValue: { me: me.asReadonly(), hasPermission: () => permitted() },
        },
        {
          provide: FormatFacade,
          useValue: {
            locale: signal('fr-TN').asReadonly(),
            day: (value: string) => formatDay(value, 'fr-TN'),
            moment: (value: string, timeZone?: string) => formatMoment(value, 'fr-TN', timeZone),
          },
        },
        { provide: NotificationsFacade, useValue: facade },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  afterEach(() => {
    TestBed.inject(MatDialog).closeAll();
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
    vi.useRealTimers();
  });

  function render() {
    const fixture = TestBed.createComponent(NotificationBell);
    fixture.detectChanges();
    TestBed.tick();
    return fixture;
  }

  function bell(fixture: ReturnType<typeof render>): HTMLButtonElement {
    return fixture.nativeElement.querySelector('[data-testid="notification-bell"]');
  }

  async function open(fixture: ReturnType<typeof render>): Promise<void> {
    bell(fixture).focus();
    bell(fixture).click();
    await settle(fixture);
  }

  async function settle(fixture: ReturnType<typeof render>): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const all = (testId: string) =>
    Array.from(document.querySelectorAll<HTMLElement>(`[data-testid="${testId}"]`));
  const text = (element: Element | null | undefined) =>
    element?.textContent?.replace(/\s+/g, ' ').trim();

  it('connects and loads the centre when it appears', () => {
    render();

    expect(facade.connect).toHaveBeenCalledOnce();
    expect(facade.refresh).toHaveBeenCalledOnce();
  });

  it('reconnects when the session moves to another company, and only then', () => {
    render();

    me.set(inCompany('c1'));
    TestBed.tick();
    expect(facade.connect).toHaveBeenCalledOnce();

    me.set(inCompany('c2'));
    TestBed.tick();
    expect(facade.connect).toHaveBeenCalledTimes(2);
  });

  it('closes the connection when it goes away', () => {
    const fixture = render();

    fixture.destroy();

    expect(facade.disconnect).toHaveBeenCalledOnce();
  });

  it('carries the unread count for the badge and in its accessible name', async () => {
    unread.set(3);
    const fixture = render();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(bell(fixture).getAttribute('data-unread')).toBe('3');
    expect(bell(fixture).getAttribute('aria-label')).toBe('Notifications, 3 non lues');
  });

  it('shows how many notifications are unread as a number, never a bare dot, and 9+ above nine', async () => {
    const badge = (fixture: ReturnType<typeof render>) =>
      fixture.nativeElement.querySelector('.mat-badge-content') as HTMLElement | null;
    unread.set(3);
    const fixture = render();
    await fixture.whenStable();
    fixture.detectChanges();
    // A small Material badge draws a dot and hides its content: the count must be on a medium one.
    expect(fixture.nativeElement.querySelector('.mat-badge-small')).toBeNull();
    expect(badge(fixture)?.textContent?.trim()).toBe('3');

    unread.set(12);
    fixture.detectChanges();
    expect(badge(fixture)?.textContent?.trim()).toBe('9+');
    expect(bell(fixture).getAttribute('aria-label')).toBe('Notifications, 12 non lues');
  });

  it('opens the centre as a named panel on the right, and Escape gives the focus back to the bell', async () => {
    const fixture = render();
    await open(fixture);

    const dialog = document.querySelector<HTMLElement>('[role="dialog"]');
    expect(bell(fixture).getAttribute('aria-haspopup')).toBe('dialog');
    expect(dialog).not.toBeNull();
    const title = document.getElementById(dialog?.getAttribute('aria-labelledby') ?? '');
    expect(text(title)).toBe('Notifications');
    expect(document.querySelector('.cdk-overlay-pane')?.classList).toContain('notification-panel');

    // The overlay reads keyCode, as a real browser sets it.
    document.activeElement?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, bubbles: true }),
    );
    await settle(fixture);

    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(bell(fixture));
  });

  it("groups the entries by the company's day, each with its words, its icon and how long ago", async () => {
    items.set([
      unreadEntry,
      // Half past midnight in Tunis: today there, still yesterday in UTC.
      {
        ...unreadEntry,
        id: 'n2',
        type: 'invitation.accepted',
        payload: { display_name: 'Salma' },
        createdAt: '2026-09-13T23:30:00+00:00',
        readAt: '2026-09-14T08:00:00+00:00',
      },
      {
        ...unreadEntry,
        id: 'n3',
        type: 'invoice.paid',
        createdAt: '2026-09-13T10:00:00+00:00',
        readAt: '2026-09-13T11:00:00+00:00',
      },
      {
        ...unreadEntry,
        id: 'n4',
        createdAt: '2026-09-12T10:00:00+00:00',
        readAt: '2026-09-12T11:00:00+00:00',
      },
    ]);
    unread.set(1);
    const fixture = render();
    await open(fixture);

    expect(all('notifications-day').map(text)).toEqual(['Aujourd’hui', 'Hier', '12/09/2026']);
    expect(all('notification-text').map(text)).toEqual([
      'Vous avez rejoint Acme',
      'Salma a rejoint l’entreprise',
      'Nouvelle notification',
      'Vous avez rejoint Acme',
    ]);
    expect(all('notification-icon').map(text)).toEqual([
      'add_business',
      'group_add',
      'notifications',
      'add_business',
    ]);
    expect(text(all('notification-time')[0])).toBe('il y a 5 minutes');
    expect(all('notification-time')[0].getAttribute('datetime')).toBe(unreadEntry.createdAt);
  });

  it('marks the unread entries, and reading one in place marks it read and keeps the panel open', async () => {
    items.set([unreadEntry, { ...unreadEntry, id: 'n2', readAt: '2026-09-14T08:56:00+00:00' }]);
    unread.set(1);
    const fixture = render();
    await open(fixture);

    const entries = all('notification-item');
    expect(text(entries[0].querySelector('[data-testid="notification-unread"]'))).toBe('Non lue');
    expect(entries[1].querySelector('[data-testid="notification-unread"]')).toBeNull();

    entries[1].click();
    expect(facade.markRead).not.toHaveBeenCalled();
    entries[0].click();
    await settle(fixture);

    expect(facade.markRead).toHaveBeenCalledWith('n1');
    expect(document.querySelector('[role="dialog"]')).not.toBeNull();
  });

  it('leads an entry to its record, closing the panel, when the person may open it', async () => {
    const joined: InboxEntry = {
      ...unreadEntry,
      id: 'n2',
      type: 'invitation.accepted',
      payload: { display_name: 'Salma' },
    };
    items.set([joined]);
    unread.set(1);
    const fixture = render();
    await open(fixture);

    const link = all('notification-item')[0];
    expect(link.tagName).toBe('A');
    expect(link.getAttribute('href')).toBe('/members');
    link.click();
    await settle(fixture);

    expect(facade.markRead).toHaveBeenCalledWith('n2');
    expect(TestBed.inject(Router).url).toBe('/members');
    expect(document.querySelector('[role="dialog"]')).toBeNull();
  });

  // « Me prévenir » (row 150): the payload names the module by its translation key, which the panel reads as its name.
  it('names the module that arrived in the words of the screen', async () => {
    items.set([
      {
        ...unreadEntry,
        id: 'n3',
        type: 'module.arrived',
        payload: { module: 'quotes', label_key: 'modules.quotes', company: 'Acme' },
      },
    ]);
    const fixture = render();
    await open(fixture);

    expect(text(all('notification-item')[0])).toContain(
      '« Devis et commandes » est arrivé pour Acme',
    );
  });

  it('does not link a record the person may not open', async () => {
    permitted.set(false);
    items.set([{ ...unreadEntry, type: 'invitation.accepted', payload: { display_name: 'S' } }]);
    const fixture = render();
    await open(fixture);

    expect(all('notification-item')[0].tagName).toBe('BUTTON');
    expect(all('notification-item')[0].getAttribute('href')).toBeNull();
  });

  it('shows only the unread entries under the unread tab', async () => {
    items.set([unreadEntry, { ...unreadEntry, id: 'n2', readAt: '2026-09-14T08:56:00+00:00' }]);
    unread.set(1);
    const fixture = render();
    await open(fixture);
    const tab = (id: string) => document.querySelector<HTMLElement>(`[data-testid="${id}"]`);

    expect(tab('notifications-filter-all')?.getAttribute('aria-selected')).toBe('true');
    expect(all('notification-item')).toHaveLength(2);

    tab('notifications-filter-unread')?.click();
    await settle(fixture);

    expect(tab('notifications-filter-unread')?.getAttribute('aria-selected')).toBe('true');
    expect(tab('notifications-filter-all')?.getAttribute('aria-selected')).toBe('false');
    expect(all('notification-item')).toHaveLength(1);

    items.set([{ ...unreadEntry, readAt: '2026-09-14T08:58:00+00:00' }]);
    await settle(fixture);
    expect(text(tab('notifications-empty'))).toBe('Rien de non lu');
  });

  it('offers to mark everything read only when something is unread', async () => {
    unread.set(1);
    items.set([unreadEntry]);
    const fixture = render();
    await open(fixture);

    document.querySelector<HTMLButtonElement>('[data-testid="notifications-read-all"]')?.click();

    expect(facade.markAllRead).toHaveBeenCalledOnce();
  });

  it('says so when there is nothing', async () => {
    const fixture = render();
    await open(fixture);

    expect(document.querySelector('[data-testid="notifications-empty"]')?.textContent).toContain(
      'Aucune notification',
    );
    expect(document.querySelector('[data-testid="notifications-read-all"]')).toBeNull();
  });
});
