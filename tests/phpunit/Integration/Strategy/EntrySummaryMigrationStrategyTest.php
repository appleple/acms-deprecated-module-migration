<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration\Strategy;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\EntrySummaryMigrationStrategy;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\EntrySummaryMigrationStrategy
 */
#[CoversClass(EntrySummaryMigrationStrategy::class)]
final class EntrySummaryMigrationStrategyTest extends DatabaseTestCase
{
    private const TARGET_DEFAULTS = [
        'entry_summary_order' => 'datetime-desc',
        'entry_summary_limit' => 6,
        'entry_summary_pager_on' => 'on',
        'entry_summary_simple_pager_on' => 'off',
        'mo_entry_summary_notfound' => 'on',
        'entry_summary_image_on' => 'on',
        'entry_summary_category_on' => 'on',
        'entry_summary_blog_on' => 'off',
        'entry_summary_user_on' => 'off',
        'entry_summary_fulltext' => 'on',
        'entry_summary_related_entry_on' => 'off',
        'entry_summary_tag' => 'on',
    ];

    private EntrySummaryMigrationStrategy $strategy;
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->strategy = new EntrySummaryMigrationStrategy(new ModuleMigrationRepository());
        $this->blogId = BlogSeeder::seed(['blog_name' => 'EntrySummary移行テスト用ブログ']);
    }

    #[Test]
    #[TestDox('apply()はEntry_Headlineをリネームし、差分のあった項目のみconfig行を書き込み、scopeカラムをglobalへ強制する')]
    public function applyMigratesHeadlineAndForcesGlobalScope(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, [
            'module_name' => 'Entry_Headline',
            'module_cid_scope' => 'local',
        ]);
        $module = new ModuleRow($moduleId, 'mod_headline', 'Entry_Headline', $this->blogId, 'local', [
            'module_cid_scope' => 'local',
        ]);
        $configs = ConfigCollection::fromArray(self::TARGET_DEFAULTS);

        $diff = $this->strategy->diff($module, $configs);
        $result = $this->strategy->apply($module, $configs, $diff);

        $this->assertSame('Entry_Summary', $result->newModuleName);
        $this->assertArrayHasKey('entry_summary_fulltext', $result->writtenConfig);
        $this->assertSame('off', $result->writtenConfig['entry_summary_fulltext']);
        $this->assertArrayNotHasKey('entry_summary_category_on', $result->writtenConfig);
        $this->assertNotEmpty($result->notes);

        $moduleRow = SQL::newSelect('module');
        $moduleRow->addSelect('module_name');
        $moduleRow->addSelect('module_cid_scope');
        $moduleRow->addWhereOpr('module_id', $moduleId);
        $row = DB::query($moduleRow->get(dsn()), 'row');

        $this->assertSame('Entry_Summary', $row['module_name']);
        $this->assertSame('global', $row['module_cid_scope']);

        $configRow = SQL::newSelect('config');
        $configRow->addSelect('config_value');
        $configRow->addWhereOpr('config_key', 'entry_summary_fulltext');
        $configRow->addWhereOpr('config_module_id', $moduleId);
        $configRow->addWhereOpr('config_blog_id', $this->blogId);
        $this->assertSame('off', DB::query($configRow->get(dsn()), 'one'));
    }

    #[Test]
    #[TestDox('applyForRule()は指定したconfig_rule_idの下にのみconfig行を書き込み、ルール無し(NULL)の行には影響しない')]
    public function applyForRuleWritesUnderGivenRuleIdOnly(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);
        $module = new ModuleRow($moduleId, 'mod_headline', 'Entry_Headline', $this->blogId, 'local');
        $ruleId = 999;
        // このルールだけ limit を20に上書きしている想定(entry_headline_limitのconfig行がrule_id=999で存在)。
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'entry_headline_limit' => 20,
        ]));

        $diff = $this->strategy->diff($module, $configs);
        $this->strategy->applyForRule($module, $configs, $diff, $ruleId);

        $ruleScopedRow = SQL::newSelect('config');
        $ruleScopedRow->addSelect('config_value');
        $ruleScopedRow->addWhereOpr('config_key', 'entry_summary_limit');
        $ruleScopedRow->addWhereOpr('config_module_id', $moduleId);
        $ruleScopedRow->addWhereOpr('config_blog_id', $this->blogId);
        $ruleScopedRow->addWhereOpr('config_rule_id', $ruleId);
        $this->assertSame('20', DB::query($ruleScopedRow->get(dsn()), 'one'));

        $noRuleRow = SQL::newSelect('config');
        $noRuleRow->addSelect('config_key');
        $noRuleRow->addWhereOpr('config_key', 'entry_summary_limit');
        $noRuleRow->addWhereOpr('config_module_id', $moduleId);
        $noRuleRow->addWhereOpr('config_blog_id', $this->blogId);
        $noRuleRow->addWhereOpr('config_rule_id', null);
        $this->assertFalse(
            DB::query($noRuleRow->get(dsn()), 'one'),
            'ルール無し(NULL)のconfig行は書き込まれていないはず'
        );
    }

    #[Test]
    #[TestDox('applyForRule()はルール別diffがisBlocked()の場合、何も書き込まない')]
    public function applyForRuleSkipsWritingWhenRuleDiffIsBlocked(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);
        $module = new ModuleRow($moduleId, 'mod_headline', 'Entry_Headline', $this->blogId, 'local');
        $configs = ConfigCollection::fromArray(self::TARGET_DEFAULTS);
        $blockedDiff = new \Acms\Plugins\DeprecatedModuleMigration\MigrationDiff(
            'Entry_Headline',
            'Entry_Summary',
            $this->strategy->diff($module, $configs)->items,
            unsupportedReasons: ['テスト用の強制ブロック']
        );

        $this->strategy->applyForRule($module, $configs, $blockedDiff, 999);

        $sql = SQL::newSelect('config');
        $sql->addSelect('config_key');
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $this->blogId);
        $sql->addWhereOpr('config_rule_id', 999);
        $this->assertSame([], DB::query($sql->get(dsn()), 'all'));
    }
}
