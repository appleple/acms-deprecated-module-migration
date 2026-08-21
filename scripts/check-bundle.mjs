#!/usr/bin/env node
/**
 * src/dist/admin.js は `<script type="module">` でブラウザへ直接読み込ませる単一バンドルで、
 * node_modules も import map も存在しない前提のため、依存パッケージへの裸のimport
 * (bare specifier)が1つでも残っていると実行時に
 * "Failed to resolve module specifier" で壊れる(react-dom/clientの取りこぼしで実際に発生した
 * 回帰。tsdownのdeps.alwaysBundleが末尾を$固定した正規表現だとサブパスimportを
 * 見逃すことがあり、tscやVitestのNode.js側解決では検出できないため、ビルド出力そのものを
 * 検査する)。
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const bundlePath = fileURLToPath(new URL('../src/dist/admin.js', import.meta.url));
const source = readFileSync(bundlePath, 'utf-8');

// import/export ... from "X" および import("X") の X を抽出する。相対パス(./ ../ /)と
// data:/http(s): スキームだけを許可し、それ以外(パッケージ名の裸のimport)を不正とする。
const specifierPattern = /(?:from|import)\s*\(?\s*["']([^"']+)["']/g;
const bareSpecifiers = [];
let match;
while ((match = specifierPattern.exec(source)) !== null) {
  const specifier = match[1];
  if (!/^(\.|\/|https?:|data:)/.test(specifier)) {
    bareSpecifiers.push(specifier);
  }
}

if (bareSpecifiers.length > 0) {
  console.error('src/dist/admin.js に未バンドルの裸のimportが残っています(ブラウザで壊れます):');
  for (const specifier of [...new Set(bareSpecifiers)]) {
    console.error(`  - ${specifier}`);
  }
  process.exit(1);
}

console.log('src/dist/admin.js: 裸のimportは残っていません(OK)');
