<?php

/**
 * testing-framework の共有bootstrapに、このプラグイン固有のスキーマ導入を上乗せする。
 *
 * 本番では ServiceProvider::install() が PluginSchemaMigrator::migrate() を呼ぶことで
 * module_migration_snapshot テーブルが作られるが、テスト実行時はプラグインの
 * インストールフローを経由しない(acms-create-databaseはコア標準スキーマのみを作る)ため、
 * ここで同じmigrate()を明示的に1回走らせる。migrate()は既存テーブル/カラムがあれば
 * 何もしない冪等な処理なので、何度実行しても安全(PluginSchemaMigratorTest参照)。
 */

require_once __DIR__ . '/../../vendor/ablogcms/testing-framework/bootstrap.php';

(new \Acms\Plugins\DeprecatedModuleMigration\Services\PluginSchemaMigrator())
    ->migrate(__DIR__ . '/../../src/schema');
