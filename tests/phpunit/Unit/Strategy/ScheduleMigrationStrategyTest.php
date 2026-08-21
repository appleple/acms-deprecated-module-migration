<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\ScheduleMigrationStrategy;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\ScheduleMigrationStrategy
 */
#[CoversClass(ScheduleMigrationStrategy::class)]
class ScheduleMigrationStrategyTest extends TestCase
{
    private ScheduleMigrationStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ScheduleMigrationStrategy();
    }

    #[Test]
    #[TestDox('supports()はPlugin_Scheduleのみを返す')]
    public function supportsReturnsPluginSchedule(): void
    {
        $this->assertSame(['Plugin_Schedule'], $this->strategy->supports());
    }

    #[Test]
    #[TestDox('targetModuleName()は常にScheduleを返す')]
    public function targetModuleNameIsAlwaysSchedule(): void
    {
        $this->assertSame('Schedule', $this->strategy->targetModuleName('Plugin_Schedule'));
    }

    #[Test]
    #[TestDox('rank()はAを返す')]
    public function rankIsA(): void
    {
        $this->assertSame('A', $this->strategy->rank());
    }

    #[Test]
    #[TestDox('diff()はconfigキーが完全一致するため明示書き込みが必要な項目を持たない')]
    public function diffHasNoItemsRequiringExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_schedule', 'Plugin_Schedule', 1, 'local');
        $configs = ConfigCollection::fromArray([
            'schedule_unit' => '2',
            'schedule_week_start' => '1',
        ]);

        $diff = $this->strategy->diff($module, $configs);

        $this->assertSame('Plugin_Schedule', $diff->sourceModuleName);
        $this->assertSame('Schedule', $diff->targetModuleName);
        $this->assertSame([], $diff->itemsRequiringExplicitWrite());
        $this->assertFalse($diff->isBlocked());
    }

    #[Test]
    #[TestDox('diff()はサポート外のmodule_nameに対してInvalidArgumentExceptionをスローする')]
    public function diffThrowsForUnsupportedModuleName(): void
    {
        $module = new ModuleRow(1, 'mod_x', 'Entry_Headline', 1, 'local');

        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->diff($module, ConfigCollection::fromArray([]));
    }
}
