// SPDX-License-Identifier: AGPL-3.0-or-later

import { Pipe, type PipeTransform } from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';
import { refusalKey, type UnknownRefusal, type UnreadPart } from './unknown-refusal';

/**
 * A refusal's code said in a template: `{{ code | refusal: 'settings.errors' }}` reads `settings.errors.<code>`, and an
 * answer the screen has no words for (`{ unknown }`) the one sentence naming its code, `feedback.unknown_refusal`.
 * Parameters a screen's own words take (`{ retry }`) go third. Impure, as the translate pipe it extends, so a language
 * changed or texts arriving later are said again.
 */
@Pipe({ name: 'refusal', pure: false })
export class RefusalPipe extends TranslatePipe implements PipeTransform {
  override transform(
    code: string | UnknownRefusal | UnreadPart | null | undefined,
    prefix: string,
    extra: Record<string, unknown> = {},
  ): string {
    if (code === null || code === undefined || code === '') return '';
    const { key, params } = refusalKey(prefix, code);
    return super.transform(key, { ...extra, ...params }) as string;
  }
}
