<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration\Strategy;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\ScheduleMigrationStrategy;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\ScheduleMigrationStrategy
 */
#[CoversClass(ScheduleMigrationStrategy::class)]
final class ScheduleMigrationStrategyTest extends DatabaseTestCase
{
    private ScheduleMigrationStrategy $strategy;
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->strategy = new ScheduleMigrationStrategy(new ModuleMigrationRepository());
        $this->blogId = BlogSeeder::seed(['blog_name' => 'Scheduleテスト用ブログ']);
    }

    #[Test]
    #[TestDox('apply()はmodule_nameをScheduleへ書き換え、新規config行は書き込まない')]
    public function applyRenamesModuleWithoutWritingConfig(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = new ModuleRow($moduleId, 'mod_schedule', 'Plugin_Schedule', $this->blogId, 'local');
        $configs = ConfigCollection::fromArray(['schedule_unit' => '2']);

        $diff = $this->strategy->diff($module, $configs);
        $result = $this->strategy->apply($module, $configs, $diff);

        $this->assertSame($moduleId, $result->moduleId);
        $this->assertSame('Plugin_Schedule', $result->oldModuleName);
        $this->assertSame('Schedule', $result->newModuleName);
        $this->assertSame([], $result->writtenConfig);

        $sql = SQL::newSelect('module');
        $sql->addSelect('module_name');
        $sql->addWhereOpr('module_id', $moduleId);
        $this->assertSame('Schedule', DB::query($sql->get(dsn()), 'one'));
    }

    #[Test]
    #[TestDox('applyForRule()は何もconfig行を書き込まない(configキー名が完全一致するため移行不要)')]
    public function applyForRuleWritesNothing(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = new ModuleRow($moduleId, 'mod_schedule', 'Plugin_Schedule', $this->blogId, 'local');
        $configs = ConfigCollection::fromArray(['schedule_unit' => '9']);
        $diff = $this->strategy->diff($module, $configs);

        $this->strategy->applyForRule($module, $configs, $diff, 999);

        $sql = SQL::newSelect('config');
        $sql->addSelect('config_key');
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $this->blogId);
        $sql->addWhereOpr('config_rule_id', 999);
        $this->assertSame([], DB::query($sql->get(dsn()), 'all'));
    }
}
