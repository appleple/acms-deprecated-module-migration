<?php

namespace Acms\Plugins\DeprecatedModuleMigration\POST;

use ACMS_POST;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Module;

/**
 * スナップショットの内容へロールバックするJSON API。
 *
 * フォームからは `name="ACMS_POST_ModuleMigrationRollback"` で呼び出す。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::rollback()
 */
class ModuleMigrationRollback extends ACMS_POST
{
    public function post()
    {
        try {
            $blogId = (int) $this->Post->get('blogId', BID);
            $snapshotId = (int) $this->Post->get('snapshotId');
            if (!Module::canUpdate($blogId)) {
                throw new \RuntimeException('権限がありません。');
            }

            // rollback()側でスナップショットの所有ブログとblogIdの一致を必須にしている
            // (他ブログのスナップショットIDを渡されても復元できないようにするため)。
            $manager = new ModuleMigrationManager();
            $manager->rollback($snapshotId, $blogId);

            Common::responseJson(['success' => true]);
        } catch (\Throwable $e) {
            Common::responseJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
