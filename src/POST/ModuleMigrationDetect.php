<?php

namespace Acms\Plugins\DeprecatedModuleMigration\POST;

use ACMS_POST;
use Acms\Plugins\DeprecatedModuleMigration\HandlerTrait;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationPresenter;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Module;

/**
 * 対象ブログ内の非推奨モジュールを一覧表示するためのJSON API。
 *
 * フォームからは `name="ACMS_POST_ModuleMigrationDetect"` で呼び出す。
 * `includeChildren=1` を渡すと、対象ブログ配下の子孫ブログ全階層(公開状態かつ
 * 編集権限があるもの)のモジュールも合わせて列挙する
 * (ルートブログから配下のブログ横断で一括移行するためのオプション)。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::detect()
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::descendantBlogIds()
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

            $targetBlogIds = [$blogId];
            if ($this->Post->get('includeChildren') === '1') {
                // 子孫ブログの編集権限は対象ブログの権限チェックだけでは保証されないため、
                // ブログごとに個別にcanUpdate()を通ったものだけを対象に加える
                // (ModuleMigrationManagerのdocblock「呼び出し側で対象ブログへの
                // 編集権限チェックを別途行うこと」に対応)。
                foreach ($manager->descendantBlogIds($blogId) as $descendantBlogId) {
                    if (Module::canUpdate($descendantBlogId)) {
                        $targetBlogIds[] = $descendantBlogId;
                    }
                }
            }

            $blogNames = $manager->blogNames($targetBlogIds);

            $modules = [];
            foreach ($targetBlogIds as $targetBlogId) {
                foreach ($manager->detect($targetBlogId) as $module) {
                    $strategy = $manager->resolveStrategy($module->moduleName);
                    $modules[] = $presenter->presentModule($module, $strategy, $blogNames[$targetBlogId] ?? null);
                }
            }

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
