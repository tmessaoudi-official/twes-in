// SPDX-License-Identifier: AGPL-3.0-or-later

import { INVOICES_TOURS } from '../invoices/invoices-nav';
import type { Tour } from '../shared/tour/tour';

/** Every module's guided tours, each declared by its module beside its navigation. */
export const TOURS: readonly Tour[] = [...INVOICES_TOURS];
