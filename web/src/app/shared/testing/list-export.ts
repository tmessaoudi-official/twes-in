// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ComponentFixture } from '@angular/core/testing';
import type { ExportFormat } from '../list/export-address';

/**
 * Opens a list's « Exporter ▾ » and finds one format's entry, which the menu draws over the page rather than inside the
 * component; null where the list offers no export at all.
 */
export function exportEntry(
  fixture: ComponentFixture<unknown>,
  testId: string,
  format: ExportFormat,
): HTMLElement | null {
  const trigger = (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(
    `[data-testid="${testId}"]`,
  );
  if (trigger === null) return null;
  if (trigger.getAttribute('aria-expanded') !== 'true') {
    trigger.click();
    fixture.detectChanges();
  }
  return document.body.querySelector<HTMLElement>(`[data-testid="${testId}-${format}"]`);
}
