// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The settings' words (`settings.*`: labels, chain headings, choices, hints, the save's outcomes) in their own texts
 * part, out of the file every screen waits for (the screen texts budget). Several screens draw settings, the settings
 * page and every record page holding a level of them (a customer's, a product's, a role's…) among them, and their
 * toasts are drawn from the root, so every route drawing one opens them into the root (`textsOpened`). In a file of its
 * own: the routes import it, and they sit on the first page.
 */
export const SETTINGS_TEXTS = 'settings';
