// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * A short beep and a buzz as a code is read, as a handheld scanner gives: the person looks at the goods, not the
 * screen. Said only when `enabled`, which is `presentation.scan-feedback`: a quiet counter and a loud warehouse differ.
 */
export function signalRead(enabled: boolean): void {
  if (!enabled) return;
  navigator.vibrate?.(40);
  const Audio = (window as { AudioContext?: typeof AudioContext }).AudioContext;
  if (Audio === undefined) return;
  try {
    const audio = new Audio();
    const tone = audio.createOscillator();
    const volume = audio.createGain();
    tone.frequency.value = 1800;
    volume.gain.value = 0.08;
    tone.connect(volume).connect(audio.destination);
    tone.start();
    tone.stop(audio.currentTime + 0.08);
    tone.onended = () => void audio.close();
  } catch {
    // No audio output: the buzz and the screen say it.
  }
}
