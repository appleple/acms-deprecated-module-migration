<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\Exceptions\UnsupportedMigrationException;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiff;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem;
use Acms\Plugins\DeprecatedModuleMigration\MigrationResult;
use Acms\Plugins\DeprecatedModuleMigration\MigrationStrategyInterface;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;

/**
 * Category_EntryList → Category_EntrySummary (ランクB)。
 *
 * 継承関係にありカテゴリー階層走査ロジックは共通。表示件数デフォルト差、
 * Summary側の各種field_on(常時ON固定 vs 既定OFF)という非対称性がある。
 * category_entry_list_entry_active_category はSummary側に対応キーが無く再現不可能なため、
 * 有効時は自動適用をブロックする(detailed-design.html
 * 「詳細マッピング: Category_EntryList → Category_EntrySummary」参照)。
 */
final class CategoryEntrySummaryMigrationStrategy implements MigrationStrategyInterface
{
    private const SOURCE_MODULE_NAME = 'Category_EntryList';
    private const TARGET_MODULE_NAME = 'Category_EntrySummary';

    private const ACTIVE_CATEGORY_SOURCE_KEY = 'category_entry_list_entry_active_category';

    /**
     * @var list<array{target: string, sourceKey?: string, sourceDefault?: mixed, sourceHardcoded?: mixed}>
     */
    private const FIELD_MAP = [
        ['target' => 'category_entry_summary_category_order', 'sourceKey' => 'category_entry_list_category_order', 'sourceDefault' => 'sort-asc'],
        ['target' => 'category_entry_summary_level', 'sourceKey' => 'category_entry_list_level', 'sourceDefault' => 1],
        ['target' => 'category_entry_summary_order', 'sourceKey' => 'category_entry_list_entry_order', 'sourceDefault' => 'datetime-desc'],
        ['target' => 'category_entry_summary_limit', 'sourceKey' => 'category_entry_list_entry_limit', 'sourceDefault' => 5],
        ['target' => 'category_entry_summary_field_search', 'sourceKey' => 'category_entry_list_field_search', 'sourceDefault' => 'entry'],
        ['target' => 'category_entry_summary_entry_field_on', 'sourceHardcoded' => 'on'],
        ['target' => 'category_entry_summary_category_field_on', 'sourceHardcoded' => 'on'],
        ['target' => 'category_entry_summary_user_field_on', 'sourceHardcoded' => 'on'],
        ['target' => 'category_entry_summary_blog_field_on', 'sourceHardcoded' => 'on'],
        ['target' => 'category_entry_summary_image_on', 'sourceHardcoded' => 'off'],
    ];

    /**
     * FIELD_MAPでは移行しきれない設定可能キー(旧ACMS_GET_Category_EntryList::initVars()を
     * 実コードで確認して列挙)。値が既定(未設定)でない場合は自動移行の対象外として警告する。
     *
     * @var string[]
     */
    private const UNMAPPED_KNOWN_KEYS = [
        'category_entry_list_category_indexing',
        'category_entry_list_entry_amount_zero',
        'category_entry_list_sub_category',
        'category_entry_list_entry_indexing',
        'category_entry_list_category_loop_class',
        'category_entry_list_entry_loop_class',
    ];

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
        return 'B';
    }

    public function diff(ModuleRow $module, ConfigCollection $configs): MigrationDiff
    {
        $this->assertSupported($module);

        $items = [];
        foreach (self::FIELD_MAP as $field) {
            $sourceValue = $this->resolveSourceValue($field, $configs);
            $targetDefault = $configs->get($field['target']);
            $items[] = new MigrationDiffItem($field['target'], $sourceValue, $targetDefault, $field['sourceKey'] ?? null);
        }

        $unsupportedReasons = [];
        if ($this->isTruthy($configs->get(self::ACTIVE_CATEGORY_SOURCE_KEY, 'off'))) {
            $unsupportedReasons[] = sprintf(
                '%s はCategory_EntrySummaryに対応するキーが無いため自動移行できません。'
                    . '手動での対応方針確認が必要です。',
                self::ACTIVE_CATEGORY_SOURCE_KEY
            );
        }

        $warnings = [
            '設定移行は完了しますが、テンプレートの変数体系が異なるため手動書き換えが必須です'
                . '(entryUrl/entryTitle等 → url/title等、unit:loopでのラップが必要)。',
        ];
        $unmapped = $this->unmappedKeyWarning($configs);
        if ($unmapped !== null) {
            $warnings[] = $unmapped;
        }

        return new MigrationDiff(
            sourceModuleName: $module->moduleName,
            targetModuleName: self::TARGET_MODULE_NAME,
            items: $items,
            warnings: $warnings,
            unsupportedReasons: $unsupportedReasons
        );
    }

    public function apply(ModuleRow $module, ConfigCollection $configs, MigrationDiff $approvedDiff): MigrationResult
    {
        $this->assertSupported($module);

        if ($approvedDiff->isBlocked()) {
            throw new UnsupportedMigrationException(
                'category_entry_list_entry_active_category が有効なモジュールは自動移行できません。'
            );
        }

        $this->repository->renameModule($module->moduleId, $module->moduleBlogId, self::TARGET_MODULE_NAME);

        $written = [];
        foreach ($approvedDiff->itemsRequiringExplicitWrite() as $item) {
            $value = $this->toStorableValue($item->sourceEffectiveValue);
            $this->repository->upsertModuleConfig(
                $module->moduleBlogId,
                null,
                $module->moduleId,
                $item->targetConfigKey,
                $value
            );
            $written[$item->targetConfigKey] = $value;
        }

        $this->repository->forgetModuleConfigCache($module->moduleBlogId, null, $module->moduleId);

        return new MigrationResult(
            moduleId: $module->moduleId,
            oldModuleName: $module->moduleName,
            newModuleName: self::TARGET_MODULE_NAME,
            writtenConfig: $written,
            notes: ['テンプレートの手動書き換えが必須です。']
        );
    }

    /**
     * @param array{target: string, sourceKey?: string, sourceDefault?: mixed, sourceHardcoded?: mixed} $field
     */
    private function resolveSourceValue(array $field, ConfigCollection $configs): mixed
    {
        if (array_key_exists('sourceKey', $field)) {
            return $configs->get($field['sourceKey'], $field['sourceDefault'] ?? null);
        }

        return $field['sourceHardcoded'] ?? null;
    }

    private function unmappedKeyWarning(ConfigCollection $configs): ?string
    {
        $nonDefaultKeys = array_values(array_filter(
            self::UNMAPPED_KNOWN_KEYS,
            static fn (string $key): bool => $configs->differsFromDefault($key)
        ));

        if ($nonDefaultKeys === []) {
            return null;
        }

        return sprintf(
            '以下の設定キーは自動移行の対象外です。値が設定されているため、Category_EntrySummary側で手動確認してください: %s',
            implode(', ', $nonDefaultKeys)
        );
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === 'on' || $value === '1' || $value === 1;
    }

    private function toStorableValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        return (string) $value;
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
