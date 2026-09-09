// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@hey-api/openapi-ts';

// Types only (docs/SPEC.md § 7, 2026-09-09): the OpenAPI document exported by the API becomes TypeScript types
// in src/app/api/ (gitignored); services over HttpClient are written by hand. `npm run api:types` runs it.
export default defineConfig({
  input: process.env['OPENAPI_JSON'] ?? '../api/var/openapi.json',
  output: { path: 'src/app/api', postProcess: ['prettier'] },
  plugins: ['@hey-api/typescript'],
});
