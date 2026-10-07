// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * A part's build as git said it when its image was built (scripts/build-version.sh): `YYYY.MM.DD.N`, « -dirty » when
 * built from uncommitted changes, and the short hash of its last commit. Both null for an image built without them.
 */
export interface PartBuild {
  version: string | null;
  commit: string | null;
}

/** What the API says of itself: its build, the environment its kernel runs in, and the deployment, as it is. */
export interface ApiBuild extends PartBuild {
  /** `prod`, `dev` or `test`: the footer names it only when it is not `prod`. */
  mode: string;
  /** `dev`, `staging` or `prod`, or null when the deployment names none: shown only when it is not `prod`. */
  deployment: string | null;
}
