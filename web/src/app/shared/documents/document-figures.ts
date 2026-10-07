// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, InjectionToken, inject, type Signal } from '@angular/core';
import { toObservable, toSignal } from '@angular/core/rxjs-interop';
import { debounceTime, from, map, of, switchMap } from 'rxjs';

/** One line as the calculator works it out: what it is before any discount, after its own, and what it adds. */
export interface LineFigures {
  /** Quantity × price. */
  readonly amount: string;
  readonly discount: string;
  readonly net: string;
  /** Its share of the document discount. */
  readonly documentDiscount: string;
  /** Each of its taxes, as this line carries it: its share of the document's, so they add up to it. */
  readonly taxes: readonly {
    readonly code: string;
    readonly base: string;
    readonly amount: string;
  }[];
  /** What the line adds to the document, tax included. */
  readonly total: string;
}

export interface RatedFigure {
  readonly code: string;
  readonly rate: string;
  readonly base: string;
  readonly amount: string;
}

/**
 * What a document with lines comes to while it is typed, as the API's one calculator works it out. A line the preview
 * could not include yet, one still missing what it needs, is null at its position.
 */
export interface DocumentFigures {
  readonly lines: readonly (LineFigures | null)[];
  readonly subtotalNet: string;
  readonly documentDiscount: string;
  readonly totalNet: string;
  readonly taxes: readonly RatedFigure[];
  readonly totalTax: string;
  readonly fixedTaxes: readonly { readonly code: string; readonly amount: string }[];
  readonly total: string;
  readonly withholdings: readonly RatedFigure[];
  /** The total less what is withheld at source. */
  readonly netToPay: string;
}

/** What the API answers to a preview, before it is read: its OpenAPI type is named after each document. */
export interface PreviewBody {
  lines?: LineFigures[];
  subtotalNet?: string;
  documentDiscount?: string;
  totalNet?: string;
  taxes?: RatedFigure[];
  totalTax?: string;
  fixedTaxes?: { code: string; amount: string }[];
  total?: string;
  withholdings?: RatedFigure[];
  netToPay?: string;
}

/**
 * An amount shown as taken off. A credit note carries its sign on every figure, its discounts and withholdings
 * included, so putting a minus in front of one would print two.
 */
export function negated(amount: string): string {
  return amount.startsWith('-') ? amount.slice(1) : `-${amount}`;
}

/** Reads a preview's answer; a line sent from position `positions[i]` lands back there, the others stay null. */
export function toDocumentFigures(
  body: PreviewBody,
  positions: readonly number[],
  count: number,
): DocumentFigures {
  const lines: (LineFigures | null)[] = Array.from({ length: count }, () => null);
  (body.lines ?? []).forEach((line, sent) => {
    const at = positions[sent];
    if (at !== undefined && at < count) lines[at] = line;
  });
  return {
    lines,
    subtotalNet: body.subtotalNet ?? '0',
    documentDiscount: body.documentDiscount ?? '0',
    totalNet: body.totalNet ?? '0',
    taxes: body.taxes ?? [],
    totalTax: body.totalTax ?? '0',
    fixedTaxes: body.fixedTaxes ?? [],
    total: body.total ?? '0',
    withholdings: body.withholdings ?? [],
    netToPay: body.netToPay ?? '0',
  };
}

/**
 * How long typing rests before the figures are asked for: long enough that a number typed is not asked digit by
 * digit, short enough that the figures follow the hand.
 */
export const PREVIEW_DELAY = new InjectionToken<number>('PREVIEW_DELAY', {
  providedIn: 'root',
  factory: () => 250,
});

/**
 * A document's figures as it is typed. `draft` is what would be sent, or null when there is nothing to ask (a
 * document that no longer changes, or one missing who it is for); it is asked once it has rested, a later draft
 * replaces an answer still on its way, and an equal draft is not asked again. Null until the first answer, and
 * whenever the API refuses the draft: figures for something else are never shown as this one's.
 */
export function liveFigures<T>(
  draft: () => T | null,
  ask: (draft: T) => Promise<DocumentFigures | null>,
): Signal<DocumentFigures | null> {
  const delay = inject(PREVIEW_DELAY);
  const rested = computed(draft, { equal: (a, b) => JSON.stringify(a) === JSON.stringify(b) });
  return toSignal(
    toObservable(rested).pipe(
      debounceTime(delay),
      switchMap((body) => (body === null ? of(null) : from(ask(body)))),
      map((figures) => figures ?? null),
    ),
    { initialValue: null },
  );
}
