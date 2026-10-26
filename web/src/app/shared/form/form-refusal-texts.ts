// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The texts a form's refusal is said in beyond the screens' own file (`import.rejections.*` only a setup form, a document
 * or the stock answers: an establishment's code, a numbering format, a lot's dates), kept apart so the first page does not
 * carry them. Opened by `formRefusalsIn` when a save comes back refused, and by the import page, whose report says the
 * same reasons. A file of its own, so the route that opens them does not carry the adapters' code onto the first page.
 */
export const FORM_REFUSAL_TEXTS = 'form-refusals';
