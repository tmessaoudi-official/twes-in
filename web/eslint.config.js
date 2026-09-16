// @ts-check
const eslint = require('@eslint/js');
const { defineConfig } = require('eslint/config');
const tseslint = require('typescript-eslint');
const angular = require('angular-eslint');

/** Where HttpClient and the generated OpenAPI types may appear: the API adapters and the application's own wiring. */
const HTTP_ALLOWED = ['**/*-api.ts', '**/*-interceptor.ts', 'src/app/app.config.ts'];
const HTTP_ONLY_IN_ADAPTERS = {
  group: ['@angular/common/http', '@angular/common/http/*'],
  message:
    'Only a feature API adapter (*-api.ts) talks HTTP; components and facades inject the facade.',
};
const GENERATED_TYPES_ONLY_IN_ADAPTERS = {
  regex: '(^|/)api/types\\.gen$',
  message:
    'Only a feature API adapter (*-api.ts) imports the generated OpenAPI types; map them to the feature types.',
};
/** A file of shared/<area>/ reaches a feature by climbing two levels out of shared/; the generated api/ is not a feature. */
const SHARED_IMPORTS_NO_FEATURE = {
  regex: '^\\.\\./\\.\\./(?!api/)',
  message:
    'shared/ depends on no feature: take what it needs through a port in shared/ (see shared/session).',
};

module.exports = defineConfig([
  // Generated from the OpenAPI document by `npm run api:types`; not ours to lint.
  { ignores: ['src/app/api/**'] },
  {
    files: ['**/*.ts'],
    extends: [
      eslint.configs.recommended,
      tseslint.configs.recommended,
      tseslint.configs.stylistic,
      angular.configs.tsRecommended,
    ],
    processor: angular.processInlineTemplates,
    rules: {
      '@angular-eslint/directive-selector': [
        'error',
        {
          type: 'attribute',
          prefix: 'app',
          style: 'camelCase',
        },
      ],
      '@angular-eslint/component-selector': [
        'error',
        {
          type: 'element',
          prefix: 'app',
          style: 'kebab-case',
        },
      ],
    },
  },
  // The web boundaries of docs/SPEC.md § 3 "Web" (audit P2-8, § 7 2026-09-16), enforced instead of kept by discipline.
  {
    files: ['src/**/*.ts'],
    ignores: ['**/*.spec.ts'],
    rules: {
      '@angular-eslint/prefer-on-push-component-change-detection': 'error',
    },
  },
  {
    files: ['src/**/*.ts'],
    ignores: ['**/*.spec.ts', 'src/app/shared/**', ...HTTP_ALLOWED],
    rules: {
      'no-restricted-imports': [
        'error',
        { patterns: [HTTP_ONLY_IN_ADAPTERS, GENERATED_TYPES_ONLY_IN_ADAPTERS] },
      ],
    },
  },
  {
    files: ['src/app/shared/**/*.ts'],
    ignores: ['**/*.spec.ts', ...HTTP_ALLOWED],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [
            HTTP_ONLY_IN_ADAPTERS,
            GENERATED_TYPES_ONLY_IN_ADAPTERS,
            SHARED_IMPORTS_NO_FEATURE,
          ],
        },
      ],
    },
  },
  {
    files: ['src/app/shared/**/*-api.ts', 'src/app/shared/**/*.spec.ts'],
    rules: { 'no-restricted-imports': ['error', { patterns: [SHARED_IMPORTS_NO_FEATURE] }] },
  },
  {
    files: ['**/*.html'],
    extends: [angular.configs.templateRecommended, angular.configs.templateAccessibility],
    rules: {},
  },
]);
