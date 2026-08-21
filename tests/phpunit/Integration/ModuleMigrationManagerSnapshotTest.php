<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager
 *
 * applyWithSnapshot() / rollback() によるスナップショット連携を検証する。
 */
#[CoversClass(ModuleMigrationManager::class)]
final class ModuleMigrationManagerSnapshotTest extends DatabaseTestCase
{
    private ModuleMigrationManager $manager;
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->manager = new ModuleMigrationManager();
        $this->blogId = BlogSeeder::seed(['blog_name' => 'Managerスナップショット統合テスト用ブログ']);
    }

    #[Test]
    #[TestDox('applyWithSnapshot()の後にrollback()すると、module_nameが適用前の状態に戻る')]
    public function applyWithSnapshotThenRollbackRevertsModuleName(): void
    {
        ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);

        $outcome = $this->manager->applyWithSnapshot($module, $diff, userId: 1);

        $this->assertSame('Schedule', $outcome['result']->newModuleName);
        $this->assertGreaterThan(0, $outcome['snapshotId']);

        $this->manager->rollback($outcome['snapshotId'], $this->blogId);

        $sql = SQL::newSelect('module');
        $sql->addSelect('module_name');
        $sql->addWhereOpr('module_id', $module->moduleId);
        $this->assertSame('Plugin_Schedule', DB::query($sql->get(dsn()), 'one'));
    }

    #[Test]
    #[TestDox('rollback()はexpectedBlogIdがスナップショットの所有ブログと一致しない場合、RuntimeExceptionをスローする(IDOR対策)')]
    public function rollbackThrowsWhenExpectedBlogIdDoesNotMatch(): void
    {
        ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);
        $outcome = $this->manager->applyWithSnapshot($module, $diff, userId: 1);

        $otherBlogId = $this->blogId + 1;
        $this->expectException(\RuntimeException::class);
        $this->manager->rollback($outcome['snapshotId'], $otherBlogId);
    }
}
