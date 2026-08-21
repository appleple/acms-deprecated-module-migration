<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration\Strategy;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\CategoryEntrySummaryMigrationStrategy;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\CategoryEntrySummaryMigrationStrategy
 */
#[CoversClass(CategoryEntrySummaryMigrationStrategy::class)]
final class CategoryEntrySummaryMigrationStrategyTest extends DatabaseTestCase
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
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->strategy = new CategoryEntrySummaryMigrationStrategy(new ModuleMigrationRepository());
        $this->blogId = BlogSeeder::seed(['blog_name' => 'CategoryEntrySummary移行テスト用ブログ']);
    }

    #[Test]
    #[TestDox('apply()はmodule_nameを書き換え、差分のあった項目のみconfig行を書き込む')]
    public function applyMigratesModuleAndWritesOnlyChangedItems(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Category_EntryList']);
        $module = new ModuleRow($moduleId, 'mod_cat_list', 'Category_EntryList', $this->blogId, 'local');
        $configs = ConfigCollection::fromArray(self::TARGET_DEFAULTS);

        $diff = $this->strategy->diff($module, $configs);
        $result = $this->strategy->apply($module, $configs, $diff);

        $this->assertSame('Category_EntrySummary', $result->newModuleName);
        $this->assertArrayHasKey('category_entry_summary_limit', $result->writtenConfig);
        $this->assertArrayHasKey('category_entry_summary_entry_field_on', $result->writtenConfig);
        $this->assertArrayNotHasKey('category_entry_summary_category_order', $result->writtenConfig);

        $sql = SQL::newSelect('module');
        $sql->addSelect('module_name');
        $sql->addWhereOpr('module_id', $moduleId);
        $this->assertSame('Category_EntrySummary', DB::query($sql->get(dsn()), 'one'));
    }

    #[Test]
    #[TestDox('applyForRule()は指定したconfig_rule_idの下にのみconfig行を書き込む')]
    public function applyForRuleWritesUnderGivenRuleIdOnly(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Category_EntryList']);
        $module = new ModuleRow($moduleId, 'mod_cat_list', 'Category_EntryList', $this->blogId, 'local');
        $ruleId = 999;
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'category_entry_list_entry_limit' => 20,
        ]));

        $diff = $this->strategy->diff($module, $configs);
        $this->strategy->applyForRule($module, $configs, $diff, $ruleId);

        $ruleScopedRow = SQL::newSelect('config');
        $ruleScopedRow->addSelect('config_value');
        $ruleScopedRow->addWhereOpr('config_key', 'category_entry_summary_limit');
        $ruleScopedRow->addWhereOpr('config_module_id', $moduleId);
        $ruleScopedRow->addWhereOpr('config_blog_id', $this->blogId);
        $ruleScopedRow->addWhereOpr('config_rule_id', $ruleId);
        $this->assertSame('20', DB::query($ruleScopedRow->get(dsn()), 'one'));

        $noRuleRow = SQL::newSelect('config');
        $noRuleRow->addSelect('config_key');
        $noRuleRow->addWhereOpr('config_key', 'category_entry_summary_limit');
        $noRuleRow->addWhereOpr('config_module_id', $moduleId);
        $noRuleRow->addWhereOpr('config_blog_id', $this->blogId);
        $noRuleRow->addWhereOpr('config_rule_id', null);
        $this->assertFalse(DB::query($noRuleRow->get(dsn()), 'one'));
    }

    #[Test]
    #[TestDox('applyForRule()はルール別diffがisBlocked()の場合、何も書き込まない')]
    public function applyForRuleSkipsWritingWhenRuleDiffIsBlocked(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Category_EntryList']);
        $module = new ModuleRow($moduleId, 'mod_cat_list', 'Category_EntryList', $this->blogId, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'category_entry_list_entry_active_category' => 'on',
        ]));
        $blockedDiff = $this->strategy->diff($module, $configs);
        $this->assertTrue($blockedDiff->isBlocked(), '前提: active_categoryが有効な場合diffはisBlocked()になるはず');

        $this->strategy->applyForRule($module, $configs, $blockedDiff, 999);

        $sql = SQL::newSelect('config');
        $sql->addSelect('config_key');
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $this->blogId);
        $sql->addWhereOpr('config_rule_id', 999);
        $this->assertSame([], DB::query($sql->get(dsn()), 'all'));
    }
}
