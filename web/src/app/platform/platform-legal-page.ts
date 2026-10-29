// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  linkedSignal,
  type OnInit,
  signal,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { Feedback } from '../shared/feedback/feedback';
import { emailTaken } from '../shared/form/email-rule';
import type { FieldError } from '../shared/form/form-builder';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { PhoneField } from '../shared/form/phone-field';
import { phoneRefusal } from '../shared/form/phone-numbers';
import { refusedError } from '../shared/form/refusal-words';
import { Select, type SelectOption } from '../shared/form/select';
import { UnsavedChanges } from '../shared/form/unsaved-changes';
import { DayPipe, MomentPipe } from '../shared/i18n/format-pipes';
import { languageChoices } from '../shared/i18n/language-options';
import { directionOf } from '../shared/i18n/languages';
import {
  LEGAL_LANGUAGE_NAMES,
  LEGAL_LANGUAGES,
  type LegalLanguage,
} from '../shared/legal/legal-api';
import { LegalMarkdown } from '../shared/legal/legal-markdown';
import { LEGAL_PAGES, type LegalPage } from '../shared/legal/legal-pages';
import { PlatformLegalFacade } from './platform-legal-facade';
import type { LegalStatusRow, LegalVersionRow } from './platform-legal-types';

interface Opened {
  readonly page: LegalPage;
  readonly language: LegalLanguage;
}

/** A piece of « English et العربية »: a language's name, in its own `lang`, or the screen's words between two. */
type ListPart =
  | { readonly kind: 'language'; readonly language: LegalLanguage; readonly name: string }
  | { readonly kind: 'literal'; readonly text: string };

/**
 * The platform's legal pages as an operator writes them: one row per page, where it stands in the language chosen
 * above the table and what is left to validate in the others, so the table keeps its width however many languages
 * ship; the dropdown counts what waits in each. Then the page opened, in Markdown, with a preview rendered exactly as
 * the public page renders it. Publishing adds a new version at once, marked a draft; validating marks the latest one
 * checked. What was typed for a page and not published stays with that page while another is opened, for as long as
 * this screen is.
 */
