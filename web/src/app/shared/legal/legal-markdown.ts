// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { marked } from 'marked';
import type { LegalLanguage } from './legal-api';

/**
 * A legal text's Markdown as the reader sees it, laid out in its own language's direction. `marked` turns it into
 * HTML and `[innerHTML]` binds it through Angular's sanitizer, so a script, an event handler or a `javascript:` link a
 * text carries never runs (`legal-text.spec.ts` reds when the sanitizer is bypassed). The public page and the
 * operator's preview show a text through this one component, so what is previewed is what is published.
 *
 * A text names the publisher's and host's identity by placeholder, `{{publisher.name}}`, which the operator fills in
 * on the platform (`LegalSettings` in the API); one not filled in reads « [à compléter] » in the text's own language,
 * so a missing fact shows rather than vanishing.
 */
@Component({
  selector: 'app-legal-markdown',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<div
    class="twes-legal-body"
    [attr.lang]="language()"
    [attr.dir]="language() === 'ar' ? 'rtl' : 'ltr'"
    [innerHTML]="html()"
    data-testid="legal-body"
  ></div>`,
})
export class LegalMarkdown {
  readonly body = input.required<string>();
  readonly language = input.required<LegalLanguage>();
  /** The identity filled in, by placeholder. */
  readonly values = input<Readonly<Record<string, string>>>({});
  protected readonly html = computed(() =>
    marked.parse(fillPlaceholders(this.body(), this.values(), this.language()), { async: false }),
  );
}

/** What a placeholder not filled in reads, in the text's language. */
export const MISSING: Readonly<Record<LegalLanguage, string>> = {
  fr: '[à compléter]',
  en: '[to be completed]',
  ar: '[يُستكمل]',
};

/** `{{ key }}` replaced by its value, or by the missing mark; spaces inside the braces are allowed. */
export function fillPlaceholders(
  body: string,
  values: Readonly<Record<string, string>>,
  language: LegalLanguage,
): string {
  return body.replace(/\{\{\s*([a-z][a-z_.]*)\s*\}\}/g, (_, key: string) =>
    Object.hasOwn(values, key) ? values[key]! : MISSING[language],
  );
}
