// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import { TestBed } from '@angular/core/testing';
import { NotificationsApi } from './notifications-api';
import { NotificationsFacade } from './notifications-facade';
import type { InboxEntry, InboxPage } from './notifications-types';
import { notificationKey } from './notifications-types';
import { LiveChanges } from '../shared/realtime/live-changes';
import { PhonePairing } from '../shared/scan/phone-pairing';
import { REALTIME_CONNECTOR, type RealtimeConnector } from '../shared/realtime/realtime-connector';

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
    onPublication: (data: unknown) => void;
    disconnect: ReturnType<typeof vi.fn>;
  }[] = [];
  const connector: RealtimeConnector = (url, getToken, onPublication) => {
    const connection = { url, getToken, onPublication, disconnect: vi.fn() };
    opened.push(connection);
    return connection;
  };
  const live = { receive: vi.fn() };
  const pairing = { receive: vi.fn((data: { type?: string }) => data.type === 'pairing') };
  let facade: NotificationsFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    live.receive.mockReset();
    pairing.receive.mockClear();
    opened.length = 0;
    TestBed.configureTestingModule({
      providers: [
        { provide: NotificationsApi, useValue: api },
        { provide: REALTIME_CONNECTOR, useValue: connector },
        { provide: LiveChanges, useValue: live },
        { provide: PhonePairing, useValue: pairing },
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

  it('refreshes when a notification is published', async () => {
    api.list.mockResolvedValue(page([added], 1));
    facade.connect();

    opened[0].onPublication({ type: 'membership.added', payload: {} });
    await vi.waitFor(() => expect(facade.unread()).toBe(1));

    expect(api.list).toHaveBeenCalledOnce();
    expect(live.receive).not.toHaveBeenCalled();
  });

  it('hands a change to data to the screens instead of reading the centre again', () => {
    facade.connect();
    const change = { type: 'changed', kind: 'customer', id: 'c1', action: 'customer.revised' };

    opened[0].onPublication(change);

    expect(live.receive).toHaveBeenCalledWith(change);
    expect(api.list).not.toHaveBeenCalled();
  });

  it("hands a paired phone's publication to the pairing, and neither reads the centre nor tells the screens", () => {
    facade.connect();
    const scan = { type: 'pairing', event: 'scan', pairing: 'p-1', tab: 't', scan: 's', code: '1' };

    opened[0].onPublication(scan);

    expect(pairing.receive).toHaveBeenCalledWith(scan);
    expect(api.list).not.toHaveBeenCalled();
    expect(live.receive).not.toHaveBeenCalled();
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
