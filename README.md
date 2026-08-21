# DeprecatedModuleMigration for a-blog cms

非推奨モジュールから代替モジュールへの設定移行を支援する **a-blog cms** の拡張アプリです。
`acms-plugin-skeleton` をベースに、`ablogcms/testing-framework` によるテスト環境と CI/CD 一式を備えています。

## できること

対象ブログの `module` テーブルを走査し、以下の非推奨モジュールを検出して代替モジュールへの設定移行を支援します。

| 非推奨モジュール | 代替モジュール | 移行方式 |
|---|---|---|
| `Plugin_Schedule` | `Schedule` | 設定値をそのまま1:1移行(A) |
| `Entry_Headline` / `Entry_List` / `Entry_Photo` | `Entry_Summary` | 設定値を対応キーへ変換(B) |
| `Category_EntryList` | `Category_EntrySummary` | 設定値を対応キーへ変換(B) |
| `Banner` | `Media_Banner` | バナー画像をメディアライブラリへ移設(C・オプトイン) |
| `User_Profile` | `User_Search` | 自動移行不可。差分確認と手動対応の助言のみ(C) |

管理画面（サイドバー「DeprecatedModuleMigration」）から、ブログ単位で

1. **検出** — 対象モジュールの一覧を表示
2. **差分確認** — 移行後の設定値をプレビュー(テンプレート側の書き換えは対象外。旧モジュール名の設置箇所はエディタの全文検索等で確認してください)
3. **適用** — 移行前の `module` / `config` 行をスナップショットとして保存した上で書き換え
4. **ロールバック** — スナップショットから適用前の状態に復元

という流れで進められます。React 製の管理画面 UI は `@ablogcms/*`（[npmjs.com/org/ablogcms](https://www.npmjs.com/org/ablogcms)）の公開パッケージを利用しています。

ルール（URLパターン別の設定上書き）で個別に上書きされている設定がある場合、差分確認画面にその件数が警告として表示されます。適用時は、ルール無し(既定)の設定と同じ変換ルールで、各ルールの設定もそれぞれのルールIDのまま移行されます（自動移行不可と判定されたルールがあれば、その分だけ適用結果に手動確認が必要な旨を表示します）。

## 動作環境

- a-blog cms: Ver. 3.2.27 以降 (3.3+ not tested yet)
- PHP: 8.1 – 8.5 (8.6+ not tested yet)

## ダウンロード

[DeprecatedModuleMigration for a-blog cms](https://github.com/appleple/acms-deprecated-module-migration/releases/latest/download/DeprecatedModuleMigration.zip)

利用するためには最新リリースから zip をダウンロード後、解凍して **extension/plugins** に設置してください。

- extension/plugins/DeprecatedModuleMigration

## インストール

管理ページ > 拡張アプリより「拡張アプリ管理」のページに移動し、DeprecatedModuleMigration をインストール・有効化してください。サイドバーに移行機能へのリンクが追加されます。

## 構成

```
.
├── src/                                  # プラグイン本体(extension/plugins/DeprecatedModuleMigration/ の実体)
│   ├── ServiceProvider.php               # プラグイン登録(サイドバー・管理画面テンプレートの差し込み、スキーマ導入)
│   ├── POST/                             # 検出・差分確認・適用・ロールバックの操作ハンドラ
│   ├── Strategy/                         # モジュールごとの移行戦略(A/B/C)
│   ├── Snapshot/                         # 適用前スナップショットの保存・復元
│   ├── Services/PluginSchemaMigrator.php # プラグイン専用テーブルのスキーマ導入
│   ├── schema/                           # module_migration_snapshot テーブル定義
│   ├── template/admin/                   # 管理画面テンプレート(React マウント先を含む)
│   └── dist/                             # フロントエンドのビルド出力(npm run build の生成物、git管理外)
├── assets/src/                           # フロントエンド(TypeScript + React)のソース
├── tests/phpunit/                        # Unit(DB不使用) / Integration(DB, 自動ロールバック)
├── scripts/*.php                         # パッケージング・バージョニング(Composer scripts経由)
└── .github/workflows/                    # CI(test.yml)・リリース(release.yml)
```

`src/` がプラグインのルートです。core のオートローダーは `Acms\Plugins\ → extension/plugins/` にマッピングするため、`Acms\Plugins\DeprecatedModuleMigration\ServiceProvider` は `src/ServiceProvider.php` に解決されます。

## テスト

```bash
composer install
npm install
vendor/bin/phpunit
```

- `Services` / `Strategy` 配下の純粋なロジックは Unit テスト（DBなし、`Acms\TestingFramework\TestCase`）
- スナップショット保存・復元やDB連携を伴うものは Integration テスト（実DB、トランザクション自動ロールバック、`Acms\TestingFramework\DatabaseTestCase`）

`ACMS_ROOT` を含む接続情報は `phpunit.xml`（git管理外のローカル上書き）で設定してください。テストデータは `Acms\TestingFramework\Seeder\*` を使います。

管理画面UI(TypeScript/React)側は Vitest + Testing Library でテストします。ソースと同階層に `*.test.{ts,tsx}` を置きます。

```bash
npm install
npm test          # Vitest(1回実行)
npm run test:watch # Vitest(watchモード)
```

## 品質チェック

```bash
composer lint      # PHP_CodeSniffer(PSR-12 + PHPCompatibility)
composer analyse   # PHPStan(level max)
composer test      # PHPUnit
composer check     # 上記3つをまとめて実行

npm run tsc        # TypeScript 型チェック
npm test           # Vitest
npm run build      # フロントエンドの本番ビルド(src/dist/admin.js を生成)
```

PHPStan は `ablogcms/testing-framework` が提供するスタブで a-blog cms コアのシンボルを解決します。`scanDirectories` はコアの実体パスに合わせて `phpstan.neon`（git管理外のローカル上書き）で調整してください。`DB::query()` の戻り値型がスタブでは呼び出しモード別に絞り込まれない(常に `mixed` になる)ことに起因する既知のエラー群は `phpstan-baseline.neon` に退避しています(a-blog cms本体の `composer analyse:baseline` と同じ方針)。

## リリース

バージョンは `src/ServiceProvider.php` の `$version` で管理します（`composer.json` には持たせません）。

```bash
composer package             # build/DeprecatedModuleMigration.zip を生成(git管理外)
composer release:patch       # バージョンを上げてコミット・タグ付け
git push --follow-tags       # → release.yml が zip をビルドしGitHub Releaseへ公開
```

## ライセンス

MIT License（[`LICENSE`](LICENSE) を参照）。
