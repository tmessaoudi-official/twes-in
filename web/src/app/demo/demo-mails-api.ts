// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { DemoMails } from '../api/types.gen';

/** The journal kind a mail kept for the demo is written under, which tells an open page to read the mails again. */
export const DEMO_MAIL_KIND = 'demo_mail';

/** A mail the application would have sent the visitor, kept instead. */
export interface DemoMail {
  readonly id: string;
  readonly subject: string;
  /** When it would have left, as the API wrote it. */
  readonly capturedAt: string;
  /** As the mail was rendered; bound through Angular's sanitizer, so nothing in it runs. */
  readonly html: string;
}

/** The one importer of the generated types for « Courriers de la démo ». */
@Injectable({ providedIn: 'root' })
export class DemoMailsApi {
  private readonly http = inject(HttpClient);

  /** The mails kept for the sandbox the company is, the latest first; a company that is no sandbox answers 404. */
  async list(companyId: string): Promise<readonly DemoMail[]> {
    const raw = await firstValueFrom(
      this.http.get<DemoMails>(`/api/companies/${encodeURIComponent(companyId)}/demo-mails`),
    );
    return raw.mails.map((mail) => ({
      id: mail.id,
      subject: mail.subject,
      capturedAt: mail.capturedAt,
      html: mail.html,
    }));
  }
}
