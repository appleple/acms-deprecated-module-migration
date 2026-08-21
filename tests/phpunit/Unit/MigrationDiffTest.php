<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\MigrationDiff;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\MigrationDiff
 */
#[CoversClass(MigrationDiff::class)]
class MigrationDiffTest extends TestCase
{
    #[Test]
    #[TestDox('itemsRequiringExplicitWrite()は明示書き込みが必要な項目のみを返す')]
    public function itemsRequiringExplicitWriteFiltersOnlyChangedItems(): void
    {
        $unchanged = new MigrationDiffItem('entry_summary_order', 'datetime-desc', 'datetime-desc');
        $changed = new MigrationDiffItem('entry_summary_fulltext', 'off', 'on');

        $diff = new MigrationDiff(
            sourceModuleName: 'Entry_Headline',
            targetModuleName: 'Entry_Summary',
            items: [$unchanged, $changed]
        );

        $this->assertSame([$changed], $diff->itemsRequiringExplicitWrite());
    }

    #[Test]
    #[TestDox('unsupportedReasonsが空の場合、isBlocked()はfalseを返す')]
    public function isBlockedFalseWhenNoUnsupportedReasons(): void
    {
        $diff = new MigrationDiff('Plugin_Schedule', 'Schedule', []);

        $this->assertFalse($diff->isBlocked());
        $this->assertSame([], $diff->warnings);
        $this->assertSame([], $diff->unsupportedReasons);
    }

    #[Test]
    #[TestDox('unsupportedReasonsが1件でもあれば、isBlocked()はtrueを返す')]
    public function isBlockedTrueWhenUnsupportedReasonsExist(): void
    {
        $diff = new MigrationDiff(
            sourceModuleName: 'Category_EntryList',
            targetModuleName: 'Category_EntrySummary',
            items: [],
            warnings: ['テンプレートの手動書き換えが必須です'],
            unsupportedReasons: ['category_entry_list_entry_active_category は再現できません']
        );

        $this->assertTrue($diff->isBlocked());
        $this->assertSame(['テンプレートの手動書き換えが必須です'], $diff->warnings);
    }
}
