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

describe('KNOWN_NOTIFICATION_TYPES', () => {
  // A kind the API tells and the words say, but the bell does not know, reads « Nouvelle notification » instead.
  it('holds every kind the words say, in both languages', () => {
    const known = KNOWN_NOTIFICATION_TYPES.map((type) => type.replaceAll('.', '_'));
    for (const words of [fr, en]) {
      for (const key of Object.keys(words.notifications.types).filter((key) => key !== 'unknown')) {
        expect(known, key).toContain(key);
      }
    }
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
      expect(notificationRecord(type)).toMatchObject({
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
      quantities: ['on_hand', 'point'],
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
      amounts: ['owed', 'limit'],
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
      amounts: ['amount_due'],
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

  it('say when a recurring expense drafted its copy, or paused itself, and lead to where each is', () => {
    expect(notificationKey('expense.recurring_drafted')).toBe(
      'notifications.types.expense_recurring_drafted',
    );
    expect(notificationKindKey('expense.recurring_stopped')).toBe(
      'notifications.kinds.expense_recurring_stopped',
    );
    expect(notificationRecord('expense.recurring_drafted')).toEqual({
      icon: 'event_repeat',
      route: '/expenses',
      permission: 'expense.read',
    });
    expect(notificationRecord('expense.recurring_stopped')?.route).toBe('/expenses/recurring');
  });
});

// What a notification says in money is written as the screen writes amounts: each type names its amounts.
describe('amounts in a notification', () => {
  it('are named by each type that says one', () => {
    expect(notificationRecord('invoice.late_fee_drafted').amounts).toEqual([
      'amount_due',
      'late_fee',
    ]);
    expect(notificationRecord('subscription.payment_declared').amounts).toEqual(['amount']);
    expect(notificationRecord('subscription.payment_decided').amounts).toEqual(['amount']);
    expect(notificationRecord('stock.low').amounts).toBeUndefined();
  });
});

// A quantity and a moment are written as the screen writes them too; a count (`counted`, `differences`) is not one.
describe('quantities and moments in a notification', () => {
  it('are named by each type that says one', () => {
    expect(notificationRecord('stock.count_difference').quantities).toEqual(['difference']);
    expect(notificationRecord('stock.imported').quantities).toBeUndefined();
    expect(notificationRecord('support_access.granted').moments).toEqual(['until']);
  });
});

describe('support access and data check notifications', () => {
  it('have words, and lead an owner to « Accès du support »', () => {
    for (const type of ['support_access.granted', 'support_access.ended']) {
      expect(notificationKey(type)).toBe(`notifications.types.${type.replace('.', '_')}`);
      expect(notificationRecord(type)).toMatchObject({
        icon: 'support_agent',
        route: '/company/support-access',
        permission: 'company.settings',
      });
    }
    // An operator's, about a company not theirs: told by the mail, with nothing here to open.
    expect(notificationKey('data.check_failed')).toBe('notifications.types.data_check_failed');
    expect(notificationRecord('data.check_failed').route).toBeNull();
  });
});

describe('installation notifications', () => {
  it('have words and lead nowhere: the owner asks whoever runs the installation, the operator reads the mail', () => {
    const icons = {
      'installation.update_available': 'fiber_new',
      'installation.rolled_back': 'undo',
      'installation.update_failed': 'error',
      'installation.unhealthy': 'sync_problem',
      'installation.backup_failed': 'cloud_off',
      'installation.backup_stopped': 'cloud_off',
    };
    for (const [type, icon] of Object.entries(icons)) {
      expect(notificationKey(type)).toBe(`notifications.types.${type.replace('.', '_')}`);
      expect(notificationKindKey(type)).toBe(`notifications.kinds.${type.replace('.', '_')}`);
      expect(notificationRecord(type)).toEqual({ icon, route: null, permission: null });
    }
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
