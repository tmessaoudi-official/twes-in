// SPDX-License-Identifier: AGPL-3.0-or-later

import { settingRules } from '../shared/settings/setting-forms';
import type { FieldKind, FormDescriptor } from '../shared/form/form-types';
import type { PlatformSupportRules, SupportChannel } from './platform-types';

export const SUPPORT_CHANNELS: readonly SupportChannel[] = [
  'phone',
  'whatsapp',
  'email',
  'status_url',
];

/** What each channel's field takes, so a phone's keyboard shows the right keys. */
const KINDS: Readonly<Record<SupportChannel, FieldKind>> = {
  phone: 'tel',
  whatsapp: 'tel',
  email: 'email',
  status_url: 'url',
};

/**
 * « Contact du support » as a form: each channel optional (an empty one is taken away), held to the rule its setting
 * declares, refused in the API's own sentence; none read yet holds a channel to nothing but the API's answer.
 */
export function supportForm(rules: PlatformSupportRules | null): FormDescriptor {
  return {
    id: 'platform-support',
    sections: [
      {
        id: 'support',
        title: 'platform.support.title',
        description: 'platform.support.intro',
        fields: SUPPORT_CHANNELS.map((channel) => ({
          id: channel,
          label: `platform.support.${channel}`,
          hint: `platform.support.${channel}_hint`,
          kind: KINDS[channel],
          ...(rules === null ? {} : settingRules(rules[channel])),
        })),
      },
    ],
  };
}
