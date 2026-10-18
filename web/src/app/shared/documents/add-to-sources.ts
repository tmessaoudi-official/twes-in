// SPDX-License-Identifier: AGPL-3.0-or-later

import { InjectionToken, type Type } from '@angular/core';
import type { PickOption } from '../form/pick-field';
import type { AddToDraft, AddToKind, AddToParty } from './add-to-dialog';

/** How one kind of document reads its open drafts and the customers it may be written for. */
export interface AddToReader {
  drafts(companyId: string, customerId: string | null): Promise<readonly AddToDraft[]>;
  customers(companyId: string, words: string): Promise<readonly PickOption[]>;
}

/**
 * A kind of document goods can be added to, declared by its feature beside its `*_NAV`: `shared/` imports no feature,
 * so the features hand their kinds in. The reader is loaded only when the picker opens, which keeps
 * the document's API off every page that never offers it.
 */
export interface AddToSource {
  readonly kind: AddToKind;
  /** Whom it is written for, by whom its drafts are found. */
  readonly party: AddToParty;
  /** The module the kind belongs to, and the right writing it takes: a kind the person may not write is not offered. */
  readonly module: string;
  readonly permission: string;
  /** Where its editor lives: `<path>/new` or `<path>/<id>`, which takes the goods in from `?add=`. */
  readonly path: string;
  readonly reader: () => Promise<Type<AddToReader>>;
}

/**
 * The kinds offered, in the order given, provided by the screens that offer « Ajouter à… » beside `AddToDocuments`:
 * not at the root, where the first page would carry them for every screen that never offers it.
 */
export const ADD_TO_SOURCES = new InjectionToken<readonly AddToSource[]>('ADD_TO_SOURCES');
