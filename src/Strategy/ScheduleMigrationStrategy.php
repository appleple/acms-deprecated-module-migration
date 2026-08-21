<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiff;
use Acms\Plugins\DeprecatedModuleMigration\MigrationResult;
use Acms\Plugins\DeprecatedModuleMigration\MigrationStrategyInterface;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;

/**
 * Plugin_Schedule → Schedule (ランクA)。
 *
 * Plugin_ScheduleはScheduleを継承するだけの薄いラッパーで、configキー・デフォルト値まで
 * 完全一致するため、module_nameの書き換え以外の変換は不要（detailed-design.html
 * 「詳細マッピング: Plugin_Schedule → Schedule」参照）。
 */
final class ScheduleMigrationStrategy implements MigrationStrategyInterface
{
    private const SOURCE_MODULE_NAME = 'Plugin_Schedule';
    private const TARGET_MODULE_NAME = 'Schedule';

    public function __construct(private readonly ModuleMigrationRepository $repository = new ModuleMigrationRepository())
    {
    }

    public function supports(): array
    {
        return [self::SOURCE_MODULE_NAME];
    }

    public function targetModuleName(string $sourceModuleName): string
    {
        return self::TARGET_MODULE_NAME;
    }

    public function rank(): string
    {
        return 'A';
    }

    public function diff(ModuleRow $module, ConfigCollection $configs): MigrationDiff
    {
        $this->assertSupported($module);

        return new MigrationDiff(
            sourceModuleName: $module->moduleName,
            targetModuleName: self::TARGET_MODULE_NAME,
            items: []
        );
    }

    public function apply(ModuleRow $module, ConfigCollection $configs, MigrationDiff $approvedDiff): MigrationResult
    {
        $this->assertSupported($module);

        $this->repository->renameModule($module->moduleId, $module->moduleBlogId, self::TARGET_MODULE_NAME);
        $this->repository->forgetModuleConfigCache($module->moduleBlogId, null, $module->moduleId);

        return new MigrationResult(
            moduleId: $module->moduleId,
            oldModuleName: $module->moduleName,
            newModuleName: self::TARGET_MODULE_NAME
        );
    }

    public function applyForRule(ModuleRow $module, ConfigCollection $configs, MigrationDiff $ruleDiff, int $ruleId): void
    {
        // Plugin_Schedule/Scheduleはconfigキー名が完全一致するためconfig行の書き込みが
        // 発生しない(diff()が常に空items)。既存のルール別上書き行はconfig_key名が
        // 変わらないため、apply()のリネームだけで新モジュール側でも引き続き有効になる。
    }

    private function assertSupported(ModuleRow $module): void
    {
        if (!in_array($module->moduleName, $this->supports(), true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s は %s がサポートしていない module_name です。',
                $module->moduleName,
                self::class
            ));
        }
    }
}
