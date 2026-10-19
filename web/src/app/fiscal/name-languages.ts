// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, inject, signal } from '@angular/core';
import type { SelectOption } from '../shared/form/select';
import { languageChoices } from '../shared/i18n/language-options';
import { DOCUMENT_LANGUAGES, type Language } from '../shared/i18n/languages';
import { SettingsApi } from '../shared/settings/settings-api';
import { nameField } from './fiscal-forms';
import type { LanguageNames } from './fiscal-types';

/** What the company prints in: its own documents' language, and those its customers and their groups hold. */
interface InUse {
  documents: Language;
  parties: readonly Language[];
}

const isDocumentLanguage = (value: unknown): value is Language =>
  typeof value === 'string' && DOCUMENT_LANGUAGES.includes(value);

/**
 * The languages a unit's or a tax's names form shows, as ruled for languages that grow: the company's documents
 * language, the languages its customers print in and any the record already names; the rest are asked for through
 * « Ajouter une langue », and every one but the documents' own may be let go. A page makes one, loads it with the
 * company and opens it with each record; it hands `DescriptorForm` what to withhold, remove and note.
 */
export class NameLanguages {
  private readonly settings = inject(SettingsApi);

  /** Null while unknown: then every language is shown, as nothing tells which ones matter. */
  private readonly inUse = signal<InUse | null>(null);
  private readonly added = signal<ReadonlySet<Language>>(new Set());
  private readonly dropped = signal<ReadonlySet<Language>>(new Set());

  private readonly shown = computed<readonly Language[]>(() => {
    const inUse = this.inUse();
    if (inUse === null) return DOCUMENT_LANGUAGES;
    const added = this.added();
    const dropped = this.dropped();
    return DOCUMENT_LANGUAGES.filter(
      (language) =>
        language === inUse.documents ||
        ((inUse.parties.includes(language) || added.has(language)) && !dropped.has(language)),
    );
  });

  readonly withheld = computed<ReadonlySet<string>>(() => {
    const shown = this.shown();
    return new Set(DOCUMENT_LANGUAGES.filter((tag) => !shown.includes(tag)).map(nameField));
  });

  readonly removable = computed<ReadonlySet<string>>(() => {
    const documents = this.inUse()?.documents;
    if (documents === undefined) return new Set();
    return new Set(
      this.shown()
        .filter((tag) => tag !== documents)
        .map(nameField),
    );
  });

  readonly notes = computed<Readonly<Record<string, string>>>(() => {
    const documents = this.inUse()?.documents;
    return documents === undefined
      ? {}
      : { [nameField(documents)]: 'fiscal.names.documents_language' };
  });

  /** The languages « Ajouter une langue » offers, each named in itself by the shared language dropdown. */
  readonly addable = computed<SelectOption[]>(() => {
    const shown = this.shown();
    return languageChoices(
      DOCUMENT_LANGUAGES.filter((tag) => !shown.includes(tag)),
      'names-add',
    );
  });

  async load(companyId: string): Promise<void> {
    try {
      const answer = await this.settings.valuesInUse(companyId, 'document.language');
      this.inUse.set(
        isDocumentLanguage(answer.company)
          ? {
              documents: answer.company,
              parties: (answer.parties ?? []).filter(isDocumentLanguage),
            }
          : null,
      );
    } catch {
      // Unknown: every language stays on the form, as it was before the company's languages could be read.
      this.inUse.set(null);
    }
  }

  /** A record opened: the languages it names are shown, and nothing let go before stays let go. */
  open(names: LanguageNames): void {
    this.added.set(
      new Set(
        Object.entries(names)
          .filter(([tag, name]) => isDocumentLanguage(tag) && name.trim() !== '')
          .map(([tag]) => tag),
      ),
    );
    this.dropped.set(new Set());
  }

  add(language: Language): void {
    this.added.update((added) => new Set([...added, language]));
    this.dropped.update((dropped) => new Set([...dropped].filter((tag) => tag !== language)));
  }

  /** The field `DescriptorForm` emptied; the documents language stays shown whatever is let go (`shown`). */
  remove(fieldId: string): void {
    const language = DOCUMENT_LANGUAGES.find((tag) => nameField(tag) === fieldId);
    if (language === undefined) return;
    this.dropped.update((dropped) => new Set([...dropped, language]));
    this.added.update((added) => new Set([...added].filter((tag) => tag !== language)));
  }
}
