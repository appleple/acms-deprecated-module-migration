import { defineConfig } from 'tsdown';

/**
 * 管理画面へ `<script type="module">` で直接読み込ませる単一バンドル(src/dist/admin.js)を作る。
 * npm パッケージとしての公開は想定しないため dts は出力しない。
 *
 * react / @ablogcms/* はブラウザ側に node_modules が存在しない前提のため、既定の
 * external化(package.jsonのdependenciesを外部化するtsup/tsdown共通の挙動)を上書きし、
 * deps.alwaysBundleで明示的にバンドルへ含める(transitiveな依存も連鎖してバンドルされる)。
 */
export default defineConfig((options) => ({
  entry: { admin: 'assets/src/index.ts' },
  outDir: 'src/dist',
  format: 'esm',
  platform: 'browser',
  outputOptions: {
    // package.json の "type": "module" により拡張子は既定で .js になるはずだが、
    // テンプレート側(src/template/admin/main.html)が admin.js を直接参照しているため明示する。
    entryFileNames: '[name].js',
  },
  deps: {
    alwaysBundle: [/^@ablogcms\//, 'react', 'react-dom'],
    // alwaysBundle対象のtransitive依存(classnames, focus-trap等)も連鎖して自動でバンドルされる
    // (この単一バンドルはnode_modulesの無いブラウザで読まれるため、それが望む挙動)。
    // onlyBundleは「バンドルを許可するパッケージ」を明示列挙する厳格モードで、意図せぬ肥大化を
    // 検知するnpmライブラリ向けの機能のため、ここでは無効化し警告ヒントを消す。
    onlyBundle: false,
  },
  dts: false,
  clean: !options.watch,
  minify: !options.watch,
  sourcemap: Boolean(options.watch),
}));
