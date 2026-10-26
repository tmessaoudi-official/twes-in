// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from 'vitest/config';

// @material/material-color-utilities 0.4.0 publishes ESM whose relative imports carry no file extension, which Node's
// resolver refuses; Vite resolves them, so the package is processed by Vite instead of being loaded by Node.
export default defineConfig({
  test: {
    server: { deps: { inline: ['@material/material-color-utilities'] } },
    // Half the cores: with one worker a core, a machine also running a gate's API half or another project left specs no
    // headroom, and cases near their five seconds timed out on load alone (7 workers: 130 s, five timeouts; 4: 90 s, none).
    maxWorkers: 4,
  },
});
