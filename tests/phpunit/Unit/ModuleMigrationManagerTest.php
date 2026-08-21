<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\CategoryEntrySummaryMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\EntrySummaryMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\MediaBannerMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\ScheduleMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\UserSearchAdvisoryStrategy;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager
 */
#[CoversClass(ModuleMigrationManager::class)]
class ModuleMigrationManagerTest extends TestCase
{
    #[Test]
    #[TestDox('デフォルトコンストラクタは非推奨5モジュール名すべてを解決できる')]
    public function defaultConstructorSupportsAllFiveDeprecatedModules(): void
    {
        $manager = new ModuleMigrationManager();

        foreach (
            [
                'Plugin_Schedule',
                'Entry_Headline',
                'Entry_List',
                'Entry_Photo',
                'Category_EntryList',
                'Banner',
                'User_Profile',
            ] as $moduleName
        ) {
            $this->assertNotNull($manager->resolveStrategy($moduleName), "{$moduleName} が解決できること");
        }
    }

    #[Test]
    #[TestDox('resolveStrategy()はサポート対象外のmodule_nameに対してnullを返す')]
    public function resolveStrategyReturnsNullForUnsupportedModuleName(): void
    {
        $manager = new ModuleMigrationManager();

        $this->assertNull($manager->resolveStrategy('Entry_Body'));
    }

    #[Test]
    #[TestDox('resolveStrategy()は対応するStrategyインスタンスを返す(Scheduleの場合)')]
    public function resolveStrategyReturnsScheduleStrategyForPluginSchedule(): void
    {
        $manager = new ModuleMigrationManager();

        $this->assertInstanceOf(ScheduleMigrationStrategy::class, $manager->resolveStrategy('Plugin_Schedule'));
    }

    #[Test]
    #[TestDox('resolveStrategy()は対応するStrategyインスタンスを返す(Entry_Headlineの場合)')]
    public function resolveStrategyReturnsEntrySummaryStrategyForEntryHeadline(): void
    {
        $manager = new ModuleMigrationManager();

        $this->assertInstanceOf(EntrySummaryMigrationStrategy::class, $manager->resolveStrategy('Entry_Headline'));
    }

    #[Test]
    #[TestDox('resolveStrategy()は対応するStrategyインスタンスを返す(Category_EntryListの場合)')]
    public function resolveStrategyReturnsCategoryEntrySummaryStrategyForCategoryEntryList(): void
    {
        $manager = new ModuleMigrationManager();

        $this->assertInstanceOf(
            CategoryEntrySummaryMigrationStrategy::class,
            $manager->resolveStrategy('Category_EntryList')
        );
    }

    #[Test]
    #[TestDox('resolveStrategy()は対応するStrategyインスタンスを返す(Bannerの場合)')]
    public function resolveStrategyReturnsMediaBannerStrategyForBanner(): void
    {
        $manager = new ModuleMigrationManager();

        $this->assertInstanceOf(MediaBannerMigrationStrategy::class, $manager->resolveStrategy('Banner'));
    }

    #[Test]
    #[TestDox('resolveStrategy()は対応するStrategyインスタンスを返す(User_Profileの場合)')]
    public function resolveStrategyReturnsUserSearchAdvisoryStrategyForUserProfile(): void
    {
        $manager = new ModuleMigrationManager();

        $this->assertInstanceOf(UserSearchAdvisoryStrategy::class, $manager->resolveStrategy('User_Profile'));
    }

    #[Test]
    #[TestDox('コンストラクタにStrategy一覧を注入した場合、そちらが優先される')]
    public function constructorAcceptsCustomStrategyList(): void
    {
        $customStrategy = new ScheduleMigrationStrategy();
        $manager = new ModuleMigrationManager([$customStrategy]);

        $this->assertSame($customStrategy, $manager->resolveStrategy('Plugin_Schedule'));
        $this->assertNull($manager->resolveStrategy('Entry_Headline'));
    }
}
