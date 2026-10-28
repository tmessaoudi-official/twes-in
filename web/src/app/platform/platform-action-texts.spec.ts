// SPDX-License-Identifier: AGPL-3.0-or-later

import arAccount from '../../../public/i18n/account/ar.json';
import enAccount from '../../../public/i18n/account/en.json';
import frAccount from '../../../public/i18n/account/fr.json';
import arMain from '../../../public/i18n/ar.json';
import enMain from '../../../public/i18n/en.json';
import frMain from '../../../public/i18n/fr.json';
import ar from '../../../public/i18n/platform/ar.json';
import en from '../../../public/i18n/platform/en.json';
import fr from '../../../public/i18n/platform/fr.json';

const LANGUAGES = [
  ['fr', fr, frAccount, frMain],
  ['en', en, enAccount, enMain],
  ['ar', ar, arAccount, arMain],
] as const;

/**
 * A confirmation that says how its action is undone names the action as the row will then offer it: a refused company
 * was never opened, so its row offers « Approuver », never « Rétablir l'accès », which only an opened one shows.
 */
describe('the platform confirmations name the action that undoes them', () => {
  it.each(LANGUAGES)(
    'in %s, refusing names « approve » and suspending names « restore »',
    (_language, texts) => {
      const actions = texts.platform.companies.actions;
      expect(actions.reject_message).toContain(actions.approve);
      expect(actions.reject_message).not.toContain(actions.restore);
      expect(actions.suspend_message).toContain(actions.restore);
    },
  );
});

/** One act, one name: what a person presses is what the question it opens and the page it leads to call it. */
describe('the platform names an act as the page it shares it with', () => {
  it.each(LANGUAGES)(
    'in %s, an operator ends their other sessions in « Mon compte »’s words',
    (_language, texts, account) => {
      const asked = account.account.devices.end_others_title.replace(/\s*[?؟]$/u, '');
      expect(texts.platform.accounts.end_other_sessions).toBe(asked);
    },
  );

  it.each(LANGUAGES)(
    'in %s, the palette names the installation texts as their page does',
    (_language, texts, _account, main) => {
      expect(main.shell.platform_texts).toBe(texts.platform.texts.title);
    },
  );
});
