<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit\Strategy\Banner;

use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\SafeRelativePath;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * a-blog cms コアの Filesystem::isSafeRelativePath() と同じ判定結果になることを固定する
 * 特徴付けテスト(ケースはコア側 FilesystemTest の provider を移植)。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\SafeRelativePath
 */
#[CoversClass(SafeRelativePath::class)]
final class SafeRelativePathTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function safeRelativePathProvider(): array
    {
        return [
            'アップロードで生成される日付ディレクトリ付きのパス' => ['2026/08/0123456789abcdef.jpg'],
            'ディレクトリを持たないファイル名' => ['0123456789abcdef.jpg'],
            '深い階層' => ['2026/08/19/sub/0123456789abcdef.png'],
            'ハイフン・アンダースコアを含むファイル名' => ['2026/08/my-photo_01.jpg'],
            '日本語ファイル名(rawfilename運用)' => ['2026/08/写真.jpg'],
            'ドットで始まらない二重拡張子' => ['2026/08/archive.tar.gz'],
            '二重ドットで始まるだけの名前は遡上ではない' => ['2026/08/..hidden.jpg'],
        ];
    }

    #[Test]
    #[TestDox('基準ディレクトリ配下に収まる相対パスは許可される')]
    #[DataProvider('safeRelativePathProvider')]
    public function isSafeReturnsTrueForSafePath(string $path): void
    {
        $this->assertTrue(SafeRelativePath::isSafe($path));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeRelativePathProvider(): array
    {
        return [
            '空文字' => [''],
            '親ディレクトリへの遡上' => ['../secret.jpg'],
            '途中に含まれる親ディレクトリ' => ['2026/08/../../../config.server.php'],
            '末尾の親ディレクトリ' => ['2026/08/..'],
            '絶対パス' => ['/etc/passwd'],
            'Windowsのドライブレター' => ['C:/windows/system32/drivers/etc/hosts'],
            'バックスラッシュ区切りの遡上' => ['..\\secret.jpg'],
            'バックスラッシュ混在の遡上' => ['2026\\08\\..\\..\\config.server.php'],
            'ヌルバイトによる切り詰め' => ["2026/08/valid.jpg\0.php"],
            'スキーム付きURL' => ['https://example.com/evil.jpg'],
            'ストリームラッパー' => ['php://filter/convert.base64-encode/resource=index.php'],
        ];
    }

    #[Test]
    #[TestDox('基準ディレクトリの外へ抜け出せる相対パスは拒否される')]
    #[DataProvider('unsafeRelativePathProvider')]
    public function isSafeReturnsFalseForUnsafePath(string $path): void
    {
        $this->assertFalse(SafeRelativePath::isSafe($path));
    }
}
