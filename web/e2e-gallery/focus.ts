// SPDX-License-Identifier: AGPL-3.0-or-later
import type { Page } from '@playwright/test';

/** One element, named so a person can find it: its test id, else its tag, type and id. */
export type Named = string;

/**
 * Where the focus is on a screen, and what a person would type into first: facts for the review's lens 53 (focus and
 * typing flow), never a verdict. Whether a list's search should take the focus, or a phone open its keyboard, is the
 * developer's ruling; the review judges each screen from these facts.
 */
export interface FocusFacts {
  /** `body` when nothing holds the focus, which is where typed keys go nowhere. */
  focused: Named;
  /** The first field that takes typing, in the open dialog when there is one, else in the page's content. */
  firstTypeable: Named | null;
  /** How many such fields that same place holds. */
  typeable: number;
  dialogOpen: boolean;
  /** A `DataList` is on the page: a list, whose search is a field among others. */
  list: boolean;
}

export async function focusFacts(page: Page): Promise<FocusFacts> {
  return page.evaluate(() => {
    const name = (element: Element): string => {
      const tested = element.closest('[data-testid]');
      if (tested !== null) return `[data-testid="${tested.getAttribute('data-testid')}"]`;
      const type = element.getAttribute('type');
      return `${element.tagName.toLowerCase()}${type === null ? '' : `[type=${type}]`}${element.id === '' ? '' : `#${element.id}`}`;
    };
    // Fields a key press writes into; a checkbox, a button or a file picker takes no typing.
    const untyped = new Set([
      'hidden',
      'checkbox',
      'radio',
      'button',
      'submit',
      'reset',
      'file',
      'range',
      'color',
      'image',
    ]);
    const takesTyping = (element: Element): boolean => {
      if (element instanceof HTMLTextAreaElement) return !element.disabled && !element.readOnly;
      if (element instanceof HTMLInputElement) {
        return !untyped.has(element.type) && !element.disabled && !element.readOnly;
      }
      return element instanceof HTMLElement && element.isContentEditable;
    };
    const shown = (element: Element): boolean =>
      element.getClientRects().length > 0 && getComputedStyle(element).visibility !== 'hidden';
    const dialogs = [...document.querySelectorAll('mat-dialog-container, [role="dialog"]')].filter(
      shown,
    );
    const dialog = dialogs.at(-1);
    // The page's own content, not the shell around it: the menu and the bars hold no field a screen is for.
    const place =
      dialog ??
      document.querySelector('mat-sidenav-content main, main, mat-sidenav-content') ??
      document.body;
    const fields = [...place.querySelectorAll('input, textarea, [contenteditable="true"]')].filter(
      (element) => takesTyping(element) && shown(element),
    );
    const active = document.activeElement;
    return {
      focused:
        active === null || active === document.body || active === document.documentElement
          ? 'body'
          : name(active),
      firstTypeable: fields[0] === undefined ? null : name(fields[0]),
      typeable: fields.length,
      dialogOpen: dialog !== undefined,
      list: document.querySelector('app-data-list') !== null,
    };
  });
}
