<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Integration\Services;

use Acms\Plugins\DeprecatedModuleMigration\Services\PluginSchemaMigrator;
use Acms\Services\Facades\Database as DB;
use Acms\Services\Facades\Logger;
use Acms\Services\Update\Database\SchemaDefinitions;
use Acms\TestingFramework\DatabaseTestCase;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Services\PluginSchemaMigrator
 */
#[CoversClass(PluginSchemaMigrator::class)]
final class PluginSchemaMigratorTest extends DatabaseTestCase
{
    private string $schemaDir;
    private string $table;
    private TestHandler $logHandler;

    protected function setUpDatabase(): void
    {
        $this->schemaDir = __DIR__ . '/../../../../src/schema';
        $definitions = SchemaDefinitions::fromYaml($this->schemaDir);
        $this->table = array_key_first($definitions->schema);

        $this->logHandler = new TestHandler();
        Logger::getInstance()->pushHandler($this->logHandler);

        // DDL(DROP/CREATE/ALTER)は暗黙コミットされトランザクションロールバックの対象外になるため、
        // テスト内でテーブルを一旦削除し、tearDown で必ず migrate() により復元する。
        DB::query(['sql' => "DROP TABLE IF EXISTS `{$this->table}`", 'params' => []], 'exec');
    }

    protected function tearDown(): void
    {
        // DDLで削除したテーブルを他のテストのために必ず復元する
        (new PluginSchemaMigrator())->migrate($this->schemaDir);
        parent::tearDown();
    }

    #[Test]
    #[TestDox('テーブル新規作成を伴うmigrate()は、作成直後のインデックス重複によるエラーログを出さない')]
    public function migrateDoesNotLogIndexCreationFailureRightAfterTableCreation(): void
    {
        (new PluginSchemaMigrator())->migrate($this->schemaDir);

        $this->assertFalse(
            $this->logHandler->hasWarningThatContains('インデックスの作成に失敗しました'),
            'テーブル新規作成直後は、CREATE TABLE時に含めたインデックスが二重にALTER TABLEされ、'
                . 'Duplicate key name等のエラーが握りつぶされてログに記録されてはならない'
        );
    }
}
