// SPDX-License-Identifier: AGPL-3.0-or-later

import type { TourAnchor } from './tour-anchors';

/**
 * One stop of a guided tour: where it points, and what it says there. The place is a shared anchor
 * (`TOUR_ANCHORS`), never one screen's own markup, so a screen redrawn around the same components keeps its tour.
 */
export interface TourStep {
  readonly anchor: TourAnchor;
  /** Translation keys. */
  readonly titleKey: string;
  readonly bodyKey: string;
  /** The page the step stands on, opened first when the tour is somewhere else. */
  readonly route?: string;
}

/**
 * A task tour, declared by its module beside its navigation and its commands and gated the same way, so a person
 * is offered only the tours of what they may do. One definition drives the in-app guide and its playback in CI.
 */
export interface Tour {
  readonly key: string;
  /** What the tour is called in the help panel. */
  readonly titleKey: string;
  /** Its line in the command palette, written out in full (« Guide : … ») so typing « guide » finds it. */
  readonly commandKey: string;
  readonly permission?: string;
  readonly module?: string;
  readonly steps: readonly TourStep[];
}
