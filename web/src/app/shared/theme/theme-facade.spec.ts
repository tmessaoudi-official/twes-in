// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { Session } from '../session/session';
import { BrowserStorageSettings } from '../settings/browser-storage-settings';
import { PageMemoryStorage, SETTINGS_STORAGE, SettingsFacade } from '../settings/settings-facade';
import { colourTokens, InvalidAccentColour, statusTokens } from './accent-theme';
import { DEFAULT_ACCENT, ThemeFacade } from './theme-facade';

describe('ThemeFacade', () => {
  const root = document.documentElement;

  let storage: PageMemoryStorage;

  beforeEach(() => {
    storage = new PageMemoryStorage();
    root.className = '';
    root.removeAttribute('style');
  });

  function start(): ThemeFacade {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: storage },
        { provide: Session, useValue: { me: () => ({ user: { id: 'u1' } }) } },
      ],
    });
    const facade = TestBed.inject(ThemeFacade);
    TestBed.tick();
    return facade;
  }

  const primary = () => root.style.getPropertyValue('--mat-sys-primary');

  it('applies the default accent in the light scheme as soon as it starts', () => {
    start();
    expect(primary()).toBe(colourTokens(DEFAULT_ACCENT, 'light')['--mat-sys-primary']);
    expect(root.classList.contains('theme-dark')).toBe(false);
  });

  it('applies the status tones of the scheme beside the system colours', () => {
    const facade = start();
    const green = () => root.style.getPropertyValue('--twes-status-success-fg');
    expect(green()).toBe(statusTokens('light')['--twes-status-success-fg']);

    facade.setScheme('dark');
    TestBed.tick();

    expect(green()).toBe(statusTokens('dark')['--twes-status-success-fg']);
  });

  it('leaves the status tones alone when the accent changes: a status never borrows the brand colour', () => {
    const facade = start();
    const info = () => root.style.getPropertyValue('--twes-status-info-fg');
    const before = info();

    facade.setAccent('#d93025');
    TestBed.tick();

    expect(info()).toBe(before);
    expect(info()).toBe(statusTokens('light')['--twes-status-info-fg']);
  });

  it('switches to the dark scheme: the class for color-scheme and the dark tokens', () => {
    const facade = start();
    facade.setScheme('dark');
    TestBed.tick();

    expect(root.classList.contains('theme-dark')).toBe(true);
    expect(primary()).toBe(colourTokens(DEFAULT_ACCENT, 'dark')['--mat-sys-primary']);
    expect(facade.scheme()).toBe('dark');
  });

  it('changes the colours with every transition held for one frame, so nothing fades from the old scheme', () => {
    const frames: FrameRequestCallback[] = [];
    const requestFrame = vi
      .spyOn(window, 'requestAnimationFrame')
      .mockImplementation((callback) => frames.push(callback));
    const facade = start();
    frames.splice(0).forEach((frame) => frame(0));
    expect(root.classList.contains('theme-changing')).toBe(false);

    facade.setScheme('dark');
    TestBed.tick();

    expect(root.classList.contains('theme-dark')).toBe(true);
    expect(root.classList.contains('theme-changing')).toBe(true);
    frames.splice(0).forEach((frame) => frame(0));
    expect(root.classList.contains('theme-changing')).toBe(false);
    requestFrame.mockRestore();
  });

  it('toggles between the two schemes', () => {
    const facade = start();
    facade.toggleScheme();
    TestBed.tick();
    expect(facade.scheme()).toBe('dark');
    facade.toggleScheme();
    TestBed.tick();
    expect(root.classList.contains('theme-dark')).toBe(false);
  });

  it('re-themes the whole document from a new accent', () => {
    const facade = start();
    facade.setAccent('#d93025');
    TestBed.tick();
    expect(primary()).toBe(colourTokens('#d93025', 'light')['--mat-sys-primary']);
  });

  it('refuses an invalid accent and keeps the current theme', () => {
    const facade = start();
    const before = primary();
    expect(() => facade.setAccent('blue')).toThrow(InvalidAccentColour);
    TestBed.tick();
    expect(primary()).toBe(before);
  });

  it('toggles between comfortable and compact density', () => {
    const facade = start();
    facade.toggleDensity();
    TestBed.tick();
    expect(facade.density()).toBe('compact');
    expect(root.classList.contains('density-compact')).toBe(true);
    facade.toggleDensity();
    TestBed.tick();
    expect(facade.density()).toBe('comfortable');
  });

  it('marks the document for compact density, and removes the mark again', () => {
    const facade = start();
    facade.setDensity('compact');
    TestBed.tick();
    expect(root.classList.contains('density-compact')).toBe(true);
    facade.setDensity('comfortable');
    TestBed.tick();
    expect(root.classList.contains('density-compact')).toBe(false);
  });

  it('toggles the sidebar between expanded and a rail, and keeps it for the next page load', () => {
    const facade = start();
    expect(facade.sidebar()).toBe('expanded');
    facade.toggleSidebar();
    TestBed.tick();
    expect(facade.sidebar()).toBe('rail');

    const reloaded = start();
    expect(reloaded.sidebar()).toBe('rail');
    reloaded.toggleSidebar();
    TestBed.tick();
    expect(reloaded.sidebar()).toBe('expanded');
  });

  it('keeps scheme, density and accent for the next page load', () => {
    const facade = start();
    facade.setScheme('dark');
    facade.setDensity('compact');
    facade.setAccent('#D93025');

    root.className = '';
    const reloaded = start();

    expect(reloaded.scheme()).toBe('dark');
    expect(reloaded.density()).toBe('compact');
    expect(reloaded.accent()).toBe('#d93025');
    expect(root.classList.contains('theme-dark')).toBe(true);
  });
});
