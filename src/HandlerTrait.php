<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * POST\* ハンドラ間で共通の「対象モジュール解決」をまとめたトレイト。
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
}