@Component({
  selector: 'app-platform-legal-page',
  imports: [
    DayPipe,
    FormsModule,
    LegalMarkdown,
    MatButtonModule,
    MatIconModule,
    MomentPipe,
    PhoneField,
    RouterLink,
    Select,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './platform-legal-page.html',
})
export class PlatformLegalPage implements OnInit {
  protected readonly facade = inject(PlatformLegalFacade);
  private readonly feedback = inject(Feedback);
  private readonly translate = inject(TranslateService);
  /**
   * The country a phone of the publisher or the host is read by: the platform's own, which its operators set, whatever
   * company is open; none until it is read.
   */
  protected readonly phoneCountry = computed(() => this.facade.country() ?? '');
  protected readonly pages = LEGAL_PAGES;
  protected readonly names = LEGAL_LANGUAGE_NAMES;
  protected readonly directionOf = directionOf;

  /** The language the table shows: the screen's, when the legal pages are written in it. */
  protected readonly language = signal<LegalLanguage>(
    LEGAL_LANGUAGES.find((tag) => tag === this.translate.getCurrentLang()) ?? LEGAL_LANGUAGES[0],
  );
  /** The screen's language, which joins the names of the other languages: « English et العربية ». */
  private readonly screen = toSignal(this.translate.onLangChange, { initialValue: null });

  protected readonly opened = signal<Opened | null>(null);
  protected readonly text = signal('');
  /** Until the open page's history arrives, which then fills the editor: anything typed sooner would be replaced. */
  protected readonly loading = signal(false);
  protected readonly previewing = signal(false);
  /** The texts typed and not published, by page and language. */
  private readonly drafts = signal<ReadonlyMap<string, string>>(new Map());

  protected readonly statuses = computed(
    () => new Map(this.facade.overview().map((row) => [key(row.page, row.language), row])),
  );
  /** Every page each language has not validated yet, a page never written in it among them. */
  private readonly waiting = computed(
    () =>
      new Map(
        LEGAL_LANGUAGES.map((language) => [
          language,
          LEGAL_PAGES.filter(
            (page) => this.statuses().get(key(page, language))?.validated !== true,
          ),
        ]),
      ),
  );
  protected readonly waitingTotal = computed(() =>
    [...this.waiting().values()].reduce((total, pages) => total + pages.length, 0),
  );
  protected readonly languageOptions = computed<SelectOption[]>(() =>
    languageChoices(LEGAL_LANGUAGES, 'legal-overview-language').map((option) => ({
      ...option,
      count: this.waiting().get(option.value)?.length ?? 0,
      countKind: 'waiting',
      countLabel: 'platform.legal.to_validate',
    })),
  );

  /**
   * For each page, the languages other than the chosen one it waits in: how many, and their names joined as the
   * screen's language joins them, each name in its own `lang`.
   */
  protected readonly others = computed(() => {
    this.screen();
    const chosen = this.language();
    const screen = this.translate.getCurrentLang();
    const join = new Intl.ListFormat(screen === null ? [] : [screen], { type: 'conjunction' });
    return new Map(
      LEGAL_PAGES.map((page) => {
        const languages = LEGAL_LANGUAGES.filter(
          (language) =>
            language !== chosen && this.waiting().get(language)?.includes(page) === true,
        );
        let next = 0;
        const parts = join
          .formatToParts(languages.map((language) => this.names[language]))
          .map<ListPart>((part) => {
            if (part.type === 'literal') return { kind: 'literal', text: part.value };
            const language = languages[next++];
            return { kind: 'language', language, name: this.names[language] };
          });
        return [page, { count: languages.length, parts }] as const;
      }),
    );
  });

  /** The open page's latest version, null when it was never written in that language. */
  protected readonly latest = computed<LegalVersionRow | null>(
    () => this.facade.versions()[0] ?? null,
  );
  protected readonly changed = computed(
    () => this.text().trim() !== '' && this.text() !== (this.latest()?.body ?? ''),
  );

  /** The identity as typed, until saved: the saved one again after every load or save. */
  protected readonly identityDraft = linkedSignal<Readonly<Record<string, string>>>(() => ({
    ...this.facade.identity(),
  }));
  protected readonly identityFields = computed(() => Object.keys(this.facade.identity()));
  private readonly identityChanges = computed(() => {
    const saved = this.facade.identity();
    const draft = this.identityDraft();
    return Object.fromEntries(Object.entries(draft).filter(([key, value]) => value !== saved[key]));
  });
  protected readonly identityChanged = computed(
    () => Object.keys(this.identityChanges()).length > 0,
  );
  /** Whether a save of the identity was asked: what it would refuse is said from then on, as it is typed. */
  private readonly identityTried = signal(false);
  /** The fields typed again since the API refused them, whose refusal no longer says what they hold. */
  private readonly retyped = signal<ReadonlySet<string>>(new Set());
  /**
   * Each identity field refused, by placeholder: an address the one e-mail rule refuses, said before the save asks who
   * is at the screen, since the API asks that before it reads what was sent; and what the API refused in the last save,
   * until that field is typed again.
   */
  private readonly identityRefused = computed(() => {
    const refused = new Map<string, FieldRefusal>();
    for (const refusal of this.facade.identityRefusals()) {
      if (!this.retyped().has(refusal.field)) refused.set(refusal.field, refusal);
    }
    if (this.identityTried()) {
      for (const [placeholder, value] of Object.entries(this.identityDraft())) {
        if (this.addressField(placeholder) && value.trim() !== '' && !emailTaken(value)) {
          refused.set(placeholder, { field: placeholder, reason: 'invalid_email', params: {} });
        }
        // A phone kept from before the rule is left as it is until it is typed again, as every form leaves it.
        const phone = this.phoneField(placeholder) ? phoneRefusal(value) : null;
        if (phone !== null && value !== this.facade.identity()[placeholder]) {
          refused.set(placeholder, {
            field: placeholder,
            reason: phone.key.replace(/^import\.rejections\./, ''),
            params: phone.params ?? {},
          });
        }
      }
    }
    return refused;
  });
  /**
   * What is typed and not saved: the identity's fields changed, the open text, and the texts kept for pages opened
   * before. Leaving the screen or reloading it loses them all, so the page is held while any is there.
   */
  private readonly unsaved = computed(() => {
    const opened = this.opened();
    const open = opened === null ? null : key(opened.page, opened.language);
    const others = [...this.drafts().keys()].filter((each) => each !== open).length;
    return Object.keys(this.identityChanges()).length + (this.changed() ? 1 : 0) + others;
  });

  /** What the preview fills the placeholders with: the saved identity, as the public page will. */
  protected readonly filled = computed(() =>
    Object.fromEntries(
      Object.entries(this.facade.identity()).filter(([, value]) => value.trim() !== ''),
    ),
  );

  constructor() {
    inject(UnsavedChanges).declare(this.unsaved);
  }

  ngOnInit(): void {
    void this.facade.load();
  }

  protected statusOf(page: LegalPage, language: LegalLanguage): LegalStatusRow | undefined {
    return this.statuses().get(key(page, language));
  }

  protected choose(language: string | null): void {
    const chosen = LEGAL_LANGUAGES.find((tag) => tag === language);
    if (chosen !== undefined) this.language.set(chosen);
  }

  protected isOpen(page: LegalPage, language: LegalLanguage): boolean {
    const opened = this.opened();
    return opened !== null && opened.page === page && opened.language === language;
  }

  protected async open(page: LegalPage, language: LegalLanguage): Promise<void> {
    const leaving = this.opened();
    if (leaving !== null) {
      const leftText = this.changed() ? this.text() : null;
      this.drafts.update((drafts) => kept(drafts, key(leaving.page, leaving.language), leftText));
    }
    this.opened.set({ page, language });
    this.previewing.set(false);
    this.text.set('');
    this.loading.set(true);
    await this.facade.open(page, language);
    if (!this.isOpen(page, language)) return;
    this.text.set(this.drafts().get(key(page, language)) ?? this.latest()?.body ?? '');
    this.loading.set(false);
  }

  /** How a text writes this fact, for the operator to copy. */
  protected written(placeholder: string): string {
    return `{{${placeholder}}}`;
  }

  protected typedIdentity(placeholder: string, event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.identityDraft.update((draft) => ({ ...draft, [placeholder]: value }));
    this.retyped.update((retyped) => new Set([...retyped, placeholder]));
  }

  /** A phone field hands its number in international form, as it is typed or its country picked. */
  protected typedPhone(placeholder: string, value: string): void {
    this.identityDraft.update((draft) => ({ ...draft, [placeholder]: value }));
    this.retyped.update((retyped) => new Set([...retyped, placeholder]));
  }

  /** Whether an identity field holds a phone: the one phone field, its country picked beside the local number. */
  protected phoneField(placeholder: string): boolean {
    return placeholder.endsWith('.phone');
  }

  /** What is said under a phone field, in the screen's words, or nothing. */
  protected phoneError(placeholder: string): string {
    const error = this.identityError(placeholder);
    return error === null ? '' : (this.translate.instant(error.key, error.params) as string);
  }

  /** Whether an identity field holds an e-mail address: it shows an example and is held to the one e-mail rule. */
  protected addressField(placeholder: string): boolean {
    return placeholder.endsWith('.email');
  }

  /** What a reader hears after an identity field's name: its example, then what is refused in it. */
  protected identityDescribedBy(placeholder: string): string | null {
    const ids = [
      ...(this.addressField(placeholder) ? [`legal-identity-${placeholder}-hint`] : []),
      ...(this.identityError(placeholder) === null ? [] : [`legal-identity-${placeholder}-error`]),
    ];
    return ids.length === 0 ? null : ids.join(' ');
  }

  /** What is said under an identity field the screen or the API refuses, in the words every form uses. */
  protected identityError(placeholder: string): FieldError | null {
    const refusal = this.identityRefused().get(placeholder);
    return refusal === undefined ? null : refusedError(this.translate, refusal);
  }

  protected async saveIdentity(): Promise<void> {
    this.identityTried.set(true);
    const first = this.identityFields().find((placeholder) =>
      this.identityRefused().has(placeholder),
    );
    if (first !== undefined) {
      document.querySelector<HTMLElement>(`[data-testid="legal-identity-${first}"]`)?.focus();
      return;
    }
    this.retyped.set(new Set());
    const outcome = await this.facade.saveIdentity(this.identityChanges());
    if (outcome === 'saved') this.feedback.success('platform.legal.identity.saved');
    else if (outcome === 'saved_since') this.feedback.failure('live.saved_since');
  }

  protected typed(event: Event): void {
    this.text.set((event.target as HTMLTextAreaElement).value);
  }

  protected reuse(version: LegalVersionRow): void {
    this.text.set(version.body);
    this.previewing.set(false);
  }

  protected async publish(): Promise<void> {
    const opened = this.opened();
    if (opened === null || !(await this.facade.write(this.text()))) return;
    this.drafts.update((drafts) => kept(drafts, key(opened.page, opened.language), null));
    this.text.set(this.latest()?.body ?? this.text());
    this.feedback.success('platform.legal.published');
  }

  protected async validate(): Promise<void> {
    if (await this.facade.validate()) {
      this.feedback.success('platform.legal.validated_toast');
    }
  }
}

/** The drafts with this page's text kept, or let go when null. */
function kept(
  drafts: ReadonlyMap<string, string>,
  page: string,
  text: string | null,
): ReadonlyMap<string, string> {
  const next = new Map(drafts);
  if (text === null) next.delete(page);
  else next.set(page, text);
  return next;
}

function key(page: LegalPage, language: LegalLanguage): string {
  return `${page}.${language}`;
}
