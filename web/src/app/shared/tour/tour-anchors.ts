// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The places a guided tour may point at (docs/SPEC.md § 7, 2026-09-25 22:17, rows 137 and 140). Each is a
 * `data-tour="…"` attribute on a shared component or a home panel, never on one screen's own markup, so a tour step
 * written against « the list's search » keeps working on every list, and a screen redrawn around the same components
 * keeps its tour.
 *
 * `scripts/gates/tour-anchors.sh` checks this list both ways: every name here sits on some element, and every
 * `data-tour` in the app is named here. Removing an anchor a tour uses is therefore a red CI, not a silent tour.
 */
export const TOUR_ANCHORS = [
  'shell-nav',
  'shell-create',
  'shell-palette',
  'shell-account',
  'shell-settings',
  'list-search',
  'list-views',
  'list-columns',
  'list-paginator',
  'form',
  'record-bar',
  'document-actions',
  'actions-final',
  'action-kind',
  'first-steps',
] as const;

export type TourAnchor = (typeof TOUR_ANCHORS)[number];
