// SPDX-License-Identifier: AGPL-3.0-or-later

/** A line of narration and when it is on screen, in milliseconds from the video's first frame. */
export interface Cue {
  start: number;
  end: number;
  text: string;
}

/** Three words a second is a slow reading pace, so a viewer reads the line and still sees the screen behind it. */
const WORDS_PER_SECOND = 3;
/** The moment the eye takes to find a new line before reading it. */
const FINDING = 1_000;
const SHORTEST = 2_500;
const LONGEST = 9_000;

/** How long a line stays on screen: its reading time, in half seconds, never shorter or longer than a viewer bears. */
export function holdFor(text: string): number {
  const words = text.split(/\s+/).filter((word) => /[\p{L}\p{N}]/u.test(word)).length;
  const reading = FINDING + (words * 1_000) / WORDS_PER_SECOND;
  const rounded = Math.ceil(reading / 500) * 500;
  return Math.min(LONGEST, Math.max(SHORTEST, rounded));
}

export function timestamp(milliseconds: number): string {
  const at = Math.max(0, Math.round(milliseconds));
  const hours = Math.floor(at / 3_600_000);
  const minutes = Math.floor(at / 60_000) % 60;
  const seconds = Math.floor(at / 1_000) % 60;
  const rest = at % 1_000;
  const two = (value: number) => String(value).padStart(2, '0');
  return `${two(hours)}:${two(minutes)}:${two(seconds)}.${String(rest).padStart(3, '0')}`;
}

/** The narration as a WebVTT captions track, beside the video for players that show captions themselves. */
export function subtitles(cues: readonly Cue[]): string {
  return track(cues, (index) => String(index + 1));
}

/** The guide's steps as a WebVTT chapters track. */
export function chapters(cues: readonly Cue[]): string {
  return track(cues, (index) => `chapter-${index + 1}`);
}

function track(cues: readonly Cue[], identifier: (index: number) => string): string {
  const blocks = cues.map((cue, index) => {
    if (cue.end < cue.start) {
      throw new Error(`cue ${index + 1} ends before it starts (${cue.start} → ${cue.end})`);
    }
    return `${identifier(index)}\n${timestamp(cue.start)} --> ${timestamp(cue.end)}\n${cue.text}\n`;
  });
  return `WEBVTT\n\n${blocks.join('\n')}`;
}
