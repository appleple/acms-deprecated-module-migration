<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\MigrationResult;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\MigrationResult
 */
#[CoversClass(MigrationResult::class)]
class MigrationResultTest extends TestCase
{
    #[Test]
    #[TestDox('コンストラクタに渡した値をそのまま各プロパティから参照できる')]
    public function exposesConstructorValuesAsProperties(): void
    {
        $result = new MigrationResult(
            moduleId: 10,
            oldModuleName: 'Plugin_Schedule',
            newModuleName: 'Schedule',
            writtenConfig: ['entry_summary_fulltext' => 'off'],
            notes: ['テンプレート側の書き換えが必要です']
        );

        $this->assertSame(10, $result->moduleId);
        $this->assertSame('Plugin_Schedule', $result->oldModuleName);
        $this->assertSame('Schedule', $result->newModuleName);
        $this->assertSame(['entry_summary_fulltext' => 'off'], $result->writtenConfig);
        $this->assertSame(['テンプレート側の書き換えが必要です'], $result->notes);
    }

    #[Test]
    #[TestDox('writtenConfigとnotesを省略した場合は空配列になる')]
    public function writtenConfigAndNotesDefaultToEmptyArray(): void
    {
        $result = new MigrationResult(1, 'Plugin_Schedule', 'Schedule');

        $this->assertSame([], $result->writtenConfig);
        $this->assertSame([], $result->notes);
    }
}
