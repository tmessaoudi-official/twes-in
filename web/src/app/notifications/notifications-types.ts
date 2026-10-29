// SPDX-License-Identifier: AGPL-3.0-or-later

import type { IconName } from '../shared/icons/icons';

/**
 * The notification centre seen from its components. Built by the API adapter (notifications-api.ts) from the
 * generated OpenAPI types, which nothing else imports.
 */
export type NotificationPayload = Readonly<Record<string, string | number | boolean | null>>;

export interface InboxEntry {
  id: string;
  /** a dotted code such as "invitation.accepted"; translated under notifications.types */
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
  'invitation.received',
  'invitation.accepted',
  'membership.role_changed',
  'membership.removed',
  'stock.delivery_note_lines_left_out',
  'stock.delivery_note_moved_no_stock',
  'stock.invoice_lines_left_out',
  'stock.invoice_moved_no_stock',
  'stock.credit_lines_not_returned',
  'stock.credit_moved_no_stock',
  'stock.count_difference',
  'stock.imported',
  'stock.low',
  'invoice.credit_limit_passed',
  'invoice.reminder_due',
  'invoice.late_fee_drafted',
  'invoice.recurring_drafted',
  'invoice.recurring_stopped',
  'expense.recurring_drafted',
  'expense.recurring_stopped',
  'subscription.payment_declared',
  'subscription.payment_decided',
  'module.arrived',
  'support_access.granted',
  'support_access.ended',
  'data.check_failed',
  'signup.awaiting_approval',
  'installation.update_available',
  'installation.rolled_back',
  'installation.update_failed',
  'installation.update_refused',
  'installation.unhealthy',
  'installation.backup_failed',
  'installation.backup_stopped',
  'installation.address_moved',
  'installation.clock_off',
] as const;

/** "invitation.accepted" → "notifications.types.invitation_accepted"; an unknown type → the generic key. */
export function notificationKey(type: string): string {
  return (KNOWN_NOTIFICATION_TYPES as readonly string[]).includes(type)
    ? `notifications.types.${type.replaceAll('.', '_')}`
    : 'notifications.types.unknown';
}

/**
 * The sentence an entry says: its type's own, or the one its record's variant picks from the payload
 * ("subscription.payment_decided" with `decision: "refused"` → "…subscription_payment_decided_refused").
 */
export function sentenceKey(entry: InboxEntry, record: NotificationRecord): string {
  const base = notificationKey(entry.type);
  const value = record.variant === undefined ? undefined : entry.payload[record.variant.field];
  return typeof value === 'string' && record.variant?.values.includes(value) === true
    ? `${base}_${value}`
    : base;
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
  /** the payload's amounts, which the words write as the screen writes amounts, at the payload's `scale` */
  readonly amounts?: readonly string[];
  /** the payload's quantities, sent at their column's decimals, which the words write without the zeros that say nothing */
  readonly quantities?: readonly string[];
  /** the payload's field naming the unit the quantities are counted in, written after each: « -3 kg » is not « -3 » pieces */
  readonly unit?: string;
  /**
   * A payload field whose value picks the sentence among the type's own (`<key>_<value>`), such as which answer a
   * payment had; a notice without it, or with a value not listed, says the type's general sentence
   */
  readonly variant?: { readonly field: string; readonly values: readonly string[] };
  /** the payload's moments, sent as the company's `YYYY-MM-DD HH:mm`, which the words write in the screen's order of a day */
  readonly moments?: readonly string[];
}

