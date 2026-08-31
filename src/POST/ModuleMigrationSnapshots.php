<?php

namespace Acms\Plugins\DeprecatedModuleMigration\POST;

use ACMS_POST;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationPresenter;
use Acms\Plugins\DeprecatedModuleMigration\Snapshot\SnapshotSummary;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Module;

/**
 * 対象ブログの移行履歴(スナップショット)を新しい順に一覧表示するためのJSON API。
 *
 * フォームからは `name="ACMS_POST_ModuleMigrationSnapshots"` で呼び出す。
 * ページ遷移などで適用直後のロールバック導線(ACMS_POST_ModuleMigrationApplyのレスポンス)を
 * 失っても、この一覧から任意のスナップショットへロールバックできるようにする。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::listSnapshots()
 */
class ModuleMigrationSnapshots extends ACMS_POST
{
    public function post()
    {
        try {
            $blogId = (int) $this->Post->get('blogId', BID);
            if (!Module::canUpdate($blogId)) {
                throw new \RuntimeException('権限がありません。');
            }

            $manager = new ModuleMigrationManager();
            $presenter = new ModuleMigrationPresenter();
            $snapshots = array_map(
                fn (SnapshotSummary $summary): array => $presenter->presentSnapshot($summary),
                $manager->listSnapshots($blogId)
            );

            Common::responseJson([
                'success' => true,
                'snapshots' => $snapshots,
            ]);
        } catch (\Throwable $e) {
            Common::responseJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
