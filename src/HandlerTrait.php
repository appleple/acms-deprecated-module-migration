<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

use Acms\Services\Facades\Config;

/**
 * POST\* ハンドラ間で共通の「対象モジュール解決」「テンプレート参照検出」をまとめたトレイト。
 * CSRF検証・権限チェック・レスポンス整形はハンドラ本体側に残す。
 */
trait HandlerTrait
{
    private function findTargetModule(ModuleMigrationManager $manager, int $blogId, int $moduleId): ModuleRow
    {
        foreach ($manager->detect($blogId) as $module) {
            if ($module->moduleId === $moduleId) {
                return $module;
            }
        }

        throw new \RuntimeException('対象モジュールが見つかりません。');
    }

    /**
     * @return TemplateReference[]
     */
    private function findTemplateReferences(ModuleMigrationManager $manager, ModuleRow $module, int $blogId): array
    {
        try {
            $theme = Config::loadBlogConfig($blogId)->get('theme');
            if ($theme === '') {
                return [];
            }
            $themeDir = SCRIPT_DIR . THEMES_DIR . $theme;
            if (!is_dir($themeDir)) {
                return [];
            }

            return $manager->scanTemplateReferences($module, $themeDir);
        } catch (\Throwable $e) {
            // テーマディレクトリの解決に失敗した場合は「自動検出不可」として空配列を返す
            // (detailed-design.html「3. テンプレート参照の検出」)
            return [];
        }
    }
}
