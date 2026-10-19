<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\ServiceProvider;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ServiceProvider
 */
#[CoversClass(ServiceProvider::class)]
final class ServiceProviderTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function versionProvider(): array
    {
        return [
            '下限ちょうど' => ['3.2.36', true],
            '下限より新しいパッチ' => ['3.2.37', true],
            '次のマイナー' => ['3.3.0', true],
            '下限より1つ古い' => ['3.2.35', false],
            '以前の下限' => ['3.2.29', false],
            '2系' => ['2.11.50', false],
        ];
    }

    #[Test]
    #[TestDox('a-blog cms $version は、対応バージョン(3.2.36以降)かどうか: $expected')]
    #[DataProvider('versionProvider')]
    public function isSupportedVersion(string $version, bool $expected): void
    {
        $this->assertSame($expected, ServiceProvider::isSupportedVersion($version));
    }
}
