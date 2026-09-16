// SPDX-License-Identifier: AGPL-3.0-or-later

import { notificationKey, notificationRecord } from './notifications-types';

describe('notificationKey', () => {
  it('translates a known type under its own key and anything newer under the generic one', () => {
    expect(notificationKey('membership.added')).toBe('notifications.types.membership_added');
    expect(notificationKey('invitation.received')).toBe('notifications.types.invitation_received');
    expect(notificationKey('invoice.paid')).toBe('notifications.types.unknown');
  });
});

describe('stock notifications', () => {
  it('have words and lead to the stock movements for whoever may read them', () => {
    for (const type of [
      'stock.delivery_note_lines_left_out',
      'stock.delivery_note_moved_no_stock',
    ]) {
      expect(notificationKey(type)).toBe(`notifications.types.${type.replace('.', '_')}`);
      expect(notificationRecord(type)).toEqual({
        icon: 'inventory_2',
        route: '/stock/movements',
        permission: 'stock.read',
      });
    }
  });
});

describe('notificationRecord', () => {
  it('gives each type its icon and, where the record is in this company, the screen it leads to', () => {
    expect(notificationRecord('invitation.accepted')).toEqual({
      icon: 'group_add',
      route: '/members',
      permission: 'user.read',
    });
    // Being added to a company is news about another company: nothing here to open.
    expect(notificationRecord('membership.added')).toEqual({
      icon: 'add_business',
      route: null,
      permission: null,
    });
    // An invitation is to another company, and its link is in the mail: nothing here to open either.
    expect(notificationRecord('invitation.received')).toEqual({
      icon: 'mail',
      route: null,
      permission: null,
    });
    expect(notificationRecord('invoice.paid')).toEqual({
      icon: 'notifications',
      route: null,
      permission: null,
    });
  });
});
