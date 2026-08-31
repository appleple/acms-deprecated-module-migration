<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration\Snapshot;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Snapshot\ModuleSnapshotRepository;
use Acms\Plugins\DeprecatedModuleMigration\Snapshot\SnapshotSummary;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ConfigSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Snapshot\ModuleSnapshotRepository
 */
#[CoversClass(ModuleSnapshotRepository::class)]
final class ModuleSnapshotRepositoryTest extends DatabaseTestCase
{
    private ModuleSnapshotRepository $repository;
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->repository = new ModuleSnapshotRepository();
        $this->blogId = BlogSeeder::seed(['blog_name' => 'スナップショットテスト用ブログ']);
    }

    #[Test]
    #[TestDox('save()は適用前のmodule行・config行をスナップショットとして保存し、snapshot_idを返す')]
    public function saveCreatesSnapshotRow(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        ConfigSeeder::seed($this->blogId, 'schedule_unit', '2', ['config_module_id' => $moduleId]);
        $module = new ModuleRow($moduleId, 'mod_schedule', 'Plugin_Schedule', $this->blogId, 'local');

        $snapshotId = $this->repository->save($module, userId: 1);

        $this->assertGreaterThan(0, $snapshotId);

        $sql = SQL::newSelect('module_migration_snapshot');
        $sql->addSelect('snapshot_module_id');
        $sql->addSelect('snapshot_blog_id');
        $sql->addWhereOpr('snapshot_id', $snapshotId);
        $row = DB::query($sql->get(dsn()), 'row');

        $this->assertSame($moduleId, (int) $row['snapshot_module_id']);
        $this->assertSame($this->blogId, (int) $row['snapshot_blog_id']);
    }

    #[Test]
    #[TestDox('save()の後にrestore()すると、module_nameとconfig値が保存時点の状態に戻る')]
    public function restoreRevertsModuleAndConfigToSnapshotState(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        ConfigSeeder::seed($this->blogId, 'schedule_unit', '2', ['config_module_id' => $moduleId]);
        $module = new ModuleRow($moduleId, 'mod_schedule', 'Plugin_Schedule', $this->blogId, 'local');

        $snapshotId = $this->repository->save($module, userId: 1);

        // 移行を模擬してmodule_name・config値を書き換える
        $renameSql = SQL::newUpdate('module');
        $renameSql->addUpdate('module_name', 'Schedule');
        $renameSql->addWhereOpr('module_id', $moduleId);
        DB::query($renameSql->get(dsn()), 'exec');

        $updateConfigSql = SQL::newUpdate('config');
        $updateConfigSql->addUpdate('config_value', '9');
        $updateConfigSql->addWhereOpr('config_key', 'schedule_unit');
        $updateConfigSql->addWhereOpr('config_module_id', $moduleId);
        DB::query($updateConfigSql->get(dsn()), 'exec');

        $identifiers = $this->repository->restore($snapshotId);

        $this->assertSame($moduleId, $identifiers['moduleId']);
        $this->assertSame($this->blogId, $identifiers['blogId']);

        $moduleSql = SQL::newSelect('module');
        $moduleSql->addSelect('module_name');
        $moduleSql->addWhereOpr('module_id', $moduleId);
        $this->assertSame('Plugin_Schedule', DB::query($moduleSql->get(dsn()), 'one'));

        $configSql = SQL::newSelect('config');
        $configSql->addSelect('config_value');
        $configSql->addWhereOpr('config_key', 'schedule_unit');
        $configSql->addWhereOpr('config_module_id', $moduleId);
        $this->assertSame('2', DB::query($configSql->get(dsn()), 'one'));
    }

    #[Test]
    #[TestDox('restore()にexpectedBlogIdを渡し、スナップショットの所有ブログと一致しない場合は例外をスローし何も変更しない(IDOR対策)')]
    public function restoreThrowsWhenExpectedBlogIdDoesNotMatch(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = new ModuleRow($moduleId, 'mod_schedule', 'Plugin_Schedule', $this->blogId, 'local');
        $snapshotId = $this->repository->save($module, userId: 1);

        $renameSql = SQL::newUpdate('module');
        $renameSql->addUpdate('module_name', 'Schedule');
        $renameSql->addWhereOpr('module_id', $moduleId);
        DB::query($renameSql->get(dsn()), 'exec');

        $otherBlogId = $this->blogId + 1;
        $this->expectException(\RuntimeException::class);
        try {
            $this->repository->restore($snapshotId, $otherBlogId);
        } finally {
            $moduleSql = SQL::newSelect('module');
            $moduleSql->addSelect('module_name');
            $moduleSql->addWhereOpr('module_id', $moduleId);
            $this->assertSame(
                'Schedule',
                DB::query($moduleSql->get(dsn()), 'one'),
                '所有ブログ不一致時はrestoreが実行されず、直前の状態のままであること'
            );
        }
    }

    #[Test]
    #[TestDox('restore()は移行後に新規追加されたconfig行を削除する(スナップショット時点に存在しなかった行)')]
    public function restoreRemovesConfigRowsAddedAfterSnapshot(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);
        $module = new ModuleRow($moduleId, 'mod_headline', 'Entry_Headline', $this->blogId, 'local');

        $snapshotId = $this->repository->save($module, userId: 1);

        // 移行によって新規config行が追加されたことを模擬する
        ConfigSeeder::seed($this->blogId, 'entry_summary_fulltext', 'off', ['config_module_id' => $moduleId]);

        $this->repository->restore($snapshotId);

        $configSql = SQL::newSelect('config');
        $configSql->addWhereOpr('config_key', 'entry_summary_fulltext');
        $configSql->addWhereOpr('config_module_id', $moduleId);
        $rows = DB::query($configSql->get(dsn()), 'all');

        $this->assertSame([], $rows);
    }

    #[Test]
    #[TestDox('findAllByBlogId()は指定ブログのスナップショットを新しい順に返し、moduleNameはスナップショット時点の値を返す')]
    public function findAllByBlogIdReturnsSnapshotsForBlogInDescendingOrder(): void
    {
        $moduleId1 = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module1 = new ModuleRow($moduleId1, 'mod_schedule', 'Plugin_Schedule', $this->blogId, 'local');
        $snapshotId1 = $this->repository->save($module1, userId: 1);

        $moduleId2 = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);
        $module2 = new ModuleRow($moduleId2, 'mod_headline', 'Entry_Headline', $this->blogId, 'local');
        $snapshotId2 = $this->repository->save($module2, userId: 2);

        $summaries = $this->repository->findAllByBlogId($this->blogId);

        $this->assertCount(2, $summaries);
        $this->assertContainsOnlyInstancesOf(SnapshotSummary::class, $summaries);

        // 新しい順(直近に保存したsnapshotId2が先頭)
        $this->assertSame($snapshotId2, $summaries[0]->snapshotId);
        $this->assertSame($moduleId2, $summaries[0]->moduleId);
        $this->assertSame('Entry_Headline', $summaries[0]->moduleName);
        $this->assertSame(2, $summaries[0]->userId);

        $this->assertSame($snapshotId1, $summaries[1]->snapshotId);
        $this->assertSame($moduleId1, $summaries[1]->moduleId);
        $this->assertSame('Plugin_Schedule', $summaries[1]->moduleName);
        $this->assertSame(1, $summaries[1]->userId);
    }

    #[Test]
    #[TestDox('findAllByBlogId()は他ブログのスナップショットを含まない')]
    public function findAllByBlogIdExcludesOtherBlogs(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = new ModuleRow($moduleId, 'mod_schedule', 'Plugin_Schedule', $this->blogId, 'local');
        $this->repository->save($module, userId: 1);

        $otherBlogId = BlogSeeder::seed(['blog_name' => '別ブログ']);

        $summaries = $this->repository->findAllByBlogId($otherBlogId);

        $this->assertSame([], $summaries);
    }
}
