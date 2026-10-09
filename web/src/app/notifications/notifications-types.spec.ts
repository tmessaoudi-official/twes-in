// SPDX-License-Identifier: AGPL-3.0-or-later

import en from '../../../public/i18n/en.json';
import fr from '../../../public/i18n/fr.json';
import {
  KNOWN_NOTIFICATION_TYPES,
  notificationKey,
  notificationKindKey,
  notificationRecord,
} from './notifications-types';

describe('notificationKey', () => {
  it('translates a known type under its own key and anything newer under the generic one', () => {
    expect(notificationKey('membership.added')).toBe('notifications.types.membership_added');
    expect(notificationKey('invitation.received')).toBe('notifications.types.invitation_received');
    expect(notificationKey('invoice.paid')).toBe('notifications.types.unknown');
  });
});

describe('notificationKindKey', () => {
  it('names each known kind in a few words, apart from the sentence that tells one, and anything newer generically', () => {
    for (const type of KNOWN_NOTIFICATION_TYPES) {
      expect(notificationKindKey(type)).toBe(`notifications.kinds.${type.replaceAll('.', '_')}`);
    }
    expect(notificationKindKey('invoice.paid')).toBe('notifications.kinds.unknown');
  });

  it('has a name for every known kind, and the generic one, in both languages', () => {
    for (const words of [fr, en]) {
      const kinds: Record<string, string> = words.notifications.kinds;
      for (const type of [...KNOWN_NOTIFICATION_TYPES, 'unknown']) {
        expect(kinds[type.replaceAll('.', '_')], type).toEqual(expect.any(String));
      }
    }
  });
});

describe('stock notifications', () => {
  it('have words and lead to the stock movements for whoever may read them', () => {
    for (const type of [
      'stock.delivery_note_lines_left_out',
      'stock.delivery_note_moved_no_stock',
      'stock.invoice_lines_left_out',
      'stock.invoice_moved_no_stock',
      'stock.credit_lines_not_returned',
      'stock.credit_moved_no_stock',
      'stock.count_difference',
      'stock.imported',
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

describe('stock alerts', () => {
  it('a product fallen to its reorder point has words and leads to the stock', () => {
    expect(notificationKey('stock.low')).toBe('notifications.types.stock_low');
    expect(notificationRecord('stock.low')).toEqual({
      icon: 'inventory_2',
      route: '/stock',
      permission: 'stock.read',
    });
  });
});

describe('credit limit notifications', () => {
  it('have words and lead to the invoices for whoever may read them', () => {
    expect(notificationKey('invoice.credit_limit_passed')).toBe(
      'notifications.types.invoice_credit_limit_passed',
    );
    expect(notificationRecord('invoice.credit_limit_passed')).toEqual({
      icon: 'credit_score',
      route: '/invoices',
      permission: 'invoice.read',
    });
  });
});

describe('reminder notifications', () => {
  it('have words and lead to the invoices, where the late one is chased', () => {
    expect(notificationKey('invoice.reminder_due')).toBe(
      'notifications.types.invoice_reminder_due',
    );
    expect(notificationRecord('invoice.reminder_due')).toEqual({
      icon: 'notifications_active',
      route: '/invoices',
      permission: 'invoice.read',
    });
  });

  it('say when the stage drafted a late fee, which waits among the invoices', () => {
    expect(notificationKey('invoice.late_fee_drafted')).toBe(
      'notifications.types.invoice_late_fee_drafted',
    );
    expect(notificationRecord('invoice.late_fee_drafted')?.route).toBe('/invoices');
  });

  it('say when a recurring invoice drafted its copy, or paused itself, and lead to where each is', () => {
    expect(notificationKey('invoice.recurring_drafted')).toBe(
      'notifications.types.invoice_recurring_drafted',
    );
    expect(notificationRecord('invoice.recurring_drafted')).toEqual({
      icon: 'event_repeat',
      route: '/invoices',
      permission: 'invoice.read',
    });
    expect(notificationRecord('invoice.recurring_stopped')?.route).toBe('/invoices/recurring');
  });
});

describe('module notifications', () => {
  it('have words and lead to the modules page for whoever may switch one on', () => {
    expect(notificationKey('module.arrived')).toBe('notifications.types.module_arrived');
    expect(notificationRecord('module.arrived')).toEqual({
      icon: 'extension',
      route: '/company/modules',
      permission: 'company.settings',
    });
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
