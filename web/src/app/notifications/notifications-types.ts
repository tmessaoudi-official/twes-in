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
export const KNOWN_NOTIFICATION_TYPES = ['membership.added', 'invitation.accepted'] as const;

/** "membership.added" → "notifications.types.membership_added"; an unknown type → the generic key. */
export function notificationKey(type: string): string {
  return (KNOWN_NOTIFICATION_TYPES as readonly string[]).includes(type)
    ? `notifications.types.${type.replaceAll('.', '_')}`
    : 'notifications.types.unknown';
}
