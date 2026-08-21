<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

use Acms\Services\Facades\Config;
use Acms\Services\Facades\Database as DB;
use SQL;

/**
 * 非推奨モジュール移行の各Strategy::apply()から共通利用するDB書き込み処理。
 *
 * config行は「既存行があれば探してUPDATE、無ければINSERT」方式を取る。既存の
 * Config::saveConfig()（削除→一括INSERT）は対象スコープの該当キーを全削除するため、
 * 「対象キー以外のconfig行には触れない」という移行機能の要件(detailed-design.html
 * 「10. DB操作の詳細」)には適合しない。
 */
final class ModuleMigrationRepository
{
    /**
     * リネーム先が module テーブルのUNIQUE制約(module_identifier, module_name, module_blog_id,
     * module_scope)に衝突する既存の別モジュールが無いかを調べる。同一ブログ内で複数の旧モジュール
     * (例: Entry_Headline と Entry_List)が同じ module_identifier を持ったまま両方 Entry_Summary へ
     * リネームしようとすると、2件目のUPDATEがUNIQUE制約違反で失敗し、config行だけ書き込まれた
     * 中途半端な状態になりうる。apply()前にManager側で検出してブロックするために使う。
     */
    public function renameWouldCollide(
        int $moduleId,
        string $moduleIdentifier,
        string $newModuleName,
        int $moduleBlogId,
        string $moduleScope
    ): bool {
        $sql = SQL::newSelect('module');
        $sql->addSelect('module_id');
        $sql->addWhereOpr('module_identifier', $moduleIdentifier);
        $sql->addWhereOpr('module_name', $newModuleName);
        $sql->addWhereOpr('module_blog_id', $moduleBlogId);
        $sql->addWhereOpr('module_scope', $moduleScope);
        $sql->addWhereOpr('module_id', $moduleId, '<>');
        $sql->setLimit(1);

        return DB::query($sql->get(dsn()), 'one') !== false;
    }

    public function renameModule(int $moduleId, int $moduleBlogId, string $newModuleName): void
    {
        $sql = SQL::newUpdate('module');
        $sql->addUpdate('module_name', $newModuleName);
        $sql->addUpdate('module_updated_datetime', date('Y-m-d H:i:s'));
        $sql->addWhereOpr('module_id', $moduleId);
        $sql->addWhereOpr('module_blog_id', $moduleBlogId);
        $this->execOrFail($sql->get(dsn()), 'モジュール名の更新に失敗しました。');
    }

    /**
     * 指定した module_*_scope カラムを 'global' に固定する
     * (Entry_Headline移行時のURLパラメータscope固定上書きの再現。detailed-design.html
     * 「詳細マッピング: Entry_Headline/List/Photo → Entry_Summary」参照)。
     *
     * @param string[] $columns
     */
    public function forceGlobalScope(int $moduleId, int $moduleBlogId, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $sql = SQL::newUpdate('module');
        foreach ($columns as $column) {
            $sql->addUpdate($column, 'global');
        }
        $sql->addWhereOpr('module_id', $moduleId);
        $sql->addWhereOpr('module_blog_id', $moduleBlogId);
        $this->execOrFail($sql->get(dsn()), 'URLパラメータscopeの更新に失敗しました。');
    }

    public function upsertModuleConfig(int $blogId, ?int $ruleId, int $moduleId, string $key, string $value): void
    {
        if ($this->configRowExists($blogId, $ruleId, $moduleId, $key)) {
            $sql = SQL::newUpdate('config');
            $sql->addUpdate('config_value', $value);
            $sql->addWhereOpr('config_key', $key);
            $sql->addWhereOpr('config_rule_id', $ruleId);
            $sql->addWhereOpr('config_module_id', $moduleId);
            $sql->addWhereOpr('config_blog_id', $blogId);
            $this->execOrFail($sql->get(dsn()), "設定値の更新に失敗しました(key={$key})。");

            return;
        }

        $sql = SQL::newInsert('config');
        $sql->addInsert('config_key', $key);
        $sql->addInsert('config_value', $value);
        $sql->addInsert('config_sort', $this->nextConfigSort($blogId, $ruleId, $moduleId));
        $sql->addInsert('config_rule_id', $ruleId);
        $sql->addInsert('config_module_id', $moduleId);
        $sql->addInsert('config_blog_id', $blogId);
        $this->execOrFail($sql->get(dsn()), "設定値の追加に失敗しました(key={$key})。");
    }

