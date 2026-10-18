// SPDX-License-Identifier: AGPL-3.0-or-later

import { effect, inject, untracked } from '@angular/core';
import type { AbstractControl } from '@angular/forms';
import { Router } from '@angular/router';
import { Feedback } from '../feedback/feedback';
import { type LineFields, type LineList, scanIntoLines } from '../scan/scan-lines';

/**
 * The products an address hands a document, `?add=<id>&add=<id>` from « Ajouter à… »: each once, in the order they
 * were chosen.
 */
export function productsAsked(add: string | readonly string[] | undefined): readonly string[] {
  if (add === undefined) return [];
  return [...new Set(typeof add === 'string' ? [add] : add)].filter((id) => id !== '');
}

/** What « Ajouter à… » left on a document's lines: how many products went on, and how many were not found. */
export interface TakenIn {
  readonly added: number;
  readonly leftOut: number;
  /** The products found that the document refuses by a rule of its own, and the notice that names the rule. */
  readonly refused?: { readonly count: number; readonly key: string };
}

/** A document editor as « Ajouter à… » needs it: its lines ready to take products, and whether it still may. */
export interface AddedInto {
  /** The products the address asks for, the `add` input. */
  readonly add: () => string | readonly string[] | undefined;
  /**
   * The document once its lines and the customer it names are read, since the customer's regime says which taxes a
   * line takes; null until then. `id` is null on a new document.
   */
  readonly ready: () => { readonly id: string | null; readonly editable: boolean } | null;
  /** Puts the products on the lines by the document's own rule; null when it could not read them. */
  readonly take: (asked: readonly string[]) => Promise<TakenIn | null>;
}

/**
 * Takes in, once, the products « Ajouter à… » sent a document editor (`?add=`), from its constructor: the address then
 * forgets them, so a reload or Back adds nothing, and a toast says how many went on and how many were not found.
 * Nothing is saved: the lines are the person's to look over and save. A document that is no longer a draft takes
 * nothing, and says so.
 */
export function takeInAdded(into: AddedInto): void {
  const router = inject(Router);
  const feedback = inject(Feedback);
  // Until the address forgets them, a rebuilt form or another customer must not add them a second time.
  let taken: string | null = null;
  effect(() => {
    const asked = productsAsked(into.add());
    if (asked.length === 0) {
      taken = null;
      return;
    }
    const document = into.ready();
    if (document === null) return;
    const key = `${document.id ?? 'new'}|${asked.join(',')}`;
    if (taken === key) return;
    taken = key;
    untracked(() => {
      void router.navigate([], {
        queryParams: { add: null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
      if (!document.editable) {
        feedback.notice('documents.add_to.not_draft');
        return;
      }
      void into.take(asked).then((done) => {
        if (done === null) return;
        if (done.added > 0) feedback.notice('documents.add_to.taken_in', { count: done.added });
        if (done.leftOut > 0) feedback.notice('documents.add_to.left_out', { count: done.leftOut });
        if (done.refused !== undefined && done.refused.count > 0) {
          feedback.notice(done.refused.key, { count: done.refused.count });
        }
      });
    });
  });
}

/**
 * Puts the products asked for on a document's lines, in the order asked, one of each, as a scan would place it: the
 * same product in the same unit counts one more, anything else starts a line, in place of the empty one a new
 * document opens with. `fresh` builds a line with the product applied, by the document's own rule; `placed` is told
 * of each line a product went onto, so the document can price it as it prices a scan.
 */
export function productsIntoLines<
  L extends AbstractControl,
  P extends { readonly id: string; readonly unitId: string },
>(
  lines: LineList<L>,
  fields: (line: L) => LineFields,
  asked: readonly string[],
  found: readonly P[],
  fresh: (product: P) => L,
  placed: (line: L, product: P) => void,
): TakenIn {
  const byId = new Map(found.map((product) => [product.id, product]));
  let added = 0;
  for (const id of asked) {
    const product = byId.get(id);
    if (product === undefined) continue;
    const placement = scanIntoLines(
      lines,
      fields,
      { productId: product.id, unitId: product.unitId, count: 1 },
      () => fresh(product),
    );
    placed(lines.at(placement.index), product);
    added += 1;
  }
  return { added, leftOut: asked.length - added };
}
