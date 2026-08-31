<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

use ACMS_App;
use Acms\Plugins\DeprecatedModuleMigration\Services\PluginSchemaMigrator;
use Acms\Services\Common\InjectTemplate;

/**
 * 非推奨モジュール自動移行プラグインの登録クラス。
 *
 * Layout:
 * The production code lives in `src/`, and `src/` is what gets deployed as the plugin root
 * (`extension/plugins/DeprecatedModuleMigration/` is a symlink/mount to this repository's `src/`).
 * The core autoloader resolves `Acms\Plugins\ → extension/plugins/`, so
 * `Acms\Plugins\DeprecatedModuleMigration\ServiceProvider` maps to
 * `extension/plugins/DeprecatedModuleMigration/ServiceProvider.php` = `src/ServiceProvider.php`.
 */
class ServiceProvider extends ACMS_App
{
    /**
     * @var string
     */
    public $version = '0.0.1';

    /**
     * @var string
     */
    public $name = 'DeprecatedModuleMigration';

    /**
     * @var string
     */
    public $author = 'appleple';

    /**
     * @var bool
     */
    public $module = false;

    /**
     * @var false|string
     */
    public $menu = 'module_migration_index';

    /**
     * @var string
     */
    public $desc = '非推奨モジュール(Plugin_Schedule/Entry_Headline/Entry_List/Entry_Photo/Category_EntryList/Banner/User_Profile)から代替モジュールへの設定移行を支援するプラグインです。';

    /**
     * プラグインの初期処理(有効な全リクエストで実行される)。
     *
     * @return void
     */
    public function init()
    {
        $inject = InjectTemplate::singleton();

        if (defined('ADMIN') && ADMIN === 'app_' . $this->menu) {
            $inject->add('admin-main', PLUGIN_DIR . 'DeprecatedModuleMigration/template/admin/main.html');
            $inject->add('admin-topicpath', PLUGIN_DIR . 'DeprecatedModuleMigration/template/admin/topicpath.html');
        }
    }

    /**
     * インストール前の環境チェック処理。
     *
     * @return bool
     */
    public function checkRequirements()
    {
        return true;
    }

    /**
     * インストール時の処理。module_migration_snapshotテーブルを作成する。
     *
     * @return void
     */
    public function install()
    {
        (new PluginSchemaMigrator())->migrate(__DIR__ . '/schema');
    }

    /**
     * アンインストール時の処理。
     *
     * スナップショット(ロールバック用の履歴データ)は明示的な運用判断で削除すべきデータのため、
     * アンインストール時に自動削除はしない(acms-archives-to-mediaの監査ログと同じ方針)。
     *
     * @return void
     */
    public function uninstall()
    {
    }

    /**
     * アップデート時の処理。スキーマの差分を再適用する。
     *
     * @return bool
     */
    public function update()
    {
        $this->install();

        return true;
    }

    /**
     * 有効化時の処理。
     *
     * @return bool
     */
    public function activate()
    {
        return true;
    }

    /**
     * 無効化時の処理。
     *
     * @return bool
     */
    public function deactivate()
    {
        return true;
    }
}
