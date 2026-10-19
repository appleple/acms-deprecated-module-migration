<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit\Strategy\Banner;

use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\MediaHelperBannerImageMigrator;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\MediaHelperBannerImageMigrator
 *
 * 画像の保存そのもの(コアの Media::registerLocalFile())は実ファイルと DB を伴うため、
 * ここでは「コアへ渡す一時ファイルの名前」だけを固定する。拡張子が無いと、コアが
 * 一覧用の縮小画像(tiny-)を作れず、メディアの一覧・編集画面でサムネイルが空になる。
 */
#[CoversClass(MediaHelperBannerImageMigrator::class)]
final class MediaHelperBannerImageMigratorTest extends TestCase
{
    /**
     * @return array<string, array{string, non-empty-string}>
     */
    public static function extensionProvider(): array
    {
        return [
            'png' => ['banner/foo.png', '.png'],
            '大文字の拡張子はそのまま残す' => ['banner/foo.JPG', '.JPG'],
            'ディレクトリ名にドットがあっても、ファイル名の拡張子を使う' => ['v1.0/foo.webp', '.webp'],
            '日本語のファイル名' => ['banner/バナー.jpeg', '.jpeg'],
        ];
    }

    #[Test]
    #[TestDox('一時ファイルの名前は、元のファイルの拡張子を保つ: $relativePath')]
    #[DataProvider('extensionProvider')]
    public function temporaryFilePathKeepsExtension(string $relativePath, string $expectedSuffix): void
    {
        $path = MediaHelperBannerImageMigrator::temporaryFilePath($relativePath);

        $this->assertSame($expectedSuffix, '.' . pathinfo($path, PATHINFO_EXTENSION));
        $this->assertStringStartsWith(rtrim(sys_get_temp_dir(), '/') . '/', $path);
    }

    #[Test]
    #[TestDox('拡張子の無いファイルは、拡張子を付けない')]
    public function temporaryFilePathHasNoSuffixWithoutExtension(): void
    {
        $path = MediaHelperBannerImageMigrator::temporaryFilePath('banner/foo');

        $this->assertSame('', pathinfo($path, PATHINFO_EXTENSION));
    }

    #[Test]
    #[TestDox('同じファイルでも、呼ぶたびに別の一時ファイル名になる')]
    public function temporaryFilePathIsUnique(): void
    {
        $this->assertNotSame(
            MediaHelperBannerImageMigrator::temporaryFilePath('banner/foo.png'),
            MediaHelperBannerImageMigrator::temporaryFilePath('banner/foo.png')
        );
    }
}