    /**
     * Banner系のように1つのconfig_keyに複数値(スロット配列)を持つモジュールのconfigを
     * 丸ごと置き換える。既存行を全削除してから、渡した配列の順序どおりconfig_sortを
     * 振って再INSERTする(a-blog cmsの複数値configは「同一config_keyの複数行を
     * config_sort順に並べる」方式であり、Field::get($key, $default, $i)がこの並び順を
     * 配列インデックスとして参照するため、位置がそのまま意味を持つ)。
     *
     * @param string[] $values
     */
    public function replaceModuleConfigArray(int $blogId, ?int $ruleId, int $moduleId, string $key, array $values): void
    {
        $deleteSql = SQL::newDelete('config');
        $deleteSql->addWhereOpr('config_key', $key);
        $deleteSql->addWhereOpr('config_rule_id', $ruleId);
        $deleteSql->addWhereOpr('config_module_id', $moduleId);
        $deleteSql->addWhereOpr('config_blog_id', $blogId);
        $this->execOrFail($deleteSql->get(dsn()), "設定値配列の削除に失敗しました(key={$key})。");

        if ($values === []) {
            return;
        }

        $sort = $this->nextConfigSort($blogId, $ruleId, $moduleId);
        foreach ($values as $value) {
            $sql = SQL::newInsert('config');
            $sql->addInsert('config_key', $key);
            $sql->addInsert('config_value', $value);
            $sql->addInsert('config_sort', $sort++);
            $sql->addInsert('config_rule_id', $ruleId);
            $sql->addInsert('config_module_id', $moduleId);
            $sql->addInsert('config_blog_id', $blogId);
            $this->execOrFail($sql->get(dsn()), "設定値配列の追加に失敗しました(key={$key})。");
        }
    }

    public function forgetModuleConfigCache(int $blogId, ?int $ruleId, int $moduleId): void
    {
        Config::forgetCache($blogId, $ruleId, $moduleId);
    }

    /**
     * DB::query(..., 'exec') は本番環境(debug=false)では、クエリ失敗時に例外を投げず false を返す
     * (Services/Database/Engine/PdoEngine::query()のPDOException catch節を参照)。DB::transaction()は
     * 例外でのみロールバックするため、戻り値を検査しないと exec の失敗が黙って握りつぶされ、
     * 一部のUPDATE/INSERTだけが成功した中途半端な状態でトランザクションがコミットされてしまう。
     */
    /**
     * @param array{sql: string, params: array<int<0, max>|string, mixed>}|string $sql
     */
    private function execOrFail(array|string $sql, string $errorMessage): void
    {
        if (DB::query($sql, 'exec') === false) {
            throw new \RuntimeException($errorMessage);
        }
    }

    private function configRowExists(int $blogId, ?int $ruleId, int $moduleId, string $key): bool
    {
        $sql = SQL::newSelect('config');
        $sql->addSelect('config_key');
        $sql->addWhereOpr('config_key', $key);
        $sql->addWhereOpr('config_rule_id', $ruleId);
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $blogId);
        $sql->setLimit(1);

        return DB::query($sql->get(dsn()), 'one') !== false;
    }

    private function nextConfigSort(int $blogId, ?int $ruleId, int $moduleId): int
    {
        $sql = SQL::newSelect('config');
        $sql->addSelect('config_sort');
        $sql->addWhereOpr('config_rule_id', $ruleId);
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $blogId);
        $sql->setOrder('config_sort', 'DESC');
        $sql->setLimit(1);

        $current = DB::query($sql->get(dsn()), 'one');

        return $current === false ? 1 : ((int) $current) + 1;
    }
}
