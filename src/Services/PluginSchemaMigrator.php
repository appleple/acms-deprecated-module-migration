<?php

declare(strict_types=1);

namespace Acms\Plugins\DeprecatedModuleMigration\Services;

use Acms\Services\Update\Database\Schema;
use Acms\Services\Update\Database\SchemaDefinitions;

final class PluginSchemaMigrator
{
    public function migrate(string $schemaDirectory): void
    {
        $schema = new Schema(dsn(), SchemaDefinitions::fromYaml($schemaDirectory));
        $missingTables = $schema->compareTables();
        $missingTables = array_values(array_filter($missingTables, 'is_string'));
        if ($missingTables !== []) {
            $schema->createTables($missingTables, $schema->indexDefine);
        }
        foreach (array_keys($schema->define) as $table) {
            // createTables() 直後の $table はカラム・インデックスとも作成済みのため、
            // ここで再度差分比較すると dbIndex 未更新により全インデックスが未追加と
            // 誤判定され、ALTER TABLE ADD が重複して失敗する（本体 Engine::updateColumns/
            // updateIndexs と同じく作成済みテーブルは対象外にする）。
            if (in_array($table, $missingTables, true)) {
                continue;
            }
            $columnDiff = $schema->compareColumns($table);
            if (!is_array($columnDiff) || !isset($columnDiff['add'], $columnDiff['change']) || !is_array($columnDiff['add']) || !is_array($columnDiff['change'])) {
                throw new \RuntimeException('Plugin column schema comparison failed.');
            }
            $schema->resolveColumns($table, $columnDiff['add'], $columnDiff['change']);
            $schema->makeIndex($table, $schema->compareIndex($table));
        }
    }
}
