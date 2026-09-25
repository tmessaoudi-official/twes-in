// SPDX-License-Identifier: AGPL-3.0-or-later

/** A step of « Premiers pas » as the API worked it out: done or not, from what the company has. */
export interface FirstStep {
  readonly key: string;
  readonly done: boolean;
}

/** What the signed-in member may still set up in the company (docs/SPEC.md § 7, 2026-09-25 22:17, row 139). */
export interface FirstSteps {
  readonly remaining: number;
  readonly steps: readonly FirstStep[];
}

/**
 * Where each step is done. A step the API names and this map does not is still shown, without a link: the API
 * decides what the steps are, the screen only where to go for them.
 */
export const FIRST_STEP_LINKS: Readonly<Record<string, string>> = {
  'company.profile': '/company/profile',
  'fiscal.taxes': '/fiscal/taxes',
  'customers.first': '/customers/new',
  'products.first': '/products/new',
  'company.brand': '/settings',
  'company.members': '/members',
};

/** What a step reads: a change to any of them may tick one off, or bring one back. */
export const FIRST_STEPS_KINDS = [
  'company',
  'tax_component',
  'customer',
  'product',
  'setting',
  'membership',
  'invitation',
] as const;
