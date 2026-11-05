// SPDX-License-Identifier: AGPL-3.0-or-later

import { Pipe, type PipeTransform } from '@angular/core';

/** The roles every company shares, and support's own, which the release translates under `roles.`. */
const SHIPPED = new Set(['owner', 'admin', 'member', 'clerk', 'accountant', 'support']);

/**
 * What a role's name is translated through: a shipped role's key, or the name a company gave its own role as it is,
 * which the translation hands back unchanged. Built by hand from `roles.` and the name, a company's « barista » read
 * « roles.barista ».
 */
export const roleKey = (name: string): string => (SHIPPED.has(name) ? `roles.${name}` : name);

/** `{{ company.role | roleKey | translate }}`: the one way a role's name reaches a template. */
@Pipe({ name: 'roleKey' })
export class RoleKeyPipe implements PipeTransform {
  transform(name: string): string {
    return roleKey(name);
  }
}
