// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { CAMERA_ABSENT_MS, CameraScanning } from './camera-scanning';

/** A camera whose frames are the codes a test says it sees, and a clock the test moves. */
function rig(seen: (at: number) => readonly string[]) {
  let now = 0;
  const accepted: string[] = [];
  const scanning = new CameraScanning({
    now: () => now,
    read: () => Promise.resolve(seen(now)),
    accept: (code) => accepted.push(code),
  });
  const at = async (time: number) => {
    now = time;
    await scanning.frame();
  };
  return { scanning, accepted, at };
}

describe('CameraScanning', () => {
  it('counts a code once while it stays in view, however many frames show it', async () => {
    const { accepted, at } = rig(() => ['3017620422003']);

    for (let time = 0; time <= 5000; time += 100) await at(time);

    expect(accepted).toEqual(['3017620422003']);
  });

  it('counts it again once it has left the view and come back, as a till counts a second item', async () => {
    const { accepted, at } = rig((time) =>
      time < 1000 || time >= 1000 + CAMERA_ABSENT_MS ? ['3017620422003'] : [],
    );

    for (let time = 0; time <= 1000 + CAMERA_ABSENT_MS + 500; time += 100) await at(time);

    expect(accepted).toEqual(['3017620422003', '3017620422003']);
  });

  it('does not count a code that only flickered out for a frame or two', async () => {
    const { accepted, at } = rig((time) => (time === 300 || time === 400 ? [] : ['ABC-1']));

    for (let time = 0; time <= 2000; time += 100) await at(time);

    expect(accepted).toEqual(['ABC-1']);
  });

  it('counts another code at once, and each code of a frame holding several', async () => {
    const { accepted, at } = rig((time) => (time < 500 ? ['A-1'] : ['A-1', 'B-2']));

    for (let time = 0; time <= 1000; time += 100) await at(time);

    expect(accepted).toEqual(['A-1', 'B-2']);
  });

  it('keeps scanning when a frame cannot be read', async () => {
    let calls = 0;
    const accepted: string[] = [];
    const scanning = new CameraScanning({
      now: () => calls * 100,
      read: () => {
        calls += 1;
        return calls === 1 ? Promise.reject(new Error('frame lost')) : Promise.resolve(['X-9']);
      },
      accept: (code) => accepted.push(code),
    });

    await scanning.frame();
    await scanning.frame();

    expect(accepted).toEqual(['X-9']);
  });
});
