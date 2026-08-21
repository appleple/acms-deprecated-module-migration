<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem
 */
#[CoversClass(MigrationDiffItem::class)]
class MigrationDiffItemTest extends TestCase
{
    #[Test]
    #[TestDox('旧実効値と新既定値が異なる場合、requiresExplicitWrite()はtrueを返す')]
    public function requiresExplicitWriteWhenValuesDiffer(): void
    {
        $item = new MigrationDiffItem(
            targetConfigKey: 'entry_summary_fulltext',
            sourceEffectiveValue: 'off',
            targetDefaultValue: 'on',
            sourceConfigKey: null
        );

        $this->assertTrue($item->requiresExplicitWrite());
        $this->assertTrue($item->willChangeIfUnset());
    }

    #[Test]
    #[TestDox('旧実効値と新既定値が一致する場合、requiresExplicitWrite()はfalseを返す')]
    public function doesNotRequireExplicitWriteWhenValuesMatch(): void
    {
        $item = new MigrationDiffItem(
            targetConfigKey: 'entry_summary_order',
            sourceEffectiveValue: 'datetime-desc',
            targetDefaultValue: 'datetime-desc',
            sourceConfigKey: 'entry_headline_order'
        );

        $this->assertFalse($item->requiresExplicitWrite());
        $this->assertFalse($item->willChangeIfUnset());
    }

    #[Test]
    #[TestDox('型が異なるが文字列表現が一致する値(6と"6")は一致として扱う')]
    public function treatsLooseNumericStringEquivalentsAsEqual(): void
    {
        $item = new MigrationDiffItem(
            targetConfigKey: 'entry_summary_limit',
            sourceEffectiveValue: 6,
            targetDefaultValue: '6',
            sourceConfigKey: 'entry_list_limit'
        );

        $this->assertFalse($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('sourceConfigKeyを省略した場合はnullになる(コード固定値からの移行を表す)')]
    public function sourceConfigKeyDefaultsToNull(): void
    {
        $item = new MigrationDiffItem(
            targetConfigKey: 'entry_summary_tag',
            sourceEffectiveValue: 'off',
            targetDefaultValue: 'on'
        );

        $this->assertNull($item->sourceConfigKey);
    }
}
