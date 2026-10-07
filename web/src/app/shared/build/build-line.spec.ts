// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { Clipboard } from '@angular/cdk/clipboard';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { MatTooltip } from '@angular/material/tooltip';
import { By } from '@angular/platform-browser';
import {
  provideTranslateLoader,
  provideTranslateService,
  type TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { provideQuietFeedback, successToasts } from '../testing/feedback';
import { BuildInfo } from './build-info';
import { BuildLine } from './build-line';
import type { ApiBuild, PartBuild } from './build-types';

// shared/ reads no feature's files, the translations included: the few strings this line shows, inline.
class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      build: {
        unversioned: 'non versionnée',
        hint: 'Web {{web}} · API {{api}} — cliquer pour copier',
        copied: 'Copié',
      },
    });
  }
}

// docs/SPEC.md § 7, 2026-10-07 14:28 and 14:31: « Web 2026.10.07.3 dev · API 2026.10.07.5 · [staging] ».
describe('BuildLine', () => {
  const web = signal<PartBuild | null>({ version: '2026.10.07.3', commit: 'aaaa1111' });
  const api = signal<ApiBuild | null>({
    version: '2026.10.07.5',
    commit: 'bbbb2222',
    mode: 'prod',
    deployment: 'prod',
  });
  const info = {
    web,
    api,
    webMode: null as 'dev' | null,
    line: signal('Web 2026.10.07.3 (aaaa1111) · API 2026.10.07.5 (bbbb2222)'),
    start: vi.fn(),
  };
  const clipboard = { copy: vi.fn(() => true) };
  let fixture: ComponentFixture<BuildLine>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  function open(): void {
    TestBed.configureTestingModule({
      imports: [BuildLine],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: BuildInfo, useValue: info },
        { provide: Clipboard, useValue: clipboard },
      ],
    });
    fixture = TestBed.createComponent(BuildLine);
    fixture.detectChanges();
  }

  beforeEach(() => {
    web.set({ version: '2026.10.07.3', commit: 'aaaa1111' });
    api.set({ version: '2026.10.07.5', commit: 'bbbb2222', mode: 'prod', deployment: 'prod' });
    info.webMode = null;
    info.start.mockReset();
    clipboard.copy.mockClear();
  });

  it('shows each part’s version, and nothing of production', () => {
    open();

    expect(info.start).toHaveBeenCalled();
    expect(q('build-line')?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'Web 2026.10.07.3 · API 2026.10.07.5',
    );
    expect(q('build-deployment')).toBeNull();
  });

  it('names each part’s mode and the deployment when they are not production', () => {
    info.webMode = 'dev';
    api.set({ version: '2026.10.07.5', commit: 'bbbb2222', mode: 'dev', deployment: 'staging' });
    open();

    expect(q('build-web')?.textContent?.replace(/\s+/g, ' ').trim()).toBe('Web 2026.10.07.3 dev');
    expect(q('build-api')?.textContent?.replace(/\s+/g, ' ').trim()).toBe('API 2026.10.07.5 dev');
    expect(q('build-deployment')?.textContent?.trim()).toBe('[staging]');
  });

  it('says a part built without a version is unversioned, and waits for an API that has not answered', () => {
    web.set({ version: null, commit: null });
    api.set(null);
    open();

    expect(q('build-web')?.textContent).toContain('Web non versionnée');
    expect(q('build-api')).toBeNull();
  });

  it('gives the hashes on hover or focus, and copies the whole line for support on a click', () => {
    open();
    const line = q('build-line') as HTMLButtonElement;
    expect(line.tagName).toBe('BUTTON');
    const tooltip = fixture.debugElement.query(By.directive(MatTooltip)).injector.get(MatTooltip);
    expect(tooltip.message).toBe('Web aaaa1111 · API bbbb2222 — cliquer pour copier');

    line.click();
    expect(clipboard.copy).toHaveBeenCalledWith(
      'Web 2026.10.07.3 (aaaa1111) · API 2026.10.07.5 (bbbb2222)',
    );
    expect(successToasts()).toContain('build.copied');
  });
});
