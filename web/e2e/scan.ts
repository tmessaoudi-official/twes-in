// SPDX-License-Identifier: AGPL-3.0-or-later
import type { Page } from '@playwright/test';

/**
 * A handheld scanner's code, as the scanner types it: every character and the closing Enter within a millisecond or
 * two of each other. Playwright's keyboard cannot promise that: each key is its own round trip to the browser, and on
 * a loaded CI runner two keys arrived 24 ms apart on the driver's side alone (CI run 35824358332) and later more than
 * the 30 ms the app allows (CI run 35833959425, a scan into a line's product field read as a person typing).
 *
 * So the burst is played inside the page, as the keyboard would deliver it to the focused element: keydown, then —
 * unless a handler prevented it — the character inserted and an `input` event, and the Enter's keydown last. Each key
 * is its own task, as a real one is, so the page renders between two keys (a field that empties its list mid-burst
 * must have emptied it before the Enter). And each carries the time it was TYPED, a millisecond after the one before,
 * as a real key carries the time the hardware sent it: a page busy for a moment delivers a scanner's keys late, and
 * the app times them by when they were typed, so that moment must not read as a pause in the test either.
 */
export async function scan(page: Page, code: string): Promise<void> {
  await page.evaluate(async (characters) => {
    const typedAt = performance.now();
    let typed = 0;
    const at = <E extends Event>(event: E): E => {
      Object.defineProperty(event, 'timeStamp', { value: typedAt + typed });
      return event;
    };
    const press = (key: string, keyCode: number): void => {
      typed += 1;
      const target = document.activeElement ?? document.body;
      const down = at(
        new KeyboardEvent('keydown', {
          key,
          keyCode,
          which: keyCode,
          bubbles: true,
          cancelable: true,
        }),
      );
      if (!target.dispatchEvent(down) || key === 'Enter') return;
      if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
        const start = target.selectionStart ?? target.value.length;
        const end = target.selectionEnd ?? target.value.length;
        target.setRangeText(key, start, end, 'end');
        target.dispatchEvent(
          at(new InputEvent('input', { data: key, inputType: 'insertText', bubbles: true })),
        );
      }
    };
    const nextTask = () => new Promise((resolve) => setTimeout(resolve));
    for (const character of characters) {
      press(character, character.toUpperCase().charCodeAt(0));
      await nextTask();
    }
    press('Enter', 13);
    await nextTask();
  }, code);
}

/**
 * Waits until the scan card holds the focus, as a person's next key needs: the card hears its one-key actions on itself,
 * and the dialog moves the focus in only once it has opened. A key pressed while the card is visible but not yet focused
 * goes to the page instead (CI run 35863365591: `i` left the page on /products).
 */
export async function cardHasFocus(page: Page): Promise<void> {
  await page.waitForFunction(() => {
    const card = document.querySelector('[data-testid="product-scan-card"]');
    return card !== null && card.contains(document.activeElement);
  });
}
