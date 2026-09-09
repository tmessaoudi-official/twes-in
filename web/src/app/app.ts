// SPDX-License-Identifier: AGPL-3.0-or-later
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Component, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MatToolbarModule } from '@angular/material/toolbar';
import { TranslatePipe } from '@ngx-translate/core';
import { catchError, map, of } from 'rxjs';

type ApiStatus = 'checking' | 'ok' | 'degraded' | 'unreachable';

interface HealthResponse {
  status: string;
  database: string;
}

@Component({
  selector: 'app-root',
  imports: [MatToolbarModule, TranslatePipe],
  templateUrl: './app.html',
  styleUrl: './app.scss',
})
export class App {
  private readonly http = inject(HttpClient);

  /** One probe of the API at start-up; the status line is the whole G0 user interface. */
  protected readonly apiStatus = toSignal(
    this.http.get<HealthResponse>('/api/health').pipe(
      map((body): ApiStatus => (body.status === 'ok' ? 'ok' : 'degraded')),
      catchError((error: HttpErrorResponse) =>
        of<ApiStatus>(
          (error.error as Partial<HealthResponse> | null)?.status === 'degraded'
            ? 'degraded'
            : 'unreachable',
        ),
      ),
    ),
    { initialValue: 'checking' as ApiStatus },
  );
}
