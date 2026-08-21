<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * ModuleMigration の値オブジェクトを、管理画面React UIへ返すJSON化可能な配列へ変換する。
 *
 * ACMS_POST_ModuleMigration_* ハンドラから直接配列を組み立てるのではなくここへ切り出すことで、
 * ハンドラ本体はCSRF検証・権限チェック・Facade呼び出しの薄いグルーコードのままにし、
 * 変換ロジックだけをUnitテスト可能にする(development-guidelines.md
 * 「テストする対象: Helper/純粋関数」に準拠)。
 */
final class ModuleMigrationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function presentModule(ModuleRow $module, ?MigrationStrategyInterface $strategy): array
    {
        return [
            'moduleId' => $module->moduleId,
            'moduleIdentifier' => $module->moduleIdentifier,
            'moduleName' => $module->moduleName,
            'moduleBlogId' => $module->moduleBlogId,
            'moduleScope' => $module->moduleScope,
            'targetModuleName' => $strategy?->targetModuleName($module->moduleName),
            'rank' => $strategy?->rank(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDiff(MigrationDiff $diff): array
    {
        return [
            'sourceModuleName' => $diff->sourceModuleName,
            'targetModuleName' => $diff->targetModuleName,
            'items' => array_map(
                fn (MigrationDiffItem $item): array => $this->presentItem($item),
                $diff->items
            ),
            'warnings' => $diff->warnings,
            'unsupportedReasons' => $diff->unsupportedReasons,
            'isBlocked' => $diff->isBlocked(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentItem(MigrationDiffItem $item): array
    {
        return [
            'targetConfigKey' => $item->targetConfigKey,
            'sourceConfigKey' => $item->sourceConfigKey,
            'sourceEffectiveValue' => $item->sourceEffectiveValue,
            'targetDefaultValue' => $item->targetDefaultValue,
            'willChangeIfUnset' => $item->willChangeIfUnset(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentResult(MigrationResult $result): array
    {
        return [
            'moduleId' => $result->moduleId,
            'oldModuleName' => $result->oldModuleName,
            'newModuleName' => $result->newModuleName,
            'writtenConfig' => $result->writtenConfig,
            'notes' => $result->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentTemplateReference(TemplateReference $reference): array
    {
        return [
            'filePath' => $reference->filePath,
            'lineNumber' => $reference->lineNumber,
            'matchedLine' => $reference->matchedLine,
        ];
    }
}
