// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';

/**
 * Hands the browser a file the page fetched itself, under the name given: a link a person follows cannot show a
 * refusal in the page, so a download that may be refused is fetched first (the TEJ file, docs/SPEC.md § 7,
 * 2026-09-26).
 */
export function saveFile(file: Blob, filename: string): void {
  const url = URL.createObjectURL(file);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.hidden = true;
  document.body.append(link);
  try {
    link.click();
  } finally {
    link.remove();
    URL.revokeObjectURL(url);
  }
}

/** `saveFile` behind a class, so a component that downloads can be tested without a browser download. */
@Injectable({ providedIn: 'root' })
export class FileSaver {
  save(file: Blob, filename: string): void {
    saveFile(file, filename);
  }
}
