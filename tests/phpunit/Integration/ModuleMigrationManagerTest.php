<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ConfigSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager
 */
#[CoversClass(ModuleMigrationManager::class)]
final class ModuleMigrationManagerTest extends DatabaseTestCase
{
    private ModuleMigrationManager $manager;
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->manager = new ModuleMigrationManager();
        $this->blogId = BlogSeeder::seed(['blog_name' => 'Manager統合テスト用ブログ']);
    }

    #[Test]
    #[TestDox('detect()は所有ブログの非推奨モジュールのみを列挙し、対象外モジュールは含めない')]
    public function detectListsOnlyDeprecatedModulesOwnedByBlog(): void
    {
        $scheduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Body']); // 対象外
        $otherBlogId = BlogSeeder::seed(['blog_name' => '別ブログ']);
        ModuleSeeder::seed($otherBlogId, ['module_name' => 'Plugin_Schedule']); // 所有ブログが異なる

        $result = $this->manager->detect($this->blogId);

        $this->assertCount(1, $result);
        $this->assertSame($scheduleId, $result[0]->moduleId);
        $this->assertSame('Plugin_Schedule', $result[0]->moduleName);
    }

    #[Test]
    #[TestDox('diff()はConfig::loadModuleConfig()経由で実際の設定行を実効値として解決する')]
    public function diffResolvesEffectiveValueFromActualConfigRow(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);
        ConfigSeeder::seed($this->blogId, 'entry_headline_limit', '6', ['config_module_id' => $moduleId]);

        $modules = $this->manager->detect($this->blogId);
        $module = $modules[0];
        $diff = $this->manager->diff($module);

        $limitItem = null;
        foreach ($diff->items as $item) {
            if ($item->targetConfigKey === 'entry_summary_limit') {
                $limitItem = $item;
            }
        }

        $this->assertNotNull($limitItem);
        $this->assertSame('6', $limitItem->sourceEffectiveValue);
    }

    #[Test]
    #[TestDox('diff()はmodule単位のconfig行が無くても、ブログ単位のconfig上書きを実効値として解決する')]
    public function diffResolvesEffectiveValueFromBlogLevelConfigWhenModuleRowIsAbsent(): void
    {
        // module_id を指定せず(グローバル=ブログ単位)に entry_headline_limit を上書きする。
        // module単位のconfig行は一切作らない(=このケースが実効値ベース差分計算の核心)。
        ConfigSeeder::seed($this->blogId, 'entry_headline_limit', '8');
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);

        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);

        $limitItem = null;
        foreach ($diff->items as $item) {
            if ($item->targetConfigKey === 'entry_summary_limit') {
                $limitItem = $item;
            }
        }

        $this->assertNotNull($limitItem);
        $this->assertSame(
            '8',
            $limitItem->sourceEffectiveValue,
            'module行が無くても、ブログ単位で上書きされた実効値(8)が旧実効値として解決されること'
        );
    }

    #[Test]
    #[TestDox('diff()はconfig行が一切無い(全キーがシステム既定値)場合、未対応キーの警告を出さない(既定値ベースの誤検知防止)')]
    public function diffDoesNotWarnAboutUnmappedKeysWhenNothingIsCustomized(): void
    {
        // config行を一切作らない = 全ての未対応キー(order2, offset, indexing等)がシステム既定値の
        // ままの状態。この状態でも「値が空文字でない」という判定基準だと、default.yaml側の既定値が
        // 非空文字であるキー(例: entry_headline_order2の既定値'id-desc')が常に誤検知されてしまう
        // (Fable再レビューで検出された回帰)。ConfigCollection::differsFromDefault()による
        // システム既定値との比較で、これが解消されていることを確認する。
        // scopeカラムはglobalに揃え、本テストの関心事(未対応キー警告)以外の警告(scope警告)が
        // 混入しないようにする。
        ModuleSeeder::seed($this->blogId, [
            'module_name' => 'Entry_Headline',
            'module_uid_scope' => 'global',
            'module_cid_scope' => 'global',
            'module_eid_scope' => 'global',
            'module_keyword_scope' => 'global',
            'module_tag_scope' => 'global',
            'module_field_scope' => 'global',
            'module_start_scope' => 'global',
            'module_end_scope' => 'global',
            'module_page_scope' => 'global',
            'module_order_scope' => 'global',
        ]);

        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);

        $this->assertSame(
            [],
            $diff->warnings,
            '未カスタマイズのconfig(システム既定値のまま)では、未対応キーの警告が出ないこと'
        );
    }

    #[Test]
    #[TestDox('diff()はブログ単位で未対応キーがシステム既定値から変更されている場合のみ警告する')]
    public function diffWarnsAboutUnmappedKeysOnlyWhenActuallyCustomized(): void
    {
        // entry_headline_offset(既定値0)をブログ単位で10へ明示的にカスタマイズしたケース。
        ConfigSeeder::seed($this->blogId, 'entry_headline_offset', '10');
        ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);

        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);

        $joined = implode(' / ', $diff->warnings);
        $this->assertStringContainsString('entry_headline_offset', $joined);
    }

    #[Test]
    #[TestDox('apply()はmodule_nameの書き換えまで実際にDBへ反映する(Plugin_Schedule)')]
    public function applyPersistsModuleRename(): void
    {
        ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = $this->manager->detect($this->blogId)[0];

        $diff = $this->manager->diff($module);
        $result = $this->manager->apply($module, $diff);

        $this->assertSame('Schedule', $result->newModuleName);

        $reloaded = $this->manager->detect($this->blogId);
        $this->assertSame([], $reloaded, 'リネーム後は非推奨モジュール一覧から外れること');
    }

    #[Test]
    #[TestDox('apply()は同一ブログ内の別モジュールとリネーム先が衝突する場合、DBへ書き込む前にRuntimeExceptionをスローする')]
    public function applyThrowsWhenRenameWouldCollideWithAnotherModule(): void
    {
        // 同じmodule_identifierを持つEntry_ListとEntry_Headlineが同一ブログに併存し、
        // 先にEntry_Listだけを手動でEntry_Summaryへリネーム済みのケースを想定する。
        // 実際にはconfig_rowが無いシンプルな状況(=既にEntry_Summaryが存在)を再現する。
        ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_dup',
            'module_name' => 'Entry_Summary',
            'module_scope' => 'local',
        ]);
        ModuleSeeder::seed($this->blogId, [
            'module_identifier' => 'mod_dup',
            'module_name' => 'Entry_Headline',
            'module_scope' => 'local',
        ]);

        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);

        $this->expectException(\RuntimeException::class);
        $this->manager->apply($module, $diff);
    }

    #[Test]
    #[TestDox('diff()はconfig_rule_idが設定された行がある場合、ルール別上書きの件数を警告として付け加える')]
    public function diffWarnsAboutRuleScopedOverrides(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Entry_Headline']);
        ConfigSeeder::seed($this->blogId, 'entry_headline_limit', '10', ['config_module_id' => $moduleId, 'config_rule_id' => 5]);
        ConfigSeeder::seed($this->blogId, 'entry_headline_limit', '20', ['config_module_id' => $moduleId, 'config_rule_id' => 7]);

        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);

        $joined = implode(' / ', $diff->warnings);
        $this->assertStringContainsString('2 件のルール別上書き', $joined);
    }

    #[Test]
    #[TestDox('diff()はルール別上書きが無い場合、その警告を付け加えない')]
    public function diffDoesNotWarnAboutRuleScopedOverridesWhenNoneExist(): void
    {
        ModuleSeeder::seed($this->blogId, [
            'module_name' => 'Entry_Headline',
            'module_uid_scope' => 'global',
            'module_cid_scope' => 'global',
            'module_eid_scope' => 'global',
            'module_keyword_scope' => 'global',
            'module_tag_scope' => 'global',
            'module_field_scope' => 'global',
            'module_start_scope' => 'global',
            'module_end_scope' => 'global',
            'module_page_scope' => 'global',
            'module_order_scope' => 'global',
        ]);

        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);

        $this->assertSame([], $diff->warnings);
    }

    #[Test]
    #[TestDox('apply()はルール別に上書きされたconfig行も、同じ変換ルールでそのルールIDのまま移行する')]
    public function applyMigratesRuleScopedConfigOverridesToo(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, [
            'module_name' => 'Entry_Headline',
            'module_uid_scope' => 'global',
            'module_cid_scope' => 'global',
            'module_eid_scope' => 'global',
            'module_keyword_scope' => 'global',
            'module_tag_scope' => 'global',
            'module_field_scope' => 'global',
            'module_start_scope' => 'global',
            'module_end_scope' => 'global',
            'module_page_scope' => 'global',
        ]);
        // ルールID 5 だけ limit を 20 にしている(ベース(ルール無し)は既定値のまま)。
        ConfigSeeder::seed($this->blogId, 'entry_headline_limit', '20', ['config_module_id' => $moduleId, 'config_rule_id' => 5]);

        $module = $this->manager->detect($this->blogId)[0];
        $diff = $this->manager->diff($module);
        $this->manager->apply($module, $diff);

        $sql = SQL::newSelect('config');
        $sql->addSelect('config_value');
        $sql->addWhereOpr('config_key', 'entry_summary_limit');
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $this->blogId);
        $sql->addWhereOpr('config_rule_id', 5);
        $this->assertSame(
            '20',
            DB::query($sql->get(dsn()), 'one'),
            'ルールID 5 の実効値(20)が entry_summary_limit として同じルールIDで移行されているはず'
        );
    }

    #[Test]
    #[TestDox('resolveStrategy()が解決できないmodule_nameに対してdiff()はInvalidArgumentExceptionをスローする')]
    public function diffThrowsWhenStrategyCannotBeResolved(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Plugin_Schedule']);
        $module = new \Acms\Plugins\DeprecatedModuleMigration\ModuleRow($moduleId, 'mod_x', 'Unknown_Module', $this->blogId, 'local');

        $this->expectException(\InvalidArgumentException::class);
        $this->manager->diff($module);
    }

    #[Test]
    #[TestDox('descendantBlogIds()は子・孫など配下の全階層のブログIDを深さ優先(blog_left昇順)で返し、他系統のブログは含まない')]
    public function descendantBlogIdsReturnsAllDescendantsAcrossLevels(): void
    {
        $rootId = BlogSeeder::seed(['blog_name' => '子孫テスト用ルート', 'blog_left' => 1, 'blog_right' => 8]);
        $childId1 = BlogSeeder::seed([
            'blog_name' => '子ブログ1',
            'blog_parent' => $rootId,
            'blog_left' => 2,
            'blog_right' => 5,
        ]);
        $grandchildId = BlogSeeder::seed([
            'blog_name' => '孫ブログ',
            'blog_parent' => $childId1,
            'blog_left' => 3,
            'blog_right' => 4,
        ]);
        $childId2 = BlogSeeder::seed([
            'blog_name' => '子ブログ2',
            'blog_parent' => $rootId,
            'blog_left' => 6,
            'blog_right' => 7,
        ]);
        $unrelatedId = BlogSeeder::seed(['blog_name' => '無関係な別系統のブログ', 'blog_left' => 1, 'blog_right' => 2]);

        $descendantIds = $this->manager->descendantBlogIds($rootId);

        $this->assertSame([$childId1, $grandchildId, $childId2], $descendantIds);
        $this->assertNotContains($unrelatedId, $descendantIds);
    }

    #[Test]
    #[TestDox('descendantBlogIds()は非公開(close)のブログを含まない')]
    public function descendantBlogIdsExcludesClosedBlogs(): void
    {
        $rootId = BlogSeeder::seed(['blog_name' => 'ルート', 'blog_left' => 1, 'blog_right' => 6]);
        $openChildId = BlogSeeder::seed([
            'blog_name' => '公開子ブログ',
            'blog_parent' => $rootId,
            'blog_left' => 2,
            'blog_right' => 3,
        ]);
        BlogSeeder::seed([
            'blog_name' => '非公開子ブログ',
            'blog_parent' => $rootId,
            'blog_status' => 'close',
            'blog_left' => 4,
            'blog_right' => 5,
        ]);

        $descendantIds = $this->manager->descendantBlogIds($rootId);

        $this->assertSame([$openChildId], $descendantIds);
    }

    #[Test]
    #[TestDox('descendantBlogIds()は子孫を持たないブログに対して空配列を返す')]
    public function descendantBlogIdsReturnsEmptyArrayWhenNoDescendants(): void
    {
        $leafId = BlogSeeder::seed(['blog_name' => '子孫なしブログ', 'blog_left' => 1, 'blog_right' => 2]);

        $this->assertSame([], $this->manager->descendantBlogIds($leafId));
    }

    #[Test]
    #[TestDox('blogNames()は指定したblogIdをキーにブログ名を返す')]
    public function blogNamesReturnsNameMapForGivenBlogIds(): void
    {
        $otherBlogId = BlogSeeder::seed(['blog_name' => '別ブログ']);

        $names = $this->manager->blogNames([$this->blogId, $otherBlogId]);

        $this->assertSame('Manager統合テスト用ブログ', $names[$this->blogId]);
        $this->assertSame('別ブログ', $names[$otherBlogId]);
    }

    #[Test]
    #[TestDox('blogNames()は空配列を渡すと空配列を返す(クエリを発行しない)')]
    public function blogNamesReturnsEmptyArrayForEmptyInput(): void
    {
        $this->assertSame([], $this->manager->blogNames([]));
    }
}
