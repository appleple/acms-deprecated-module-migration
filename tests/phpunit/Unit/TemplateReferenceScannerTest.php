<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit;

use Acms\Plugins\DeprecatedModuleMigration\TemplateReferenceScanner;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\TemplateReferenceScanner
 */
#[CoversClass(TemplateReferenceScanner::class)]
class TemplateReferenceScannerTest extends TestCase
{
    private string $themeDir;
    private TemplateReferenceScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new TemplateReferenceScanner();
        $this->themeDir = sys_get_temp_dir() . '/acms_template_scan_' . uniqid('', true);
        mkdir($this->themeDir . '/sub', 0777, true);
    }

    protected function tearDown(): void
    {
        // Acms\TestingFramework\TestCase が提供する再帰削除ヘルパーをそのまま利用する
        // (同名のメソッドを本クラスで再宣言すると、親の protected を private で
        // オーバーライドすることになり PHP の可視性ルール違反で致命的エラーになる)。
        $this->removeDirectory($this->themeDir);
        parent::tearDown();
    }

    #[Test]
    #[TestDox('module_nameとmodule_identifierが一致するBEGIN_MODULEタグを検出する')]
    public function detectsMatchingBeginModuleTag(): void
    {
        file_put_contents(
            $this->themeDir . '/index.html',
            "line1\n<!-- BEGIN_MODULE Plugin_Schedule id=\"mod_schedule_top\" -->\nline3\n"
        );

        $result = $this->scanner->scan($this->themeDir, 'Plugin_Schedule', 'mod_schedule_top');

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]->lineNumber);
        $this->assertStringContainsString('Plugin_Schedule', $result[0]->matchedLine);
    }

    #[Test]
    #[TestDox('module_identifierが異なるタグは検出しない')]
    public function doesNotMatchDifferentIdentifier(): void
    {
        file_put_contents(
            $this->themeDir . '/index.html',
            "<!-- BEGIN_MODULE Plugin_Schedule id=\"other_identifier\" -->\n"
        );

        $result = $this->scanner->scan($this->themeDir, 'Plugin_Schedule', 'mod_schedule_top');

        $this->assertSame([], $result);
    }

    #[Test]
    #[TestDox('サブディレクトリのテンプレートも再帰的に走査する')]
    public function scansSubdirectoriesRecursively(): void
    {
        file_put_contents(
            $this->themeDir . '/sub/entry.html',
            "<!-- BEGIN_MODULE Entry_Headline id=\"mod_headline\" -->\n"
        );

        $result = $this->scanner->scan($this->themeDir, 'Entry_Headline', 'mod_headline');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('sub/entry.html', $result[0]->filePath);
    }

    #[Test]
    #[TestDox('テーマディレクトリが存在しない場合は空配列を返す(検出不能)')]
    public function returnsEmptyArrayWhenThemeDirectoryDoesNotExist(): void
    {
        $result = $this->scanner->scan($this->themeDir . '/does-not-exist', 'Plugin_Schedule', 'mod_schedule_top');

        $this->assertSame([], $result);
    }

    #[Test]
    #[TestDox('同一ファイル内の複数箇所を全て検出する')]
    public function detectsMultipleOccurrencesInSameFile(): void
    {
        file_put_contents(
            $this->themeDir . '/index.html',
            "<!-- BEGIN_MODULE Plugin_Schedule id=\"mod_a\" -->\n"
            . "middle\n"
            . "<!-- BEGIN_MODULE Plugin_Schedule id=\"mod_a\" -->\n"
        );

        $result = $this->scanner->scan($this->themeDir, 'Plugin_Schedule', 'mod_a');

        $this->assertCount(2, $result);
        $this->assertSame([1, 3], array_map(static fn ($r) => $r->lineNumber, $result));
    }
}
