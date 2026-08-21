<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\Exceptions\UnsupportedMigrationException;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\UserSearchAdvisoryStrategy;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\UserSearchAdvisoryStrategy
 */
#[CoversClass(UserSearchAdvisoryStrategy::class)]
class UserSearchAdvisoryStrategyTest extends TestCase
{
    private UserSearchAdvisoryStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new UserSearchAdvisoryStrategy();
    }

    #[Test]
    #[TestDox('supports()はUser_Profileのみを返す')]
    public function supportsReturnsUserProfile(): void
    {
        $this->assertSame(['User_Profile'], $this->strategy->supports());
    }

    #[Test]
    #[TestDox('rank()はCを返す')]
    public function rankIsC(): void
    {
        $this->assertSame('C', $this->strategy->rank());
    }

    #[Test]
    #[TestDox('targetModuleName()は常にUser_Searchを返す')]
    public function targetModuleNameIsAlwaysUserSearch(): void
    {
        $this->assertSame('User_Search', $this->strategy->targetModuleName('User_Profile'));
    }

    #[Test]
    #[TestDox('diff()は自動適用不可としてisBlocked()がtrueになり、config項目を持たない')]
    public function diffIsAlwaysBlockedWithoutItems(): void
    {
        $module = new ModuleRow(1, 'mod_profile', 'User_Profile', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray([]));

        $this->assertTrue($diff->isBlocked());
        $this->assertSame([], $diff->items);
        $this->assertNotEmpty($diff->unsupportedReasons);
    }

    #[Test]
    #[TestDox('diff()のwarningsには手動移行チェックリストの主要観点が含まれる')]
    public function diffWarningsIncludeManualMigrationChecklist(): void
    {
        $module = new ModuleRow(1, 'mod_profile', 'User_Profile', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray([]));

        $joined = implode(' / ', $diff->warnings);
        $this->assertStringContainsString('user_search_status', $joined);
        $this->assertStringContainsString('mail_magaginze', $joined);
    }

    #[Test]
    #[TestDox('apply()は常にUnsupportedMigrationExceptionをスローする')]
    public function applyAlwaysThrows(): void
    {
        $module = new ModuleRow(1, 'mod_profile', 'User_Profile', 1, 'local');
        $configs = ConfigCollection::fromArray([]);
        $diff = $this->strategy->diff($module, $configs);

        $this->expectException(UnsupportedMigrationException::class);
        $this->strategy->apply($module, $configs, $diff);
    }

    #[Test]
    #[TestDox('diff()はサポート外のmodule_nameに対してInvalidArgumentExceptionをスローする')]
    public function diffThrowsForUnsupportedModuleName(): void
    {
        $module = new ModuleRow(1, 'mod_x', 'User_Search', 1, 'local');

        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->diff($module, ConfigCollection::fromArray([]));
    }
}
