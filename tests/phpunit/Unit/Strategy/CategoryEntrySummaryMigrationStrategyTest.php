<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\Exceptions\UnsupportedMigrationException;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\CategoryEntrySummaryMigrationStrategy;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\CategoryEntrySummaryMigrationStrategy
 */
#[CoversClass(CategoryEntrySummaryMigrationStrategy::class)]
class CategoryEntrySummaryMigrationStrategyTest extends TestCase
{
    private const TARGET_DEFAULTS = [
        'category_entry_summary_category_order' => 'sort-asc',
        'category_entry_summary_level' => 1,
        'category_entry_summary_order' => 'datetime-desc',
        'category_entry_summary_limit' => 6,
        'category_entry_summary_field_search' => 'entry',
        'category_entry_summary_entry_field_on' => 'off',
        'category_entry_summary_category_field_on' => 'off',
        'category_entry_summary_user_field_on' => 'off',
        'category_entry_summary_blog_field_on' => 'off',
        'category_entry_summary_image_on' => 'on',
    ];

    private CategoryEntrySummaryMigrationStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new CategoryEntrySummaryMigrationStrategy();
    }

    #[Test]
    #[TestDox('supports()はCategory_EntryListのみを返す')]
    public function supportsReturnsCategoryEntryList(): void
    {
        $this->assertSame(['Category_EntryList'], $this->strategy->supports());
    }

    #[Test]
    #[TestDox('rank()はBを返す')]
    public function rankIsB(): void
    {
        $this->assertSame('B', $this->strategy->rank());
    }

    #[Test]
    #[TestDox('limitは旧既定値5と新既定値6が異なるため明示書き込みが必要')]
    public function limitRequiresExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $limitItem = $this->findItem($diff, 'category_entry_summary_limit');
        $this->assertSame(5, $limitItem->sourceEffectiveValue);
        $this->assertTrue($limitItem->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('category_orderはキー名変換のみでデフォルト値が一致するため書き込み不要')]
    public function categoryOrderDoesNotRequireWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertFalse($this->findItem($diff, 'category_entry_summary_category_order')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('各種field_onは常時ON固定(旧)・既定off(新)のため明示onの書き込みが必要')]
    public function fieldOnItemsRequireExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        foreach (
            [
                'category_entry_summary_entry_field_on',
                'category_entry_summary_category_field_on',
                'category_entry_summary_user_field_on',
                'category_entry_summary_blog_field_on',
            ] as $key
        ) {
            $item = $this->findItem($diff, $key);
            $this->assertSame('on', $item->sourceEffectiveValue, "{$key} の実効値");
            $this->assertTrue($item->requiresExplicitWrite(), "{$key} の書き込み要否");
        }
    }

    #[Test]
    #[TestDox('メイン画像出力は旧機能なし(off相当)・新既定onのため明示offの書き込みが必要')]
    public function imageOnRequiresExplicitOff(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $item = $this->findItem($diff, 'category_entry_summary_image_on');
        $this->assertSame('off', $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('entry_active_categoryが無効(off)の場合、再現不可の警告は出ない')]
    public function doesNotBlockWhenActiveCategoryDisabled(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'category_entry_list_entry_active_category' => 'off',
        ]));

        $diff = $this->strategy->diff($module, $configs);

        $this->assertFalse($diff->isBlocked());
        $this->assertSame([], $diff->unsupportedReasons);
    }

    #[Test]
    #[TestDox('entry_active_categoryが有効(on)の場合、再現不可としてisBlocked()がtrueになる')]
    public function blocksWhenActiveCategoryEnabled(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'category_entry_list_entry_active_category' => 'on',
        ]));

        $diff = $this->strategy->diff($module, $configs);

        $this->assertTrue($diff->isBlocked());
        $this->assertNotEmpty($diff->unsupportedReasons);
    }

    #[Test]
    #[TestDox('diff()は常にテンプレート手動書き換えが必須である旨の警告を含む')]
    public function diffAlwaysWarnsAboutTemplateRewrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertNotEmpty($diff->warnings);
    }

    #[Test]
    #[TestDox('未対応キー(category_entry_list_sub_category等)に値がある場合、警告に含める')]
    public function warnsAboutUnmappedKeysWithNonDefaultValues(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'category_entry_list_sub_category' => 'on',
        ]));

        $diff = $this->strategy->diff($module, $configs);

        $joined = implode(' / ', $diff->warnings);
        $this->assertStringContainsString('category_entry_list_sub_category', $joined);
    }

    #[Test]
    #[TestDox('apply()はブロックされた差分(isBlocked()がtrue)に対してUnsupportedMigrationExceptionをスローする')]
    public function applyThrowsWhenDiffIsBlocked(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Category_EntryList', 1, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'category_entry_list_entry_active_category' => 'on',
        ]));
        $diff = $this->strategy->diff($module, $configs);

        $this->expectException(UnsupportedMigrationException::class);
        $this->strategy->apply($module, $configs, $diff);
    }

    private function findItem(
        \Acms\Plugins\DeprecatedModuleMigration\MigrationDiff $diff,
        string $targetConfigKey
    ): \Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem {
        foreach ($diff->items as $item) {
            if ($item->targetConfigKey === $targetConfigKey) {
                return $item;
            }
        }

        $this->fail(sprintf('targetConfigKey "%s" の項目が見つかりませんでした。', $targetConfigKey));
    }
}
