// SPDX-License-Identifier: AGPL-3.0-or-later

import { roleKey } from '../shared/i18n/role-key';

/**
 * What a membership's role reads as on the platform's accounts list: a shipped role's translation key, or the name a
 * company gave its own, which the translation hands back as it is.
 */
export const membershipRoleLabel = (role: string): string => roleKey(role);
