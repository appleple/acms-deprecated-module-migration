<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleRow
 */
#[CoversClass(ModuleRow::class)]
class ModuleRowTest extends TestCase
{
    #[Test]
    #[TestDox('コンストラクタに渡した値をそのまま各プロパティから参照できる')]
    public function exposesConstructorValuesAsProperties(): void
    {
        $module = new ModuleRow(
            moduleId: 42,
            moduleIdentifier: 'mod_headline_top',
            moduleName: 'Entry_Headline',
            moduleBlogId: 1,
            moduleScope: 'local',
            scopeColumns: ['module_cid_scope' => 'local']
        );

        $this->assertSame(42, $module->moduleId);
        $this->assertSame('mod_headline_top', $module->moduleIdentifier);
        $this->assertSame('Entry_Headline', $module->moduleName);
        $this->assertSame(1, $module->moduleBlogId);
        $this->assertSame('local', $module->moduleScope);
        $this->assertSame(['module_cid_scope' => 'local'], $module->scopeColumns);
    }

    #[Test]
    #[TestDox('scopeColumnsを省略した場合は空配列になる')]
    public function scopeColumnsDefaultsToEmptyArray(): void
    {
        $module = new ModuleRow(
            moduleId: 1,
            moduleIdentifier: 'mod_a',
            moduleName: 'Plugin_Schedule',
            moduleBlogId: 1,
            moduleScope: 'local'
        );

        $this->assertSame([], $module->scopeColumns);
    }

    #[Test]
    #[TestDox('isGlobalScope()はmoduleScopeがglobalのときtrueを返す')]
    public function isGlobalScopeReflectsScopeValue(): void
    {
        $global = new ModuleRow(1, 'mod_a', 'Banner', 1, 'global');
        $local = new ModuleRow(2, 'mod_b', 'Banner', 1, 'local');

        $this->assertTrue($global->isGlobalScope());
        $this->assertFalse($local->isGlobalScope());
    }
}
