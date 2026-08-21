<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * MigrationStrategyInterface::apply() の戻り値。1モジュールぶんの適用結果を表す。
 */
final class MigrationResult
{
    /**
     * @param array<string, mixed> $writtenConfig 新規に書き込んだ config_key => value
     * @param string[] $notes 適用結果画面に表示する注記(テンプレート対応要否等)
     */
    public function __construct(
        public readonly int $moduleId,
        public readonly string $oldModuleName,
        public readonly string $newModuleName,
        public readonly array $writtenConfig = [],
        public readonly array $notes = []
    ) {
    }
}
