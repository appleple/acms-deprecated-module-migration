<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * MigrationStrategyInterface::diff() の戻り値。1モジュールぶんの差分プレビューを表す。
 */
final class MigrationDiff
{
    /**
     * @param MigrationDiffItem[] $items
     * @param string[] $warnings 適用前に制作者へ提示すべき注意事項(テンプレート手動書き換え等)
     * @param string[] $unsupportedReasons 1件でもあれば isBlocked() が true になり、自動適用の対象外を示す
     */
    public function __construct(
        public readonly string $sourceModuleName,
        public readonly string $targetModuleName,
        public readonly array $items,
        public readonly array $warnings = [],
        public readonly array $unsupportedReasons = []
    ) {
    }

    /**
     * @return MigrationDiffItem[]
     */
    public function itemsRequiringExplicitWrite(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (MigrationDiffItem $item): bool => $item->requiresExplicitWrite()
        ));
    }

    public function isBlocked(): bool
    {
        return $this->unsupportedReasons !== [];
    }
}
