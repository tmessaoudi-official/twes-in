// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { signalRead } from './scan-signal';

describe('signalRead', () => {
  const vibrate = vi.fn();
  const created = vi.fn();

  class FakeAudio {
    currentTime = 0;
    destination = {};
    constructor() {
      created();
    }
    createOscillator() {
      return {
        frequency: { value: 0 },
        connect: () => ({ connect: () => undefined }),
        start: () => undefined,
        stop: () => undefined,
        onended: null,
      };
    }
    createGain() {
      return { gain: { value: 0 }, connect: () => undefined };
    }
    close() {
      return Promise.resolve();
    }
  }

  beforeEach(() => {
    vibrate.mockClear();
    created.mockClear();
    Object.defineProperty(navigator, 'vibrate', { value: vibrate, configurable: true });
    vi.stubGlobal('AudioContext', FakeAudio);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'vibrate');
  });

  it('beeps and buzzes when the person asked for it', () => {
    signalRead(true);

    expect(vibrate).toHaveBeenCalledWith(40);
    expect(created).toHaveBeenCalledTimes(1);
  });

  it('makes no sound and no buzz when the person turned it off', () => {
    signalRead(false);

    expect(vibrate).not.toHaveBeenCalled();
    expect(created).not.toHaveBeenCalled();
  });
});
