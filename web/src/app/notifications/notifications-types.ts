// SPDX-License-Identifier: AGPL-3.0-or-later

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
] as const;

/** "membership.added" → "notifications.types.membership_added"; an unknown type → the generic key. */
export function notificationKey(type: string): string {
  return (KNOWN_NOTIFICATION_TYPES as readonly string[]).includes(type)
    ? `notifications.types.${type.replaceAll('.', '_')}`
    : 'notifications.types.unknown';
}

/** Where a notification leads: its icon, and the screen of its record in this company with the permission it takes. */
export interface NotificationRecord {
  readonly icon: string;
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
]);

export function notificationRecord(type: string): NotificationRecord {
  return RECORDS.get(type) ?? { icon: 'notifications', route: null, permission: null };
}
