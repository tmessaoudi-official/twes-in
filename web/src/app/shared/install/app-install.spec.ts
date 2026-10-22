// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Brand } from '../brand/brand';
import { Feedback } from '../feedback/feedback';
import { TextsParts } from '../i18n/texts-part';
import { provideQuietFeedback, type RecordedFeedback } from '../testing/feedback';
import { AppInstall } from './app-install';
import { InstallGuide } from './install-guide';

/** What Chrome hands the page when it may be installed, its dialog answered as the spec says. */
function offered(prompt: () => Promise<void> = () => Promise.resolve()) {
  const event = new Event('beforeinstallprompt', { cancelable: true });
  return Object.assign(event, { prompt: vi.fn(prompt) });
}

describe('AppInstall', () => {
  const open = vi.fn();
  const textsOpen = vi.fn(() => Promise.resolve());

  function service(): AppInstall {
    TestBed.configureTestingModule({
      providers: [
        provideQuietFeedback(),
        { provide: MatDialog, useValue: { open } },
        { provide: TextsParts, useValue: { open: textsOpen } },
        { provide: Brand, useValue: { name: signal('twes-in') } },
      ],
    });
    return TestBed.inject(AppInstall);
  }

  function said(): RecordedFeedback['said'] {
    return (TestBed.inject(Feedback) as RecordedFeedback).said;
  }

  beforeEach(() => {
    open.mockReset();
    textsOpen.mockClear();
  });

  afterEach(() => {
    Reflect.deleteProperty(window.navigator, 'standalone');
    Reflect.deleteProperty(window, 'matchMedia');
  });

  it('offers nothing where the browser cannot install, so no entry answers nothing', () => {
    expect(service().offer()).toBeNull();
  });

  it('keeps the browser’s offer rather than letting it show its own bar, and opens its dialog once when asked', async () => {
    const install = service();
    const event = offered();

    window.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
    expect(install.offer()).toBe('prompt');
    install.install();
    await Promise.resolve();
    expect(event.prompt).toHaveBeenCalledTimes(1);
    expect(install.offer()).toBeNull();
    expect(said()).toEqual([]);
  });

  it('says so when the browser refuses to open its dialog, and offers it again', async () => {
    const install = service();
    const event = offered(() => Promise.reject(new DOMException('no gesture', 'NotAllowedError')));
    window.dispatchEvent(event);

    install.install();
    await vi.waitFor(() =>
      expect(said()).toEqual([{ kind: 'failure', key: 'shell.install.failed', params: undefined }]),
    );

    expect(install.offer()).toBe('prompt');
  });

  it('says it is installed, however it was, and offers it no more', () => {
    const install = service();
    window.dispatchEvent(offered());

    window.dispatchEvent(new Event('appinstalled'));

    expect(install.offer()).toBeNull();
    expect(said()).toEqual([
      { kind: 'success', key: 'shell.install.installed', params: { name: 'twes-in' } },
    ]);
  });

  it('guides Safari on an iPhone through its own menus, its words opened first', async () => {
    Object.defineProperty(window.navigator, 'standalone', { value: false, configurable: true });
    const install = service();

    expect(install.offer()).toBe('guide');
    install.install();

    await vi.waitFor(() =>
      expect(open).toHaveBeenCalledWith(InstallGuide, { autoFocus: 'dialog' }),
    );
    expect(textsOpen).toHaveBeenCalledWith('install');
    expect(textsOpen.mock.invocationCallOrder[0]).toBeLessThan(open.mock.invocationCallOrder[0]);
  });

  it('offers nothing once it runs installed, from the home screen or as an application window', () => {
    Object.defineProperty(window.navigator, 'standalone', { value: true, configurable: true });
    expect(service().offer()).toBeNull();

    TestBed.resetTestingModule();
    Reflect.deleteProperty(window.navigator, 'standalone');
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      value: (query: string) => ({ matches: query === '(display-mode: standalone)' }),
    });
    const install = service();
    window.dispatchEvent(offered());
    expect(install.offer()).toBeNull();
  });
});
