// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { SignedInState } from '../auth/auth-types';
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
        mark_all: 'Tout marquer comme lu',
        types: {
          membership_added: 'Vous avez rejoint {{company}}',
          invitation_accepted: '{{display_name}} a rejoint l’entreprise',
          unknown: 'Nouvelle notification',
        },
      },
    });
  }
}

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
    },
    permissions: ['*'],
    modules: ['customers'],
  }) satisfies SignedInState;

const unreadEntry: InboxEntry = {
  id: 'n1',
  type: 'membership.added',
  payload: { company: 'Acme' },
  companyId: null,
  createdAt: '2026-09-13T10:00:00+00:00',
  readAt: null,
};

describe('NotificationBell', () => {
  const me = signal<SignedInState | null>(inCompany('c1'));
  const items = signal<readonly InboxEntry[]>([]);
  const unread = signal(0);
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
    me.set(inCompany('c1'));
    items.set([]);
    unread.set(0);
    vi.clearAllMocks();
    await TestBed.configureTestingModule({
      imports: [NotificationBell],
      providers: [
        { provide: AuthFacade, useValue: { me: me.asReadonly() } },
        { provide: NotificationsFacade, useValue: facade },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
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

  it('lists the entries in words, and reading an unread one marks it', async () => {
    items.set([
      unreadEntry,
      { ...unreadEntry, id: 'n2', type: 'invoice.paid', readAt: '2026-09-13T10:01:00+00:00' },
    ]);
    unread.set(1);
    const fixture = render();
    bell(fixture).click();
    await fixture.whenStable();
    fixture.detectChanges();

    const entries = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-testid="notification-item"]'),
    );
    const texts = entries.map((entry) =>
      entry.querySelector('[data-testid="notification-text"]')?.textContent?.trim(),
    );
    expect(texts).toEqual(['Vous avez rejoint Acme', 'Nouvelle notification']);

    entries[1].click();
    expect(facade.markRead).not.toHaveBeenCalled();
    bell(fixture).click();
    await fixture.whenStable();
    fixture.detectChanges();
    document.querySelector<HTMLButtonElement>('[data-testid="notification-item"]')?.click();
    expect(facade.markRead).toHaveBeenCalledWith('n1');
  });

  it('offers to mark everything read only when something is unread', async () => {
    unread.set(1);
    items.set([unreadEntry]);
    const fixture = render();
    bell(fixture).click();
    await fixture.whenStable();
    fixture.detectChanges();

    document.querySelector<HTMLButtonElement>('[data-testid="notifications-read-all"]')?.click();

    expect(facade.markAllRead).toHaveBeenCalledOnce();
  });

  it('says so when there is nothing', async () => {
    const fixture = render();
    bell(fixture).click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(document.querySelector('[data-testid="notifications-empty"]')?.textContent).toContain(
      'Aucune notification',
    );
    expect(document.querySelector('[data-testid="notifications-read-all"]')).toBeNull();
  });
});
