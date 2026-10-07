// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { BuildInfo } from './build-info';
import { NewVersionBanner, RELOAD_PAGE } from './new-version-banner';

// docs/SPEC.md § 7, 2026-10-07 14:24: « Nouvelle version disponible — Recharger », never a reload of its own.
describe('NewVersionBanner', () => {
  const newWeb = signal(false);
  const info = { newWeb, start: vi.fn() };
  const reload = vi.fn();
  let fixture: ComponentFixture<NewVersionBanner>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  beforeEach(() => {
    newWeb.set(false);
    reload.mockReset();
    info.start.mockReset();
    TestBed.configureTestingModule({
      imports: [NewVersionBanner],
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        { provide: BuildInfo, useValue: info },
        { provide: RELOAD_PAGE, useValue: reload },
      ],
    });
    fixture = TestBed.createComponent(NewVersionBanner);
    fixture.detectChanges();
  });

  it('starts asking which build is out, and says nothing while this page is the latest', () => {
    expect(info.start).toHaveBeenCalled();
    expect(q('new-version')).toBeNull();
  });

  it('offers to reload once a new web build is out, and reloads only when asked', () => {
    newWeb.set(true);
    fixture.detectChanges();

    expect(q('new-version')?.getAttribute('role')).toBe('status');
    expect(q('new-version')?.textContent).toContain('build.new_version');
    expect(reload).not.toHaveBeenCalled();
    q('new-version-reload')!.click();
    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('never reloads by itself, even when it arrives on a page that already knows of a new build', () => {
    newWeb.set(true);
    fixture.destroy();
    fixture = TestBed.createComponent(NewVersionBanner);
    fixture.detectChanges();

    expect(q('new-version')).not.toBeNull();
    expect(reload).not.toHaveBeenCalled();
  });
});
