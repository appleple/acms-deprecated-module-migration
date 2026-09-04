<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\MigrationDiff;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem;
use Acms\Plugins\DeprecatedModuleMigration\MigrationResult;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationPresenter;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Snapshot\SnapshotSummary;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\ScheduleMigrationStrategy;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationPresenter
 */
#[CoversClass(ModuleMigrationPresenter::class)]
class ModuleMigrationPresenterTest extends TestCase
{
    private ModuleMigrationPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new ModuleMigrationPresenter();
    }

    #[Test]
    #[TestDox('presentModule()はStrategy解決結果を含むJSON化可能な配列を返す')]
    public function presentModuleIncludesStrategyInfo(): void
    {
        $module = new ModuleRow(1, 'mod_schedule', 'Plugin_Schedule', 1, 'local');
        $strategy = new ScheduleMigrationStrategy();

        $result = $this->presenter->presentModule($module, $strategy);

        $this->assertSame(1, $result['moduleId']);
        $this->assertSame('mod_schedule', $result['moduleIdentifier']);
        $this->assertSame('Plugin_Schedule', $result['moduleName']);
        $this->assertSame('Schedule', $result['targetModuleName']);
        $this->assertSame('A', $result['rank']);
    }

    #[Test]
    #[TestDox('presentModule()はStrategyがnullの場合、targetModuleName/rankをnullにする')]
    public function presentModuleHandlesNullStrategy(): void
    {
        $module = new ModuleRow(1, 'mod_x', 'Unknown_Module', 1, 'local');

        $result = $this->presenter->presentModule($module, null);

        $this->assertNull($result['targetModuleName']);
        $this->assertNull($result['rank']);
    }

    #[Test]
    #[TestDox('presentModule()はblogNameを渡すとそのまま含める')]
    public function presentModuleIncludesGivenBlogName(): void
    {
        $module = new ModuleRow(1, 'mod_schedule', 'Plugin_Schedule', 5, 'local');

        $result = $this->presenter->presentModule($module, null, '子ブログ');

        $this->assertSame('子ブログ', $result['blogName']);
    }

    #[Test]
    #[TestDox('presentModule()はblogNameを省略した場合nullになる')]
    public function presentModuleDefaultsBlogNameToNull(): void
    {
        $module = new ModuleRow(1, 'mod_schedule', 'Plugin_Schedule', 5, 'local');

        $result = $this->presenter->presentModule($module, null);

        $this->assertNull($result['blogName']);
    }

    #[Test]
    #[TestDox('presentDiff()は項目・警告・再現不可理由・isBlockedを含む配列を返す')]
    public function presentDiffIncludesAllFields(): void
    {
        $item = new MigrationDiffItem('entry_summary_fulltext', 'off', 'on', null);
        $diff = new MigrationDiff('Entry_Headline', 'Entry_Summary', [$item], ['警告A'], ['再現不可B']);

        $result = $this->presenter->presentDiff($diff);

        $this->assertSame('Entry_Headline', $result['sourceModuleName']);
        $this->assertSame('Entry_Summary', $result['targetModuleName']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('entry_summary_fulltext', $result['items'][0]['targetConfigKey']);
        $this->assertTrue($result['items'][0]['willChangeIfUnset']);
        $this->assertSame(['警告A'], $result['warnings']);
        $this->assertSame(['再現不可B'], $result['unsupportedReasons']);
        $this->assertTrue($result['isBlocked']);
    }

    #[Test]
    #[TestDox('presentResult()は適用結果の主要フィールドを含む配列を返す')]
    public function presentResultIncludesMainFields(): void
    {
        $result = new MigrationResult(1, 'Plugin_Schedule', 'Schedule', ['k' => 'v'], ['note']);

        $presented = $this->presenter->presentResult($result);

        $this->assertSame(1, $presented['moduleId']);
        $this->assertSame('Plugin_Schedule', $presented['oldModuleName']);
        $this->assertSame('Schedule', $presented['newModuleName']);
        $this->assertSame(['k' => 'v'], $presented['writtenConfig']);
        $this->assertSame(['note'], $presented['notes']);
    }

    #[Test]
    #[TestDox('presentSnapshot()はスナップショット要約の全フィールドを含む配列を返す')]
    public function presentSnapshotIncludesAllFields(): void
    {
        $summary = new SnapshotSummary(
            snapshotId: 3,
            moduleId: 10,
            moduleName: 'Plugin_Schedule',
            snapshotDatetime: '2026-08-30 12:00:00',
            userId: 1,
            blogId: 5
        );

        $result = $this->presenter->presentSnapshot($summary, '子ブログ');

        $this->assertSame(3, $result['snapshotId']);
        $this->assertSame(10, $result['moduleId']);
        $this->assertSame('Plugin_Schedule', $result['moduleName']);
        $this->assertSame('2026-08-30 12:00:00', $result['snapshotDatetime']);
        $this->assertSame(1, $result['userId']);
        $this->assertSame(5, $result['blogId']);
        $this->assertSame('子ブログ', $result['blogName']);
    }

    #[Test]
    #[TestDox('presentSnapshot()はblogNameを省略した場合nullになる')]
    public function presentSnapshotDefaultsBlogNameToNull(): void
    {
        $summary = new SnapshotSummary(
            snapshotId: 3,
            moduleId: 10,
            moduleName: 'Plugin_Schedule',
            snapshotDatetime: '2026-08-30 12:00:00',
            userId: 1,
            blogId: 5
        );

        $result = $this->presenter->presentSnapshot($summary);

        $this->assertNull($result['blogName']);
    }
}
