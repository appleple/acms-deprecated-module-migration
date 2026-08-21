<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * テーマ内のテンプレートファイルを走査し、指定した module_name + module_identifier の
 * 組み合わせに一致する BEGIN_MODULE タグの設置箇所を検出する(読み取り専用、書き換えは行わない)。
 *
 * detailed-design.html「設計原則: テンプレート参照の検出」参照。
 */
final class TemplateReferenceScanner
{
    /**
     * @return TemplateReference[]
     */
    public function scan(string $themeDir, string $moduleName, string $moduleIdentifier): array
    {
        if (!is_dir($themeDir)) {
            return [];
        }

        $pattern = sprintf(
            '/BEGIN_MODULE\s+%s\s+id=["\']%s["\']/',
            preg_quote($moduleName, '/'),
            preg_quote($moduleIdentifier, '/')
        );

        $references = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($themeDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isDir()) {
                continue;
            }

            $lines = @file($file->getPathname());
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $index => $line) {
                if (preg_match($pattern, $line) === 1) {
                    $references[] = new TemplateReference($file->getPathname(), $index + 1, trim($line));
                }
            }
        }

        return $references;
    }
}
