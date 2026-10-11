// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import type { Cue } from './captions';

// Every video just written is opened again in the browser and stopped at the end of each chapter, the frame kept
// as `<video>.check-<n>.png` beside it: what a viewer sees under each line of narration, to be looked at.
const OUT = process.env['VIDEOS_DIR'] ?? '../var/claude/videos';

test('every video plays, has a length, and is pictured at each chapter', async ({ page }) => {
  const language = 'fr';
  const dir = `${OUT}/${language}`;
  const videos = readdirSync(dir).filter((file) => file.endsWith('.cues.json'));
  expect(videos.length, 'videos written').toBeGreaterThan(0);

  for (const cuesFile of videos) {
    const name = cuesFile.replace(/\.cues\.json$/, '');
    const cues = JSON.parse(readFileSync(`${dir}/${cuesFile}`, 'utf8')) as Cue[];
    const body = readFileSync(`${dir}/${name}.webm`);
    // The page and the video from one origin: a frame of a video from another one taints the canvas it is drawn into.
    await page.route('http://recorder.check/**', (route) =>
      route.request().url().endsWith('/video.webm')
        ? route.fulfill({ status: 200, contentType: 'video/webm', body })
        : route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<video id="v" src="/video.webm" muted></video>',
          }),
    );
    await page.goto('http://recorder.check/');
    const duration = await page.evaluate(async () => {
      const video = document.getElementById('v') as HTMLVideoElement;
      if (video.readyState < 1) {
        await new Promise((resolve) =>
          video.addEventListener('loadedmetadata', resolve, { once: true }),
        );
      }
      return video.duration;
    });
    expect(Number.isFinite(duration), `${name} has a length`).toBe(true);
    expect(duration * 1_000, `${name} lasts past its last line`).toBeGreaterThanOrEqual(
      cues.at(-1)!.end,
    );

    // Played from the start, never sought: the recorded WebM is not seekable (`seekable` is empty), a seek lands on the
    // first frame and still says « seeked », and even a seek to 0 stalls it. Each decoded frame past a chapter's moment
    // is copied into a canvas, which is what is kept. The moment is just before the line goes: its caption still up and
    // the work under it done, so the frame shows whether the one hides the other.
    const moments = cues.map((cue) => (cue.end - Math.min(600, (cue.end - cue.start) / 2)) / 1_000);
    const frames = await page.evaluate(async (targets) => {
      const video = document.getElementById('v') as HTMLVideoElement;
      const canvas = document.createElement('canvas');
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      const context = canvas.getContext('2d')!;
      const kept: { at: number; image: string }[] = [];
      await new Promise<void>((resolve, reject) => {
        const giveUp = setTimeout(
          () => reject(new Error(`only ${kept.length} of ${targets.length} frames reached`)),
          (video.duration / 2 + 15) * 1_000,
        );
        const onFrame = (_now: number, frame: VideoFrameCallbackMetadata) => {
          try {
            while (kept.length < targets.length && frame.mediaTime >= targets[kept.length]!) {
              context.drawImage(video, 0, 0);
              kept.push({ at: frame.mediaTime, image: canvas.toDataURL('image/png') });
            }
          } catch (error) {
            // A frame callback's own error is swallowed by the browser: said here, or the check only times out.
            clearTimeout(giveUp);
            reject(error instanceof Error ? error : new Error(String(error)));
            return;
          }
          if (kept.length === targets.length) {
            clearTimeout(giveUp);
            video.pause();
            resolve();
          } else {
            video.requestVideoFrameCallback(onFrame);
          }
        };
        video.requestVideoFrameCallback(onFrame);
        video.playbackRate = 2;
        video.play().catch(reject);
      });
      return kept;
    }, moments);

    for (const [index, frame] of frames.entries()) {
      expect(frame.at - moments[index]!, `${name}, chapter ${index + 1} on time`).toBeLessThan(0.5);
      writeFileSync(
        `${dir}/${name}.check-${index + 1}.png`,
        Buffer.from(frame.image.replace(/^data:image\/png;base64,/, ''), 'base64'),
      );
    }
    // Four chapters showing one picture would mean the film stood still.
    expect(new Set(frames.map((frame) => frame.image)).size, `${name}: frames differ`).toBe(
      frames.length,
    );
    await page.unroute('http://recorder.check/**');
  }
});
