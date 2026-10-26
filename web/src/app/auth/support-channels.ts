// SPDX-License-Identifier: AGPL-3.0-or-later

import type { SupportContact } from './auth-types';

/** One way of reaching the platform's operators, as a link that opens it: a call, a WhatsApp chat or a mail. */
export interface SupportChannel {
  readonly key: 'phone' | 'whatsapp' | 'email';
  readonly icon: 'call' | 'chat' | 'mail';
  readonly href: string;
  readonly shown: string;
}

/**
 * The ways the session names to reach the operators, each as its link, a channel no operator gave left out: the « ? »
 * panel of every screen and the page a member of a shut company waits on say the same ones.
 */
export function supportChannels(support: SupportContact | undefined): readonly SupportChannel[] {
  const channels: SupportChannel[] = [];
  if (support?.phone) {
    channels.push({
      key: 'phone',
      icon: 'call',
      href: `tel:${support.phone.replace(/[^+\d]/g, '')}`,
      shown: support.phone,
    });
  }
  if (support?.whatsapp) {
    // WhatsApp's link takes the international number in digits alone, without its « + ».
    channels.push({
      key: 'whatsapp',
      icon: 'chat',
      href: `https://wa.me/${support.whatsapp.replace(/\D/g, '')}`,
      shown: support.whatsapp,
    });
  }
  if (support?.email) {
    channels.push({
      key: 'email',
      icon: 'mail',
      href: `mailto:${support.email}`,
      shown: support.email,
    });
  }
  return channels;
}
