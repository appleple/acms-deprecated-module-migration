<?php

namespace Acms\Plugins\DeprecatedModuleMigration\POST;

use ACMS_POST;
use Acms\Plugins\DeprecatedModuleMigration\HandlerTrait;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationPresenter;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Module;

/**
 * 対象ブログ内の非推奨モジュールを一覧表示するためのJSON API。
 *
 * フォームからは `name="ACMS_POST_ModuleMigrationDetect"` で呼び出す。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::detect()
 */
class ModuleMigrationDetect extends ACMS_POST
{
    use HandlerTrait;

    public function post()
    {
        try {
            $blogId = (int) $this->Post->get('blogId', BID);
            if (!Module::canUpdate($blogId)) {
                throw new \RuntimeException('権限がありません。');
            }

            $manager = new ModuleMigrationManager();
            $presenter = new ModuleMigrationPresenter();
            $modules = array_map(
                function (ModuleRow $module) use ($manager, $presenter): array {
                    $strategy = $manager->resolveStrategy($module->moduleName);
                    return $presenter->presentModule($module, $strategy);
                },
                $manager->detect($blogId)
            );

            Common::responseJson([
                'success' => true,
                'modules' => $modules,
            ]);
        } catch (\Throwable $e) {
            Common::responseJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
