<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ConfigSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository
 */
#[CoversClass(ModuleMigrationRepository::class)]
final class ModuleMigrationRepositoryTest extends DatabaseTestCase
{
    private ModuleMigrationRepository $repository;
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->repository = new ModuleMigrationRepository();
        $this->blogId = BlogSeeder::seed(['blog_name' => 'モジュール移行テスト用ブログ']);
    }

    #[Test]
    #[TestDox('renameModule() は対象moduleのmodule_nameを書き換える')]
    public function renameModuleUpdatesModuleName(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);

        $this->repository->renameModule($moduleId, $this->blogId, 'Schedule');

        $this->assertSame('Schedule', $this->fetchModuleName($moduleId));
    }

    #[Test]
    #[TestDox('renameModule() は所有ブログが一致しない場合は書き換えない')]
    public function renameModuleDoesNothingForMismatchedBlog(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $otherBlogId = BlogSeeder::seed(['blog_name' => '別のブログ']);

        $this->repository->renameModule($moduleId, $otherBlogId, 'Schedule');

        $this->assertSame('Plugin_Schedule', $this->fetchModuleName($moduleId));
    }

    #[Test]
    #[TestDox('renameWouldCollide() は同一identifier・同一新名称・同一ブログ・同一scopeの別モジュールが存在する場合trueを返す')]
    public function renameWouldCollideDetectsExistingModuleWithSameIdentifierNameBlogAndScope(): void
    {
        ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_dup',
            'module_name' => 'Entry_Summary',
            'module_scope' => 'local',
        ]);
        $movingModuleId = ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_dup',
            'module_name' => 'Entry_Headline',
            'module_scope' => 'local',
        ]);

        $wouldCollide = $this->repository->renameWouldCollide(
            $movingModuleId,
            'mod_dup',
            'Entry_Summary',
            $this->blogId,
            'local'
        );

        $this->assertTrue($wouldCollide);
    }

    #[Test]
    #[TestDox('renameWouldCollide() は自分自身のmodule_idは衝突対象から除外する')]
    public function renameWouldCollideExcludesItself(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_self',
            'module_name' => 'Entry_Headline',
            'module_scope' => 'local',
        ]);

        $wouldCollide = $this->repository->renameWouldCollide(
            $moduleId,
            'mod_self',
            'Entry_Headline',
            $this->blogId,
            'local'
        );

        $this->assertFalse($wouldCollide);
    }

    #[Test]
    #[TestDox('renameWouldCollide() はidentifierが異なれば衝突しない')]
    public function renameWouldCollideReturnsFalseWhenIdentifierDiffers(): void
    {
        ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_a',
            'module_name' => 'Entry_Summary',
            'module_scope' => 'local',
        ]);
        $movingModuleId = ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_b',
            'module_name' => 'Entry_Headline',
            'module_scope' => 'local',
        ]);

        $wouldCollide = $this->repository->renameWouldCollide(
            $movingModuleId,
            'mod_b',
            'Entry_Summary',
            $this->blogId,
            'local'
        );

        $this->assertFalse($wouldCollide);
    }

    #[Test]
    #[TestDox('renameModule() はUNIQUE制約(module_identifier, module_name, module_blog_id, module_scope)違反時にRuntimeExceptionをスローする(execの無言失敗を防ぐ安全網)')]
    public function renameModuleThrowsWhenUniqueConstraintIsViolated(): void
    {
        ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_conflict',
            'module_name' => 'Entry_Summary',
            'module_scope' => 'local',
        ]);
        $movingModuleId = ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_conflict',
            'module_name' => 'Entry_Headline',
            'module_scope' => 'local',
        ]);

        $this->expectException(\RuntimeException::class);
        // renameWouldCollide()による事前検出を経由せず、renameModule()自体がexecの失敗を
        // 検知して例外化することを直接確認する(execOrFail()の安全網そのものを特徴づける)。
        $this->repository->renameModule($movingModuleId, $this->blogId, 'Entry_Summary');
    }

    #[Test]
    #[TestDox('forceGlobalScope() は指定したscopeカラムをglobalへ書き換える')]
    public function forceGlobalScopeUpdatesGivenColumns(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, [
            'module_name' => 'Entry_Headline',
            'module_cid_scope' => 'local',
            'module_uid_scope' => 'local',
        ]);

        $this->repository->forceGlobalScope($moduleId, $this->blogId, ['module_cid_scope', 'module_uid_scope']);

        $sql = SQL::newSelect('module');
        $sql->addSelect('module_cid_scope');
        $sql->addSelect('module_uid_scope');
        $sql->addWhereOpr('module_id', $moduleId);
        $row = DB::query($sql->get(dsn()), 'row');

        $this->assertSame('global', $row['module_cid_scope']);
        $this->assertSame('global', $row['module_uid_scope']);
    }

    #[Test]
    #[TestDox('forceGlobalScope() は空配列の場合は何もしない')]
    public function forceGlobalScopeDoesNothingForEmptyColumns(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_cid_scope' => 'local']);

        $this->repository->forceGlobalScope($moduleId, $this->blogId, []);

        $sql = SQL::newSelect('module');
        $sql->addSelect('module_cid_scope');
        $sql->addWhereOpr('module_id', $moduleId);
        $row = DB::query($sql->get(dsn()), 'row');

        $this->assertSame('local', $row['module_cid_scope']);
    }

    #[Test]
    #[TestDox('upsertModuleConfig() は対象config行が存在しない場合、新規INSERTする')]
    public function upsertModuleConfigInsertsWhenRowDoesNotExist(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);

        $this->repository->upsertModuleConfig($this->blogId, null, $moduleId, 'entry_summary_fulltext', 'off');

        $this->assertSame('off', $this->fetchConfigValue($this->blogId, $moduleId, 'entry_summary_fulltext'));
    }

    #[Test]
    #[TestDox('upsertModuleConfig() は対象config行が既に存在する場合、UPDATEする')]
    public function upsertModuleConfigUpdatesWhenRowAlreadyExists(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);
        ConfigSeeder::seed($this->blogId, 'entry_summary_fulltext', 'on', ['config_module_id' => $moduleId]);

        $this->repository->upsertModuleConfig($this->blogId, null, $moduleId, 'entry_summary_fulltext', 'off');

        $this->assertSame('off', $this->fetchConfigValue($this->blogId, $moduleId, 'entry_summary_fulltext'));
        $this->assertSame(1, $this->countConfigRows($this->blogId, $moduleId, 'entry_summary_fulltext'));
    }

    #[Test]
    #[TestDox('upsertModuleConfig() は同一モジュールへの複数キー書き込みでconfig_sortが重複しない')]
    public function upsertModuleConfigAssignsIncreasingSortForMultipleKeys(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);

        $this->repository->upsertModuleConfig($this->blogId, null, $moduleId, 'entry_summary_fulltext', 'off');
        $this->repository->upsertModuleConfig($this->blogId, null, $moduleId, 'entry_summary_tag', 'off');

        $sortA = $this->fetchConfigSort($this->blogId, $moduleId, 'entry_summary_fulltext');
        $sortB = $this->fetchConfigSort($this->blogId, $moduleId, 'entry_summary_tag');

        $this->assertNotSame($sortA, $sortB);
    }

    #[Test]
    #[TestDox('replaceModuleConfigArray() は指定した値の配列をconfig_sort順の複数行として書き込む')]
    public function replaceModuleConfigArrayInsertsOrderedRows(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Banner']);

        $this->repository->replaceModuleConfigArray(
            $this->blogId,
            null,
            $moduleId,
            'media_banner_status',
            ['true', 'false', 'true']
        );

        $this->assertSame(['true', 'false', 'true'], $this->fetchOrderedConfigValues($this->blogId, $moduleId, 'media_banner_status'));
    }

    #[Test]
    #[TestDox('replaceModuleConfigArray() は既存行を全削除してから書き込む(要素数が減っても古い行が残らない)')]
    public function replaceModuleConfigArrayReplacesExistingRows(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Banner']);
        $this->repository->replaceModuleConfigArray($this->blogId, null, $moduleId, 'media_banner_status', ['true', 'false', 'true']);

        $this->repository->replaceModuleConfigArray($this->blogId, null, $moduleId, 'media_banner_status', ['false']);

        $this->assertSame(['false'], $this->fetchOrderedConfigValues($this->blogId, $moduleId, 'media_banner_status'));
    }

    #[Test]
    #[TestDox('replaceModuleConfigArray() は空配列を渡すと全行を削除するのみで新規行を作らない')]
    public function replaceModuleConfigArrayWithEmptyArrayOnlyDeletes(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Banner']);
        $this->repository->replaceModuleConfigArray($this->blogId, null, $moduleId, 'media_banner_status', ['true']);

        $this->repository->replaceModuleConfigArray($this->blogId, null, $moduleId, 'media_banner_status', []);

        $this->assertSame([], $this->fetchOrderedConfigValues($this->blogId, $moduleId, 'media_banner_status'));
    }

    /**
     * @return string[]
     */
    private function fetchOrderedConfigValues(int $blogId, int $moduleId, string $key): array
    {
        $sql = SQL::newSelect('config');
        $sql->addSelect('config_value');
        $sql->addWhereOpr('config_key', $key);
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $blogId);
        $sql->setOrder('config_sort');

        return array_map(static fn (array $row): string => $row['config_value'], DB::query($sql->get(dsn()), 'all'));
    }

    private function fetchModuleName(int $moduleId): string
    {
        $sql = SQL::newSelect('module');
        $sql->addSelect('module_name');
        $sql->addWhereOpr('module_id', $moduleId);

        return (string) DB::query($sql->get(dsn()), 'one');
    }

    private function fetchConfigValue(int $blogId, int $moduleId, string $key): string
    {
        $sql = SQL::newSelect('config');
        $sql->addSelect('config_value');
        $sql->addWhereOpr('config_key', $key);
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $blogId);

        return (string) DB::query($sql->get(dsn()), 'one');
    }

    private function fetchConfigSort(int $blogId, int $moduleId, string $key): int
    {
        $sql = SQL::newSelect('config');
        $sql->addSelect('config_sort');
        $sql->addWhereOpr('config_key', $key);
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $blogId);

        return (int) DB::query($sql->get(dsn()), 'one');
    }

    private function countConfigRows(int $blogId, int $moduleId, string $key): int
    {
        $sql = SQL::newSelect('config');
        $sql->addWhereOpr('config_key', $key);
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $blogId);

        return count(DB::query($sql->get(dsn()), 'all'));
    }
}
