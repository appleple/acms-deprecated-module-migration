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
}
