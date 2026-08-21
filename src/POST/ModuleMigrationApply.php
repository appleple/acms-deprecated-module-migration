<?php

namespace Acms\Plugins\DeprecatedModuleMigration\POST;

use ACMS_POST;
use Acms\Plugins\DeprecatedModuleMigration\HandlerTrait;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationPresenter;
use Acms\Services\Facades\Common;
use Acms\Services\Facades\Module;

/**
 * 対象モジュールの移行を適用するJSON API。適用前スナップショットを保存したうえで実行する。
 *
 * フォームからは `name="ACMS_POST_ModuleMigrationApply"` で呼び出す。
 *
 * V1のスコープでは、差分はサーバー側で再計算した最新の値を用いる(クライアントから送られた
 * 差分内容をそのまま信用しない)。ランクB個別項目の上書き承認UIは将来の拡張とする。
 *
 * Banner→Media_Banner(ランクC)は既定で無効化され、明示的なオプトインが必要
 * (basic-design.html「5. スコープ外・非対応事項」)。クライアントUIの導線だけに頼ると
 * APIを直接叩いてオプトインをバイパスできてしまうため、ここでも `optIn=1` を必須にする。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationManager::applyWithSnapshot()
 */
class ModuleMigrationApply extends ACMS_POST
{
    use HandlerTrait;

    private const OPT_IN_REQUIRED_MODULE_NAMES = ['Banner'];

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
            if (
                in_array($module->moduleName, self::OPT_IN_REQUIRED_MODULE_NAMES, true)
                && $this->Post->get('optIn') !== '1'
            ) {
                throw new \RuntimeException(
                    'この移行は実データ移行(画像のメディアライブラリ登録)を伴うため、'
                        . '明示的なオプトインが必要です。'
                );
            }

            $diff = $manager->diff($module);
            if ($diff->isBlocked()) {
                throw new \RuntimeException('この移行は自動適用できません。手動での対応が必要です。');
            }

            /** @var int|null $suid */
            $suid = SUID;
            $outcome = $manager->applyWithSnapshot($module, $diff, (int) $suid);

            $presenter = new ModuleMigrationPresenter();
            Common::responseJson([
                'success' => true,
                'result' => $presenter->presentResult($outcome['result']),
                'snapshotId' => $outcome['snapshotId'],
            ]);
        } catch (\Throwable $e) {
            Common::responseJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
