<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration\Strategy;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\BannerImageFileCheckerInterface;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\BannerImageMigratorInterface;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\MediaBannerMigrationStrategy;
use Acms\TestingFramework\DatabaseTestCase;
use Acms\TestingFramework\Seeder\BlogSeeder;
use Acms\TestingFramework\Seeder\ModuleSeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use SQL;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\MediaBannerMigrationStrategy
 *
 * 実ファイルシステム/画像最適化処理を伴う MediaHelperBannerImageMigrator 自体の統合検証は
 * スコープ外とし(ランクC・オプトインの低優先機能のため)、DB書き込みのオーケストレーションを
 * フェイクの画像マイグレータ経由で検証する。
 *
 * a-blog cmsの複数値configは「同一config_key(@N無し)の複数行をconfig_sort順に並べる」方式
 * (Services/Config/Helper::fix()のbanner正規化・ACMS_GET_Media_Bannerのconfig($key,'',$i)読み出し
 * で確認済み)であるため、DBへは "media_banner_mid" のようなプレーンなキーで複数行として
 * 書き込まれることを検証する(diff()のプレビュー表示専用の"@N"合成キーとは異なる)。
 */
#[CoversClass(MediaBannerMigrationStrategy::class)]
final class MediaBannerMigrationStrategyTest extends DatabaseTestCase
{
    private int $blogId;

    protected function setUpDatabase(): void
    {
        $this->blogId = BlogSeeder::seed(['blog_name' => 'Banner移行テスト用ブログ']);
    }

    #[Test]
    #[TestDox('apply()はmodule_nameをMedia_Bannerへ書き換え、プレーンなconfig_keyの複数行としてスロット値を書き込む')]
    public function applyMigratesBannerModuleAndImageSlot(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Banner']);
        $module = new ModuleRow($moduleId, 'mod_banner', 'Banner', $this->blogId, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_img' => ['banner/foo.jpg'],
            'banner_url' => ['https://example.com'],
        ]);

        $fileChecker = new class implements BannerImageFileCheckerInterface {
            public function exists(int $blogId, string $relativePath): bool
            {
                return true;
            }
        };
        $imageMigrator = new class implements BannerImageMigratorInterface {
            public function migrate(int $blogId, string $relativePath, string $linkUrl): int
            {
                return 555;
            }
        };

        $strategy = new MediaBannerMigrationStrategy(new ModuleMigrationRepository(), $fileChecker, $imageMigrator);

        $diff = $strategy->diff($module, $configs);
        $result = $strategy->apply($module, $configs, $diff);

        $this->assertSame('Media_Banner', $result->newModuleName);
        $this->assertSame(['555'], $result->writtenConfig['media_banner_mid']);
        $this->assertSame(['image'], $result->writtenConfig['media_banner_type']);
        $this->assertSame(['true'], $result->writtenConfig['media_banner_status']);

        $sql = SQL::newSelect('module');
        $sql->addSelect('module_name');
        $sql->addWhereOpr('module_id', $moduleId);
        $this->assertSame('Media_Banner', DB::query($sql->get(dsn()), 'one'));

        $this->assertSame(['555'], $this->fetchOrderedConfigValues($moduleId, 'media_banner_mid'));
        $this->assertSame(['image'], $this->fetchOrderedConfigValues($moduleId, 'media_banner_type'));
    }

    #[Test]
    #[TestDox('apply()は複数スロットの位置を揃えて書き込む(imgスロットとsrcスロットが混在しても欠番でずれない)')]
    public function applyKeepsSlotPositionsAlignedAcrossKeys(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Banner']);
        $module = new ModuleRow($moduleId, 'mod_banner', 'Banner', $this->blogId, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open', 'close'],
            'banner_img' => ['banner/foo.jpg', ''],
            'banner_src' => ['', '<iframe></iframe>'],
        ]);

        $fileChecker = new class implements BannerImageFileCheckerInterface {
            public function exists(int $blogId, string $relativePath): bool
            {
                return true;
            }
        };
        $imageMigrator = new class implements BannerImageMigratorInterface {
            public function migrate(int $blogId, string $relativePath, string $linkUrl): int
            {
                return 777;
            }
        };

        $strategy = new MediaBannerMigrationStrategy(new ModuleMigrationRepository(), $fileChecker, $imageMigrator);
        $diff = $strategy->diff($module, $configs);
        $strategy->apply($module, $configs, $diff);

        // スロット0=画像(mid=777, source=''), スロット1=iframe(mid='', source=iframe) の位置が揃うこと
        $this->assertSame(['777', ''], $this->fetchOrderedConfigValues($moduleId, 'media_banner_mid'));
        $this->assertSame(['', '<iframe></iframe>'], $this->fetchOrderedConfigValues($moduleId, 'media_banner_source'));
        $this->assertSame(['image', 'source'], $this->fetchOrderedConfigValues($moduleId, 'media_banner_type'));
        $this->assertSame(['true', 'false'], $this->fetchOrderedConfigValues($moduleId, 'media_banner_status'));
    }

    #[Test]
    #[TestDox('applyForRule()は指定したconfig_rule_idの下にスロット値を書き込み、ルール無し(NULL)の行とは混在しない')]
    public function applyForRuleWritesSlotsUnderGivenRuleIdOnly(): void
    {
        $moduleId = ModuleSeeder::seed($this->blogId, ['module_name' => 'Banner']);
        $module = new ModuleRow($moduleId, 'mod_banner', 'Banner', $this->blogId, 'local');
        $ruleId = 999;
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_img' => ['banner/rule-specific.jpg'],
            'banner_url' => ['https://rule.example.com'],
        ]);

        $fileChecker = new class implements BannerImageFileCheckerInterface {
            public function exists(int $blogId, string $relativePath): bool
            {
                return true;
            }
        };
        $imageMigrator = new class implements BannerImageMigratorInterface {
            public function migrate(int $blogId, string $relativePath, string $linkUrl): int
            {
                return 888;
            }
        };
        $strategy = new MediaBannerMigrationStrategy(new ModuleMigrationRepository(), $fileChecker, $imageMigrator);

        $diff = $strategy->diff($module, $configs);
        $strategy->applyForRule($module, $configs, $diff, $ruleId);

        $ruleScoped = SQL::newSelect('config');
        $ruleScoped->addSelect('config_value');
        $ruleScoped->addWhereOpr('config_key', 'media_banner_mid');
        $ruleScoped->addWhereOpr('config_module_id', $moduleId);
        $ruleScoped->addWhereOpr('config_blog_id', $this->blogId);
        $ruleScoped->addWhereOpr('config_rule_id', $ruleId);
        $this->assertSame('888', DB::query($ruleScoped->get(dsn()), 'one'));

        $noRule = SQL::newSelect('config');
        $noRule->addSelect('config_key');
        $noRule->addWhereOpr('config_key', 'media_banner_mid');
        $noRule->addWhereOpr('config_module_id', $moduleId);
        $noRule->addWhereOpr('config_blog_id', $this->blogId);
        $noRule->addWhereOpr('config_rule_id', null);
        $this->assertFalse(DB::query($noRule->get(dsn()), 'one'));
    }

    /**
     * @return string[]
     */
    private function fetchOrderedConfigValues(int $moduleId, string $key): array
    {
        $sql = SQL::newSelect('config');
        $sql->addSelect('config_value');
        $sql->addWhereOpr('config_key', $key);
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $this->blogId);
        $sql->setOrder('config_sort');

        return array_map(static fn (array $row): string => $row['config_value'], DB::query($sql->get(dsn()), 'all'));
    }
}
