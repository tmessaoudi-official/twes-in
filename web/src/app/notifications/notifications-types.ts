// SPDX-License-Identifier: AGPL-3.0-or-later

import type { IconName } from '../shared/icons/icons';

/**
 * The notification centre seen from its components. Built by the API adapter (notifications-api.ts) from the
 * generated OpenAPI types, which nothing else imports.
 */
export type NotificationPayload = Readonly<Record<string, string | number | boolean | null>>;

export interface InboxEntry {
  id: string;
  /** a dotted code such as "membership.added"; translated under notifications.types */
  type: string;
  payload: NotificationPayload;
  companyId: string | null;
  createdAt: string;
  readAt: string | null;
}

export interface InboxPage {
  /** newest first */
  items: readonly InboxEntry[];
  /** every unread notification, not only those in items */
  unread: number;
}

/** The types this client has words for; anything newer is shown with a generic line until it does. */
export const KNOWN_NOTIFICATION_TYPES = [
  'membership.added',
  'invitation.received',
  'invitation.accepted',
  'stock.delivery_note_lines_left_out',
  'stock.delivery_note_moved_no_stock',
  'stock.invoice_lines_left_out',
  'stock.invoice_moved_no_stock',
  'stock.credit_lines_not_returned',
  'stock.credit_moved_no_stock',
  'stock.count_difference',
  'stock.low',
  'invoice.credit_limit_passed',
  'invoice.reminder_due',
  'invoice.late_fee_drafted',
  'invoice.recurring_drafted',
  'invoice.recurring_stopped',
  'subscription.payment_declared',
  'subscription.payment_decided',
  'module.arrived',
] as const;

/** "membership.added" → "notifications.types.membership_added"; an unknown type → the generic key. */
export function notificationKey(type: string): string {
  return (KNOWN_NOTIFICATION_TYPES as readonly string[]).includes(type)
    ? `notifications.types.${type.replaceAll('.', '_')}`
    : 'notifications.types.unknown';
}

/** The kind's name where a person chooses how it is told: "stock.low" → "notifications.kinds.stock_low". */
export function notificationKindKey(type: string): string {
  return (KNOWN_NOTIFICATION_TYPES as readonly string[]).includes(type)
    ? `notifications.kinds.${type.replaceAll('.', '_')}`
    : 'notifications.kinds.unknown';
}

/** Where a notification leads: its icon, and the screen of its record in this company with the permission it takes. */
export interface NotificationRecord {
  readonly icon: IconName;
  /** null when there is nothing in this company to open */
  readonly route: string | null;
  readonly permission: string | null;
}

// The money and document events (invoice paid, overdue, payment recorded, delivery note delivered) add their rows here.
const RECORDS = new Map<string, NotificationRecord>([
  // Being added to a company is news about another company: nothing here to open.
  ['membership.added', { icon: 'add_business', route: null, permission: null }],
  // An invitation is to another company, and its link is in the mail alone: nothing here to open either.
  ['invitation.received', { icon: 'mail', route: null, permission: null }],
  ['invitation.accepted', { icon: 'group_add', route: '/members', permission: 'user.read' }],
  // A delivery note whose stock did not all move: the movements show what did.
  [
    'stock.delivery_note_lines_left_out',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.delivery_note_moved_no_stock',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.invoice_lines_left_out',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.invoice_moved_no_stock',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.credit_lines_not_returned',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.credit_moved_no_stock',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  // A count that found a difference leads to the movements, where the adjustment it wrote is; a product fallen to its
  // reorder point leads to the stock it is reordered from.
  [
    'stock.count_difference',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  ['stock.low', { icon: 'inventory_2', route: '/stock', permission: 'stock.read' }],
  // An invoice that took a customer past their credit limit leads to the invoices, where the account is read.
  [
    'invoice.credit_limit_passed',
    { icon: 'credit_score', route: '/invoices', permission: 'invoice.read' },
  ],
  // A late invoice reached a stage of the reminder calendar: the overdue invoices are where it is chased.
  [
    'invoice.reminder_due',
    { icon: 'notifications_active', route: '/invoices', permission: 'invoice.read' },
  ],
  // The same, with the late fee the stage charged drafted among the invoices, for a person to issue or cancel.
  [
    'invoice.late_fee_drafted',
    { icon: 'notifications_active', route: '/invoices', permission: 'invoice.read' },
  ],
  // A recurring invoice drafted its copy, which waits among the invoices to be issued; or it paused itself because
  // the invoice it copies can no longer be copied, which the recurring invoices show.
  [
    'invoice.recurring_drafted',
    { icon: 'event_repeat', route: '/invoices', permission: 'invoice.read' },
  ],
  [
    'invoice.recurring_stopped',
    { icon: 'event_repeat', route: '/invoices/recurring', permission: 'invoice.read' },
  ],
  // An operator's: the company that declared it is not one of theirs, so it leads to the platform queue.
  [
    'subscription.payment_declared',
    { icon: 'payments', route: '/platform', permission: 'platform.licensing.manage' },
  ],
  // An owner's: what the operator answered about the payment their own company declared.
  [
    'subscription.payment_decided',
    { icon: 'payments', route: '/company/subscription', permission: 'subscription.read' },
  ],
  // A module the company asked « Me prévenir » for has arrived: switched on from the modules page.
  [
    'module.arrived',
    { icon: 'extension', route: '/company/modules', permission: 'company.settings' },
  ],
]);

export function notificationRecord(type: string): NotificationRecord {
  return RECORDS.get(type) ?? { icon: 'notifications', route: null, permission: null };
}
