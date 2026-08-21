<?php

namespace Acms\Plugins\DeprecatedModuleMigration\POST;

use ACMS_POST;
use Acms\Plugins\DeprecatedModuleMigration\HandlerTrait;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationPresenter;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Module;

/**
 * 対象モジュールの差分プレビューを返すJSON API。
 *
 * フォームからは `name="ACMS_POST_ModuleMigrationDiff"` で呼び出す。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::diff()
 */
class ModuleMigrationDiff extends ACMS_POST
{
    use HandlerTrait;

    public function post()
    {
        try {
            $blogId = (int) $this->Post->get('blogId', BID);
            $moduleId = (int) $this->Post->get('moduleId');
            if (!Module::canUpdate($blogId)) {
                throw new \RuntimeException('権限がありません。');
            }

            $manager = new ModuleMigrationManager();
            $module = $this->findTargetModule($manager, $blogId, $moduleId);
            $diff = $manager->diff($module);

            $presenter = new ModuleMigrationPresenter();
            Common::responseJson([
                'success' => true,
                'diff' => $presenter->presentDiff($diff),
            ]);
        } catch (\Throwable $e) {
            Common::responseJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
