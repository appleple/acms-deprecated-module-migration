<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * module テーブル1行分の移行に必要な情報を表す値オブジェクト。
 *
 * scopeColumns は Entry_Headline のように URL パラメータscopeをコード側で
 * 'global' に固定上書きしているモジュールの移行時、実際の module_*_scope
 * カラムが未設定のままかどうかを判定するために保持する（詳細設計書
 * 「詳細マッピング: Entry_Headline/List/Photo → Entry_Summary」参照）。
 */
final class ModuleRow
{
    /**
     * @param array<string, string|null> $scopeColumns module_*_scope カラムの現在値
     */
    public function __construct(
        public readonly int $moduleId,
        public readonly string $moduleIdentifier,
        public readonly string $moduleName,
        public readonly int $moduleBlogId,
        public readonly string $moduleScope,
        public readonly array $scopeColumns = []
    ) {
    }

    public function isGlobalScope(): bool
    {
        return $this->moduleScope === 'global';
    }
}
