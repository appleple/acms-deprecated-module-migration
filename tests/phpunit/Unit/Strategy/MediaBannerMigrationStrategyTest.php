<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\BannerImageFileCheckerInterface;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\BannerImageMigratorInterface;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\MediaBannerMigrationStrategy;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\MediaBannerMigrationStrategy
 */
#[CoversClass(MediaBannerMigrationStrategy::class)]
class MediaBannerMigrationStrategyTest extends TestCase
{
    private function makeStrategy(bool $fileExists = true): MediaBannerMigrationStrategy
    {
        $fileChecker = new class ($fileExists) implements BannerImageFileCheckerInterface {
            public function __construct(private readonly bool $exists)
            {
            }

            public function exists(int $blogId, string $relativePath): bool
            {
                return $this->exists;
            }
        };

        $migrator = new class implements BannerImageMigratorInterface {
            public function migrate(int $blogId, string $relativePath, string $linkUrl): int
            {
                return 999;
            }
        };

        return new MediaBannerMigrationStrategy(fileChecker: $fileChecker, imageMigrator: $migrator);
    }

    #[Test]
    #[TestDox('supports()はBannerのみを返す')]
    public function supportsReturnsBanner(): void
    {
        $this->assertSame(['Banner'], $this->makeStrategy()->supports());
    }

    #[Test]
    #[TestDox('rank()はCを返す')]
    public function rankIsC(): void
    {
        $this->assertSame('C', $this->makeStrategy()->rank());
    }

    #[Test]
    #[TestDox('targetModuleName()は常にMedia_Bannerを返す')]
    public function targetModuleNameIsAlwaysMediaBanner(): void
    {
        $this->assertSame('Media_Banner', $this->makeStrategy()->targetModuleName('Banner'));
    }

    #[Test]
    #[TestDox('diff()はbanner_statusがopenの場合、trueへ値変換したstatus項目を持つ')]
    public function diffTransformsOpenStatusToTrue(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_url' => ['https://example.com'],
        ]);

        $diff = $this->makeStrategy()->diff($module, $configs);
        $item = $this->findItem($diff, 'media_banner_status@0');

        $this->assertSame('true', $item->sourceEffectiveValue);
    }

    #[Test]
    #[TestDox('diff()はbanner_statusがclose(open以外)の場合、falseへ値変換する')]
    public function diffTransformsNonOpenStatusToFalse(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], ['banner_status' => ['close']]);

        $diff = $this->makeStrategy()->diff($module, $configs);

        $this->assertSame('false', $this->findItem($diff, 'media_banner_status@0')->sourceEffectiveValue);
    }

    #[Test]
    #[TestDox('diff()はbanner_targetが_blankの場合、trueへ値変換する')]
    public function diffTransformsBlankTargetToTrue(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_target' => ['_blank'],
        ]);

        $diff = $this->makeStrategy()->diff($module, $configs);

        $this->assertSame('true', $this->findItem($diff, 'media_banner_target@0')->sourceEffectiveValue);
    }

    #[Test]
    #[TestDox('diff()はbanner_targetが空文字や_self等の場合、falseへ値変換する')]
    public function diffTransformsOtherTargetToFalse(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_target' => [''],
        ]);

        $diff = $this->makeStrategy()->diff($module, $configs);

        $this->assertSame('false', $this->findItem($diff, 'media_banner_target@0')->sourceEffectiveValue);
    }

    #[Test]
    #[TestDox('diff()はbanner_srcが設定されたスロットに対しmedia_banner_type=sourceを明示する')]
    public function diffSetsExplicitSourceType(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_src' => ['<iframe></iframe>'],
        ]);

        $diff = $this->makeStrategy()->diff($module, $configs);

        $this->assertSame('<iframe></iframe>', $this->findItem($diff, 'media_banner_source@0')->sourceEffectiveValue);
        $this->assertSame('source', $this->findItem($diff, 'media_banner_type@0')->sourceEffectiveValue);
    }

    #[Test]
    #[TestDox('diff()はbanner_imgが実在する場合、media_banner_mid/typeの明示書き込み項目を持つ')]
    public function diffMarksImageSlotForMigrationWhenFileExists(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_img' => ['banner/foo.jpg'],
        ]);

        $diff = $this->makeStrategy(fileExists: true)->diff($module, $configs);
        $midItem = $this->findItem($diff, 'media_banner_mid@0');
        $typeItem = $this->findItem($diff, 'media_banner_type@0');

        $this->assertSame('banner/foo.jpg', $midItem->sourceEffectiveValue);
        $this->assertSame('image', $typeItem->sourceEffectiveValue);
        $this->assertSame([], $diff->warnings);
    }

    #[Test]
    #[TestDox('diff()はbanner_imgの実ファイルが存在しない場合、そのスロットを対象から除外し警告を出す')]
    public function diffExcludesImageSlotWhenFileMissing(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open'],
            'banner_img' => ['banner/missing.jpg'],
        ]);

        $diff = $this->makeStrategy(fileExists: false)->diff($module, $configs);

        $this->assertNull($this->findItemOrNull($diff, 'media_banner_mid@0'));
        $this->assertNotEmpty($diff->warnings);
        $this->assertFalse($diff->isBlocked());
    }

    #[Test]
    #[TestDox('diff()は複数スロットをそれぞれ独立に評価する')]
    public function diffEvaluatesMultipleSlotsIndependently(): void
    {
        $module = new ModuleRow(1, 'mod_banner', 'Banner', 1, 'local');
        $configs = ConfigCollection::fromArrays([], [
            'banner_status' => ['open', 'close'],
        ]);

        $diff = $this->makeStrategy()->diff($module, $configs);

        $this->assertSame('true', $this->findItem($diff, 'media_banner_status@0')->sourceEffectiveValue);
        $this->assertSame('false', $this->findItem($diff, 'media_banner_status@1')->sourceEffectiveValue);
    }

    #[Test]
    #[TestDox('diff()はサポート外のmodule_nameに対してInvalidArgumentExceptionをスローする')]
    public function diffThrowsForUnsupportedModuleName(): void
    {
        $module = new ModuleRow(1, 'mod_x', 'Media_Banner', 1, 'local');

        $this->expectException(\InvalidArgumentException::class);
        $this->makeStrategy()->diff($module, ConfigCollection::fromArrays([], []));
    }

    private function findItem(
        \Acms\Plugins\DeprecatedModuleMigration\MigrationDiff $diff,
        string $targetConfigKey
    ): \Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem {
        $item = $this->findItemOrNull($diff, $targetConfigKey);
        if ($item === null) {
            $this->fail(sprintf('targetConfigKey "%s" の項目が見つかりませんでした。', $targetConfigKey));
        }

        return $item;
    }

    private function findItemOrNull(
        \Acms\Plugins\DeprecatedModuleMigration\MigrationDiff $diff,
        string $targetConfigKey
    ): ?\Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem {
        foreach ($diff->items as $item) {
            if ($item->targetConfigKey === $targetConfigKey) {
                return $item;
            }
        }

        return null;
    }
}
