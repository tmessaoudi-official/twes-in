// SPDX-License-Identifier: AGPL-3.0-or-later
import type { Page } from '@playwright/test';

/** One thing on a screen that looks wrong to a measurement, named so a person can find it. */
export interface Finding {
  kind:
    | 'sideways'
    | 'raw-key'
    | 'raw-interpolation'
    | 'cut-text'
    | 'off-screen'
    | 'overlap'
    | 'misaligned-row'
    | 'image-without-alt';
  /** Where it is: the nearest test id, else the element's tag and classes. */
  where: string;
  /** What was measured, in a few words. */
  detail: string;
}

/**
 * What a screen shows that a measurement can call wrong, for the screen sweep: a picture shows how a screen looks,
 * this says where to look. Measured at the window's own size, before a picture stretches it to the page's height.
 * Every check errs towards silence: a finding is something a person should see, never a style opinion.
 */
export async function auditScreen(page: Page): Promise<Finding[]> {
  return page.evaluate(() => {
    const found: { kind: string; where: string; detail: string }[] = [];
    const seen = new Set<string>();
    const add = (kind: string, element: Element, detail: string): void => {
      const where = name(element);
      const key = `${kind}|${where}|${detail}`;
      if (seen.has(key)) return;
      seen.add(key);
      found.push({ kind, where, detail });
    };
    function name(element: Element): string {
      const tested = element.closest('[data-testid]');
      if (tested !== null) return `[data-testid="${tested.getAttribute('data-testid')}"]`;
      const classes = [...element.classList].slice(0, 3).join('.');
      return `${element.tagName.toLowerCase()}${classes === '' ? '' : `.${classes}`}`;
    }
    // Seen by a sighted person: drawn, not visually hidden for a screen reader (a skip link, a column header named
    // « Actions »), and not clipped away by a scrolling ancestor (a menu entry scrolled out of its panel).
    const visible = (element: Element): boolean => {
      const rect = element.getBoundingClientRect();
      if (rect.width <= 1 || rect.height <= 1) return false;
      const style = getComputedStyle(element);
      if (
        style.visibility === 'hidden' ||
        style.display === 'none' ||
        Number(style.opacity) === 0
      ) {
        return false;
      }
      let left = rect.left;
      let right = rect.right;
      let top = rect.top;
      let bottom = rect.bottom;
      for (let at = element.parentElement; at !== null; at = at.parentElement) {
        const outer = getComputedStyle(at);
        if (outer.clip === 'rect(0px, 0px, 0px, 0px)' || outer.clipPath === 'inset(50%)')
          return false;
        if (outer.overflowX === 'visible' && outer.overflowY === 'visible') continue;
        const box = at.getBoundingClientRect();
        left = Math.max(left, box.left);
        right = Math.min(right, box.right);
        top = Math.max(top, box.top);
        bottom = Math.min(bottom, box.bottom);
        if (right - left < 1 || bottom - top < 1) return false;
      }
      return style.clip !== 'rect(0px, 0px, 0px, 0px)' && style.clipPath !== 'inset(50%)';
    };
    // Inside a scroller or a closed overlay, a control past the window is reached by scrolling, not lost.
    const scrolls = (element: Element): boolean => {
      for (
        let at = element.parentElement;
        at !== null && at !== document.body;
        at = at.parentElement
      ) {
        const style = getComputedStyle(at);
        if (/(auto|scroll)/.test(style.overflowX) && at.scrollWidth > at.clientWidth) return true;
        if (at.tagName === 'MAT-SIDENAV-CONTENT') return false;
      }
      return false;
    };

    const documentElement = document.documentElement;
    const panel = document.querySelector('mat-sidenav-content');
    const sideways = Math.max(
      documentElement.scrollWidth - documentElement.clientWidth,
      panel === null ? 0 : panel.scrollWidth - panel.clientWidth,
    );
    if (sideways > 0)
      add('sideways', panel ?? documentElement, `${sideways} px wider than the window`);

    // A key the translations did not answer reads « inventory.plan.title »; an interpolation left raw reads « {{x}} ».
    const rawKey = /^[a-z][a-z0-9_]*(\.[a-z0-9_-]+){2,}$/;
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
      const text = (node.textContent ?? '').trim();
      const parent = node.parentElement;
      if (text === '' || parent === null || !visible(parent)) continue;
      if (parent.closest('script, style, code, pre, textarea')) continue;
      if (rawKey.test(text)) add('raw-key', parent, text);
      if (/\{\{|\}\}/.test(text)) add('raw-interpolation', parent, text.slice(0, 80));
    }

    const width = window.innerWidth;
    const controls = [
      ...document.querySelectorAll(
        'button, a[href], input, select, textarea, [role="button"], mat-select',
      ),
    ].filter(visible);
    for (const control of controls) {
      if (control.closest('.cdk-overlay-container')) continue;
      const rect = control.getBoundingClientRect();
      if ((rect.right > width + 1 || rect.left < -1) && !scrolls(control)) {
        add(
          'off-screen',
          control,
          `spans ${Math.round(rect.left)}–${Math.round(rect.right)} of ${width} px`,
        );
      }
    }

    // Two controls drawn over each other: one of them cannot be pressed where it is seen. Content passing under a bar
    // pinned to the window (the phone's bottom bar, the cookie notice) is how a page scrolls, so only two controls of
    // the same pinned layer, or of none, are compared.
    const pinnedLayer = (element: Element): Element | null => {
      for (let at: Element | null = element; at !== null; at = at.parentElement) {
        const position = getComputedStyle(at).position;
        if (position === 'fixed' || position === 'sticky') return at;
      }
      return null;
    };
    const boxes = controls
      .filter((control) => !control.closest('.cdk-overlay-container'))
      .map((control) => ({
        control,
        rect: control.getBoundingClientRect(),
        layer: pinnedLayer(control),
      }));
    for (let one = 0; one < boxes.length; one++) {
      for (let other = one + 1; other < boxes.length; other++) {
        const a = boxes[one]!;
        const b = boxes[other]!;
        if (a.control.contains(b.control) || b.control.contains(a.control)) continue;
        if (a.layer !== b.layer) continue;
        const across = Math.min(a.rect.right, b.rect.right) - Math.max(a.rect.left, b.rect.left);
        const down = Math.min(a.rect.bottom, b.rect.bottom) - Math.max(a.rect.top, b.rect.top);
        const smaller = Math.min(a.rect.width * a.rect.height, b.rect.width * b.rect.height);
        if (across > 2 && down > 2 && (across * down) / smaller > 0.3) {
          add('overlap', a.control, `over ${name(b.control)}`);
        }
      }
    }

    // A row of buttons whose middles differ: one sits higher than its neighbours.
    const buttonLike =
      'button, a.mat-mdc-button-base, a[mat-button], a[mat-stroked-button], a[mat-flat-button]';
    for (const row of document.querySelectorAll('*')) {
      const style = getComputedStyle(row);
      if (style.display !== 'flex' && style.display !== 'inline-flex') continue;
      if (!style.flexDirection.startsWith('row') || style.alignItems === 'baseline') continue;
      const buttons = [...row.children].filter(
        (child) => child.matches(buttonLike) && visible(child),
      );
      if (buttons.length < 2) continue;
      const rects = buttons.map((button) => button.getBoundingClientRect());
      // Buttons wrapped onto two lines are two rows, not a misalignment.
      const firstLine = rects.filter(
        (rect) => Math.abs(rect.top - rects[0]!.top) < rects[0]!.height / 2,
      );
      if (firstLine.length < 2) continue;
      const middles = firstLine.map((rect) => rect.top + rect.height / 2);
      const spread = Math.max(...middles) - Math.min(...middles);
      if (spread > 3) add('misaligned-row', row, `middles ${Math.round(spread)} px apart`);
    }

    // Text cut short with nothing to read the rest by: no tooltip, no title, no accessible name carrying it.
    for (const element of document.querySelectorAll('body *')) {
      if (element.children.length > 0 || !visible(element)) continue;
      const text = (element.textContent ?? '').trim();
      if (text.length < 4) continue;
      const style = getComputedStyle(element);
      if (style.overflowX !== 'hidden' && style.textOverflow !== 'ellipsis') continue;
      if (element.scrollWidth <= element.clientWidth + 1) continue;
      // Cut text is a defect on screen even where a tooltip carries the rest; the finding says which it is.
      const readable = element.closest(
        '[title], [aria-label], [mattooltip], .mat-mdc-tooltip-trigger',
      );
      add('cut-text', element, `${text.slice(0, 60)}${readable === null ? '' : ' (tooltip)'}`);
    }

    for (const image of document.querySelectorAll('img')) {
      if (visible(image) && !image.hasAttribute('alt'))
        add('image-without-alt', image, image.src.slice(-60));
    }

    return found;
  }) as Promise<Finding[]>;
}
