import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import globals from 'globals';
import { fixupPluginRules } from '@eslint/compat';
import pluginReact from 'eslint-plugin-react';
import pluginReactHooks from 'eslint-plugin-react-hooks';
import configPrettier from 'eslint-config-prettier';

// eslint-plugin-react@7.37.5 は ESLint10 で削除された context.getFilename() 等のレガシー
// APIにまだ依存しており、素の登録だとルール読み込み時にクラッシュする
// (jsx-eslint/eslint-plugin-react#3977)。fixupPluginRules で後方互換ブリッジをかける
// (a-blog cms本体のeslint.config.mjsと同じ対処)。
const pluginReactCompat = fixupPluginRules(pluginReact);

export default tseslint.config(
  {
    ignores: ['src/dist/**', 'build/**', 'coverage/**', 'vendor/**', 'docs/**'],
  },

  js.configs.recommended,
  // tseslint.configs.recommended は files 未指定の要素を含むため、extends で .ts/.tsx 限定の
  // files を全要素に継承させ、JSファイル(scripts/*.mjs等)へ @typescript-eslint/* ルールが
  // 波及しないようにする(a-blog cms本体のeslint.config.mjsと同じ構成)。
  {
    files: ['**/*.ts', '**/*.tsx'],
    extends: [...tseslint.configs.recommended],
  },

  {
    files: ['assets/src/**/*.{ts,tsx}'],
    languageOptions: {
      parser: tseslint.parser,
      parserOptions: {
        ecmaFeatures: { jsx: true },
      },
      globals: {
        ...globals.browser,
        // src/global.d.ts で `declare global { let ACMS: Acms }` として型宣言している
        // a-blog cms管理画面のグローバル変数。
        ACMS: 'readonly',
      },
    },
    plugins: {
      react: pluginReactCompat,
      'react-hooks': pluginReactHooks,
    },
    settings: {
      react: { version: 'detect' },
    },
    rules: {
      ...pluginReact.configs.flat.recommended.rules,
      ...pluginReact.configs.flat['jsx-runtime'].rules,
      'react-hooks/rules-of-hooks': 'error',
      'react-hooks/exhaustive-deps': 'warn',
      'react/prop-types': 'off',
      '@typescript-eslint/no-unused-vars': 'error',
      'no-unused-vars': 'off',
    },
  },

  {
    files: ['*.mjs', 'scripts/**/*.mjs', 'vitest.config.ts', 'vitest.setup.ts', 'tsdown.config.ts'],
    languageOptions: {
      globals: { ...globals.node },
    },
  },

  {
    // vitest公式ドキュメント推奨のセットアップ(`/// <reference types="vitest/config" />`)。
    // config自体の型を読み込むための正規の書き方であり、書き換える理由がない。
    files: ['vitest.config.ts'],
    rules: {
      '@typescript-eslint/triple-slash-reference': 'off',
    },
  },

  // Prettier とのルール競合解除は必ず最後
  configPrettier
);
