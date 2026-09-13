// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { NotificationsApi } from './notifications-api';
import { NotificationsFacade } from './notifications-facade';
import type { InboxEntry, InboxPage } from './notifications-types';
import { notificationKey } from './notifications-types';
import { REALTIME_CONNECTOR, type RealtimeConnector } from './realtime-connector';

const added: InboxEntry = {
  id: 'n1',
  type: 'membership.added',
  payload: { company: 'Acme' },
  companyId: null,
  createdAt: '2026-09-13T10:00:00+00:00',
  readAt: null,
};

const page = (items: InboxEntry[], unread: number): InboxPage => ({ items, unread });

describe('NotificationsFacade', () => {
  const api = {
    list: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
    realtimeToken: vi.fn(),
  };
  const opened: {
    url: string;
    getToken: () => Promise<string>;
    onPublication: () => void;
    disconnect: ReturnType<typeof vi.fn>;
  }[] = [];
  const connector: RealtimeConnector = (url, getToken, onPublication) => {
    const connection = { url, getToken, onPublication, disconnect: vi.fn() };
    opened.push(connection);
    return connection;
  };
  let facade: NotificationsFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    opened.length = 0;
    TestBed.configureTestingModule({
      providers: [
        { provide: NotificationsApi, useValue: api },
        { provide: REALTIME_CONNECTOR, useValue: connector },
      ],
    });
    facade = TestBed.inject(NotificationsFacade);
  });

  it('shows what the API returned', async () => {
    api.list.mockResolvedValue(page([added], 1));

    await facade.refresh();

    expect(facade.items()).toEqual([added]);
    expect(facade.unread()).toBe(1);
    expect(facade.error()).toBe(false);
  });

  it('keeps the last list and flags the failure when a refresh fails', async () => {
    api.list.mockResolvedValueOnce(page([added], 1)).mockRejectedValueOnce(new Error('offline'));

    await facade.refresh();
    await facade.refresh();

    expect(facade.items()).toEqual([added]);
    expect(facade.error()).toBe(true);
  });

  it('connects to the websocket on this origin and takes its token from the API', async () => {
    api.realtimeToken.mockResolvedValue('a-token');
    const location = TestBed.inject(DOCUMENT).location;
    const scheme = location.protocol === 'https:' ? 'wss' : 'ws';

    facade.connect();

    expect(opened).toHaveLength(1);
    expect(opened[0].url).toBe(`${scheme}://${location.host}/connection/websocket`);
    await expect(opened[0].getToken()).resolves.toBe('a-token');
  });

  it('refreshes when something is published', async () => {
    api.list.mockResolvedValue(page([added], 1));
    facade.connect();

    opened[0].onPublication();
    await vi.waitFor(() => expect(facade.unread()).toBe(1));

    expect(api.list).toHaveBeenCalledOnce();
  });

  it('closes the previous connection before opening another, so a company switch hears only the new channels', () => {
    facade.connect();
    facade.connect();

    expect(opened).toHaveLength(2);
    expect(opened[0].disconnect).toHaveBeenCalledOnce();
    expect(opened[1].disconnect).not.toHaveBeenCalled();
  });

  it('closes the connection on disconnect', () => {
    facade.connect();

    facade.disconnect();

    expect(opened[0].disconnect).toHaveBeenCalledOnce();
  });

  it('marks one read and shows the new state', async () => {
    api.markRead.mockResolvedValue(undefined);
    api.list.mockResolvedValue(page([{ ...added, readAt: '2026-09-13T10:05:00+00:00' }], 0));

    await facade.markRead('n1');

    expect(api.markRead).toHaveBeenCalledWith('n1');
    expect(facade.unread()).toBe(0);
  });

  it('marks all read and shows the new state', async () => {
    api.markAllRead.mockResolvedValue(undefined);
    api.list.mockResolvedValue(page([], 0));

    await facade.markAllRead();

    expect(api.markAllRead).toHaveBeenCalledOnce();
    expect(facade.unread()).toBe(0);
  });
});

describe('notificationKey', () => {
  it('names a known type under notifications.types', () => {
    expect(notificationKey('membership.added')).toBe('notifications.types.membership_added');
    expect(notificationKey('invitation.accepted')).toBe('notifications.types.invitation_accepted');
  });

  it('falls back to the generic line for a type this client does not know yet', () => {
    expect(notificationKey('invoice.paid')).toBe('notifications.types.unknown');
  });
});
