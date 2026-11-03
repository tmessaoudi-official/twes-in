// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The texts the home's panels say beyond their module's own (`public/i18n/home/`), opened into the root by each panel
 * that reads them (`TextsParts.open`), never by the home's route: a texts request that fails must not keep anyone from
 * the page they land on. A file of its own, so a panel carries nothing else with it.
 */
export const HOME_TEXTS = 'home';
