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
 * `includeChildren=1` を渡すと、対象ブログ配下の子孫ブログ全階層(公開状態かつ編集権限が
 * あるもの)のスナップショットも合わせて列挙する(ModuleMigrationDetectの
 * 「配下のブログを含める」オプションと対象範囲を揃えるため)。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::listSnapshots()
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::descendantBlogIds()
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

            $targetBlogIds = [$blogId];
            if ($this->Post->get('includeChildren') === '1') {
                // 子孫ブログの編集権限は対象ブログの権限チェックだけでは保証されないため、
                // ブログごとに個別にcanUpdate()を通ったものだけを対象に加える
                // (ModuleMigrationDetectと同じ理由)。
                foreach ($manager->descendantBlogIds($blogId) as $descendantBlogId) {
                    if (Module::canUpdate($descendantBlogId)) {
                        $targetBlogIds[] = $descendantBlogId;
                    }
                }
            }

            $blogNames = $manager->blogNames($targetBlogIds);

            $snapshots = array_map(
                fn (SnapshotSummary $summary): array => $presenter->presentSnapshot(
                    $summary,
                    $blogNames[$summary->blogId] ?? null
                ),
                $manager->listSnapshots($targetBlogIds)
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
