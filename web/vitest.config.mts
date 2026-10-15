// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from 'vitest/config';

// @material/material-color-utilities 0.4.0 publishes ESM whose relative imports carry no file extension, which Node's
// resolver refuses; Vite resolves them, so the package is processed by Vite instead of being loaded by Node.
export default defineConfig({
  test: {
    server: { deps: { inline: ['@material/material-color-utilities'] } },
  },
});
