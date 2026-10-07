// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  computed,
  DestroyRef,
  DOCUMENT,
  inject,
  Injectable,
  InjectionToken,
  isDevMode,
  signal,
} from '@angular/core';
import { BuildApi } from './build-api';
import type { ApiBuild, PartBuild } from './build-types';

/** How often a page asks which builds are out, besides when it starts and when it comes back into view. */
export const BUILD_CHECK_INTERVAL = new InjectionToken<number>('BUILD_CHECK_INTERVAL', {
  providedIn: 'root',
  factory: () => 5 * 60 * 1000,
});

/** The web's own build mode, named only when it is not production: an Angular development build says `dev`. */
export const WEB_MODE = new InjectionToken<'dev' | null>('WEB_MODE', {
  providedIn: 'root',
  factory: () => (isDevMode() ? 'dev' : null),
});

/**
 * Which builds answer (docs/SPEC.md § 7, the build line): the web build this page runs, read once when it starts, and
 * the API's, read again and again. When the server later holds another web build, `newWeb` says so and the banner
 * offers to reload; a new API only changes what the footer reads, since the page keeps working with it.
 */
@Injectable({ providedIn: 'root' })
export class BuildInfo {
  private readonly api$ = inject(BuildApi);
  private readonly interval = inject(BUILD_CHECK_INTERVAL);
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  readonly webMode = inject(WEB_MODE);

  /** The build this page runs: the first one read, never replaced. Null until a server answered. */
  readonly web = signal<PartBuild | null>(null);
  readonly api = signal<ApiBuild | null>(null);
  /** The server holds a web build other than the one this page runs. */
  readonly newWeb = signal(false);

  /** The whole line, as support is told it: « Web 2026.10.07.3 (276aba07) dev · API … · staging ». */
  readonly line = computed(() => {
    const parts = [part('Web', this.web(), this.webMode)];
    const api = this.api();
    parts.push(part('API', api, api !== null && api.mode !== 'prod' ? api.mode : null));
    const deployment = api?.deployment;
    if (deployment && deployment !== 'prod') parts.push(deployment);
    return parts.join(' · ');
  });

  private started = false;

  /** Reads both builds now, then on a timer and whenever the page comes back into view or focus. Once, whoever asks. */
  start(): void {
    if (this.started) return;
    this.started = true;
    void this.check();
    const timer = setInterval(() => void this.check(), this.interval);
    const back = () => {
      if (this.document.visibilityState !== 'hidden') void this.check();
    };
    // A tab shown again, and a window brought forward from another (which leaves the tab visible all along).
    this.document.addEventListener('visibilitychange', back);
    this.document.defaultView?.addEventListener('focus', back);
    this.destroyRef.onDestroy(() => {
      clearInterval(timer);
      this.document.removeEventListener('visibilitychange', back);
      this.document.defaultView?.removeEventListener('focus', back);
    });
  }

  private async check(): Promise<void> {
    const [web, api] = await Promise.all([this.api$.web(), this.api$.api()]);
    if (api !== null) this.api.set(api);
    if (web === null) return;
    const own = this.web();
    if (own === null) this.web.set(web);
    else if (own.version !== web.version || own.commit !== web.commit) this.newWeb.set(true);
  }
}

function part(name: string, build: PartBuild | null, mode: string | null): string {
  const version = build?.version ?? '?';
  const commit = build?.commit ? ` (${build.commit})` : '';
  return `${name} ${version}${commit}${mode ? ` ${mode}` : ''}`;
}
