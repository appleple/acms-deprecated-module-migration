<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ConfigCollection
 */
#[CoversClass(ConfigCollection::class)]
class ConfigCollectionTest extends TestCase
{
    #[Test]
    #[TestDox('fromArray() で構築した場合、存在するキーの値を返す')]
    public function getReturnsValueForExistingKey(): void
    {
        $configs = ConfigCollection::fromArray(['entry_summary_limit' => 6]);

        $this->assertSame(6, $configs->get('entry_summary_limit'));
    }

    #[Test]
    #[TestDox('fromArray() で構築した場合、存在しないキーはデフォルト値を返す')]
    public function getReturnsDefaultForMissingKey(): void
    {
        $configs = ConfigCollection::fromArray(['entry_summary_limit' => 6]);

        $this->assertSame('fallback', $configs->get('not_registered_key', 'fallback'));
    }

    #[Test]
    #[TestDox('get() はデフォルト値省略時、存在しないキーに対しnullを返す')]
    public function getReturnsNullByDefaultForMissingKey(): void
    {
        $configs = ConfigCollection::fromArray([]);

        $this->assertNull($configs->get('not_registered_key'));
    }

    #[Test]
    #[TestDox('has() はキーが登録されているかどうかを返す')]
    public function hasReflectsKeyPresence(): void
    {
        $configs = ConfigCollection::fromArray(['schedule_unit' => 1]);

        $this->assertTrue($configs->has('schedule_unit'));
        $this->assertFalse($configs->has('missing_key'));
    }

    #[Test]
    #[TestDox('値がnullのキーであってもhas()はtrueを返す(値の有無とnull自体は区別する)')]
    public function hasReturnsTrueEvenWhenStoredValueIsNull(): void
    {
        $configs = ConfigCollection::fromArray(['banner_url' => null]);

        $this->assertTrue($configs->has('banner_url'));
        $this->assertNull($configs->get('banner_url', 'fallback'));
    }

    #[Test]
    #[TestDox('任意のresolverを渡した場合、そのresolverの戻り値がget()の結果になる')]
    public function getDelegatesToCustomResolver(): void
    {
        $configs = new ConfigCollection(function (string $key, $default = null) {
            return $key === 'entry_summary_tag' ? 'on' : $default;
        });

        $this->assertSame('on', $configs->get('entry_summary_tag'));
        $this->assertSame('fallback', $configs->get('other_key', 'fallback'));
    }

    #[Test]
    #[TestDox('fromArrays() はscalarValuesをget()で、arrayValuesをgetArray()で参照できる')]
    public function fromArraysExposesScalarAndArrayValues(): void
    {
        $configs = ConfigCollection::fromArrays(
            ['banner_order' => 'sort-asc'],
            ['banner_status' => ['open', 'close', 'open']]
        );

        $this->assertSame('sort-asc', $configs->get('banner_order'));
        $this->assertSame(['open', 'close', 'open'], $configs->getArray('banner_status'));
    }

    #[Test]
    #[TestDox('getArray() は登録されていないキーに対して空配列を返す')]
    public function getArrayReturnsEmptyArrayForMissingKey(): void
    {
        $configs = ConfigCollection::fromArrays([], []);

        $this->assertSame([], $configs->getArray('banner_status'));
    }

    #[Test]
    #[TestDox('defaultResolver未指定の場合、differsFromDefault()は値が空文字でないことで判定する(後方互換のフォールバック)')]
    public function differsFromDefaultFallsBackToNonEmptyCheckWithoutDefaultResolver(): void
    {
        $configs = ConfigCollection::fromArray(['entry_headline_offset' => '10']);

        $this->assertTrue($configs->differsFromDefault('entry_headline_offset'));
        $this->assertFalse($configs->differsFromDefault('missing_key'));
    }

    #[Test]
    #[TestDox('defaultResolverを渡した場合、differsFromDefault()は実効値とシステム既定値を比較する')]
    public function differsFromDefaultComparesAgainstDefaultResolverWhenGiven(): void
    {
        // ブログ単位の上書きが無くても、システム既定値自体が非空文字であるキー
        // (例: entry_headline_order2 の既定値 'id-desc')を想定したケース。
        // 空文字判定だけでは常に誤検知するため、既定値との比較が必要になる。
        $configs = new ConfigCollection(
            function (string $key, $default = null) {
                return $key === 'entry_headline_order2' ? 'id-desc' : $default;
            },
            null,
            function (string $key) {
                return $key === 'entry_headline_order2' ? 'id-desc' : null;
            }
        );

        $this->assertFalse(
            $configs->differsFromDefault('entry_headline_order2'),
            'システム既定値のままなら「未カスタマイズ」として差分無しと判定する'
        );
    }

    #[Test]
    #[TestDox('defaultResolverを渡した場合、実効値がシステム既定値と異なればdiffersFromDefault()はtrueを返す')]
    public function differsFromDefaultReturnsTrueWhenEffectiveValueOverridesDefault(): void
    {
        $configs = new ConfigCollection(
            function (string $key, $default = null) {
                return $key === 'entry_headline_offset' ? '10' : $default;
            },
            null,
            function (string $key) {
                return $key === 'entry_headline_offset' ? '0' : null;
            }
        );

        $this->assertTrue($configs->differsFromDefault('entry_headline_offset'));
    }

    #[Test]
    #[TestDox('fromArray() で構築した場合、getArray()はget()の値を単一要素配列として返す')]
    public function getArrayFallsBackToScalarWhenNoArrayResolverGiven(): void
    {
        $configs = ConfigCollection::fromArray(['schedule_unit' => 1]);

        $this->assertSame([1], $configs->getArray('schedule_unit'));
        $this->assertSame([], $configs->getArray('missing_key'));
    }
}