// The money and document events (invoice paid, overdue, payment recorded, delivery note delivered) add their rows here.
const RECORDS = new Map<string, NotificationRecord>([
  // An invitation is to another company, and its link is in the mail alone: nothing here to open either.
  ['invitation.received', { icon: 'mail', route: null, permission: null }],
  ['invitation.accepted', { icon: 'group_add', route: '/members', permission: 'user.read' }],
  // The person's own role changed, or they were taken out of a company: the sentence says it all, and a removed person
  // has nothing left there to open. A built-in role is named through its key, the company's own by its name.
  ['membership.role_changed', { icon: 'manage_accounts', route: null, permission: null }],
  ['membership.removed', { icon: 'person_remove', route: null, permission: null }],
  // A delivery note whose stock did not all move: the movements show what did. One whose stock failed to move waits on
  // « À surveiller », which leads to the document, where it is moved again.
  [
    'stock.delivery_note_lines_left_out',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.delivery_note_moved_no_stock',
    { icon: 'inventory_2', route: '/watch/stock.move_failed', permission: 'stock.read' },
  ],
  [
    'stock.invoice_lines_left_out',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.invoice_moved_no_stock',
    { icon: 'inventory_2', route: '/watch/stock.move_failed', permission: 'stock.read' },
  ],
  [
    'stock.credit_lines_not_returned',
    { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' },
  ],
  [
    'stock.credit_moved_no_stock',
    { icon: 'inventory_2', route: '/watch/stock.move_failed', permission: 'stock.read' },
  ],
  // A count that found a difference leads to the movements, where the adjustment it wrote is; a product fallen to its
  // reorder point leads to the stock it is reordered from.
  [
    'stock.count_difference',
    {
      icon: 'inventory_2',
      route: '/stock/movements',
      permission: 'stock.read',
      quantities: ['difference'],
      unit: 'unit',
    },
  ],
  // A file whose counts found differences leads to the movements too, where each count it wrote reads as the import's.
  ['stock.imported', { icon: 'inventory_2', route: '/stock/movements', permission: 'stock.read' }],
  [
    'stock.low',
    {
      icon: 'inventory_2',
      route: '/stock',
      permission: 'stock.read',
      quantities: ['on_hand', 'point'],
      unit: 'unit',
    },
  ],
  // An invoice that took a customer past their credit limit leads to the invoices, where the account is read.
  [
    'invoice.credit_limit_passed',
    {
      icon: 'credit_score',
      route: '/invoices',
      permission: 'invoice.read',
      amounts: ['owed', 'limit'],
    },
  ],
  // A late invoice reached a stage of the reminder calendar: the overdue invoices are where it is chased.
  [
    'invoice.reminder_due',
    {
      icon: 'notifications_active',
      route: '/invoices',
      permission: 'invoice.read',
      amounts: ['amount_due'],
    },
  ],
  // The same, with the late fee the stage charged drafted among the invoices, for a person to issue or cancel.
  [
    'invoice.late_fee_drafted',
    {
      icon: 'notifications_active',
      route: '/invoices',
      permission: 'invoice.read',
      amounts: ['amount_due', 'late_fee'],
    },
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
  // A recurring expense drafted its copy, which waits among the expenses to be checked and recorded; or it paused
  // itself because the expense it copies can no longer be copied, which the recurring expenses show.
  [
    'expense.recurring_drafted',
    { icon: 'event_repeat', route: '/expenses', permission: 'expense.read' },
  ],
  [
    'expense.recurring_stopped',
    { icon: 'event_repeat', route: '/expenses/recurring', permission: 'expense.read' },
  ],
  // An operator's: the company that declared it is not one of theirs, so it leads to the platform queue.
  [
    'subscription.payment_declared',
    {
      icon: 'payments',
      route: '/platform',
      permission: 'platform.licensing.manage',
      amounts: ['amount'],
    },
  ],
  // An owner's: what the operator answered about the payment their own company declared.
  [
    'subscription.payment_decided',
    {
      icon: 'payments',
      route: '/company/subscription',
      permission: 'subscription.read',
      amounts: ['amount'],
      variant: { field: 'decision', values: ['confirmed', 'refused'] },
    },
  ],
  // A module the company asked « Me prévenir » for has arrived: switched on from the modules page.
  [
    'module.arrived',
    { icon: 'extension', route: '/company/modules', permission: 'company.settings' },
  ],
  // Another owner opened or closed « Accès du support »: the page where it is seen, and closed.
  [
    'support_access.granted',
    {
      icon: 'support_agent',
      route: '/company/support-access',
      permission: 'company.settings',
      moments: ['until'],
    },
  ],
  [
    'support_access.ended',
    { icon: 'support_agent', route: '/company/support-access', permission: 'company.settings' },
  ],
  // An operator's, about a company not theirs: the mail says it all, and there is nothing here to open.
  ['data.check_failed', { icon: 'notifications', route: null, permission: null }],
  // An operator's: the company waits on the platform's page, where it is approved.
  [
    'signup.awaiting_approval',
    { icon: 'business', route: '/platform', permission: 'platform.company.approve' },
  ],
  // The installation's own (scripts/onprem/twes): an owner asks whoever runs it to apply a release, and an operator
  // reads the mail the script sent with the commands; there is nothing in a company to open for either.
  ['installation.update_available', { icon: 'fiber_new', route: null, permission: null }],
  ['installation.rolled_back', { icon: 'undo', route: null, permission: null }],
  ['installation.update_failed', { icon: 'error', route: null, permission: null }],
  ['installation.update_refused', { icon: 'block', route: null, permission: null }],
  ['installation.unhealthy', { icon: 'sync_problem', route: null, permission: null }],
  ['installation.backup_failed', { icon: 'cloud_off', route: null, permission: null }],
  ['installation.backup_stopped', { icon: 'cloud_off', route: null, permission: null }],
  ['installation.address_moved', { icon: 'smartphone', route: null, permission: null }],
  ['installation.clock_off', { icon: 'schedule', route: null, permission: null }],
]);

export function notificationRecord(type: string): NotificationRecord {
  return RECORDS.get(type) ?? { icon: 'notifications', route: null, permission: null };
}
