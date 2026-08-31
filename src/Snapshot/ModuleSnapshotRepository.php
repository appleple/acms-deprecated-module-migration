<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Snapshot;

use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use SQL;

/**
 * 適用前の module 行・関連 config 行をJSONスナップショットとして専用テーブルへ保存し、
 * ロールバック時に復元する。
 *
 * スナップショット本体は監査ログ(audit_log)には格納しない。監査ログの記録処理
 * (limitedJsonEncode())は要素数・文字列長・ネスト深さに制限があり、configキー数の多い
 * モジュールで切り捨てられ、ロールバックが正しく機能しない恐れがあるため
 * (detailed-design.html「10. DB操作の詳細」参照)。
 */
final class ModuleSnapshotRepository
{
    /**
     * apply()が書き換えうる module のカラム(module_name および Entry_Headline固有の
     * URLパラメータscope強制で対象になりうるカラム)。
     *
     * @var string[]
     */
    private const MODULE_COLUMNS = [
        'module_name',
        'module_uid_scope',
        'module_cid_scope',
        'module_eid_scope',
        'module_keyword_scope',
        'module_tag_scope',
        'module_field_scope',
        'module_start_scope',
        'module_end_scope',
        'module_page_scope',
        'module_order_scope',
    ];

    public function save(ModuleRow $module, int $userId): int
    {
        $payload = json_encode([
            'module' => $this->fetchModuleRow($module->moduleId),
            'configs' => $this->fetchConfigRows($module->moduleId, $module->moduleBlogId),
        ], JSON_THROW_ON_ERROR);

        // プラグイン独自テーブルのため、コア共有のsequenceテーブルではなく
        // auto_increment主キー(snapshot_id)を使う(コアのsequenceテーブルへの変更は
        // プラグインの範囲外のスキーマに手を入れることになるため避ける)。
        $sql = SQL::newInsert('module_migration_snapshot');
        $sql->addInsert('snapshot_module_id', $module->moduleId);
        $sql->addInsert('snapshot_before_json', $payload);
        $sql->addInsert('snapshot_datetime', date('Y-m-d H:i:s'));
        $sql->addInsert('snapshot_user_id', $userId);
        $sql->addInsert('snapshot_blog_id', $module->moduleBlogId);
        $this->execOrFail($sql->get(dsn()), 'スナップショットの保存に失敗しました。');

        $row = DB::query('SELECT LAST_INSERT_ID() AS snapshot_id', 'row');

        return (int) $row['snapshot_id'];
    }

    /**
     * 指定ブログが所有するスナップショットを新しい順(snapshot_id降順)に列挙する
     * (移行履歴一覧画面向け。detailed-design.html「13. ロールバック設計」の
     * 「適用直後のみロールバック可能」という制約を緩和し、ページ遷移後もこの一覧から
     * 任意のスナップショットへロールバックできるようにする)。
     *
     * @return SnapshotSummary[]
     */
    public function findAllByBlogId(int $blogId): array
    {
        $sql = SQL::newSelect('module_migration_snapshot');
        $sql->addSelect('snapshot_id');
        $sql->addSelect('snapshot_module_id');
        $sql->addSelect('snapshot_before_json');
        $sql->addSelect('snapshot_datetime');
        $sql->addSelect('snapshot_user_id');
        $sql->addWhereOpr('snapshot_blog_id', $blogId);
        $sql->setOrder('snapshot_id', 'DESC');

        /**
         * @var list<array{
         *     snapshot_id: int|string,
         *     snapshot_module_id: int|string,
         *     snapshot_before_json: string,
         *     snapshot_datetime: string,
         *     snapshot_user_id: int|string
         * }> $rows
         */
        $rows = DB::query($sql->get(dsn()), 'all');

        return array_map(fn (array $row): SnapshotSummary => $this->toSnapshotSummary($row), $rows);
    }

    /**
     * @param int|null $expectedBlogId 指定した場合、スナップショットの所有ブログと一致しなければ
     *        復元を一切行わずに例外をスローする(呼び出し元の権限チェックがblogId単位で行われる
     *        ため、snapshotIdだけを頼りに他ブログのスナップショットを復元できてしまう
     *        IDOR相当の穴を防ぐ)
     * @return array{moduleId: int, blogId: int} 呼び出し側がConfigキャッシュを
     *         無効化するために必要な識別子(detailed-design.html「13. ロールバック設計」参照)
     * @throws \RuntimeException expectedBlogIdが指定され、かつ一致しない場合
     */
    public function restore(int $snapshotId, ?int $expectedBlogId = null): array
    {
        $snapshot = $this->fetchSnapshot($snapshotId);
        $moduleId = (int) $snapshot['snapshot_module_id'];
        $blogId = (int) $snapshot['snapshot_blog_id'];

        if ($expectedBlogId !== null && $blogId !== $expectedBlogId) {
            throw new \RuntimeException(
                "スナップショットの所有ブログが一致しません: snapshot_id={$snapshotId}"
            );
        }

        $payload = json_decode((string) $snapshot['snapshot_before_json'], true, 512, JSON_THROW_ON_ERROR);

        $this->restoreModuleRow($moduleId, $blogId, $payload['module'] ?? []);
        $this->restoreConfigRows($moduleId, $blogId, $payload['configs'] ?? []);

        return ['moduleId' => $moduleId, 'blogId' => $blogId];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchModuleRow(int $moduleId): array
    {
        $sql = SQL::newSelect('module');
        foreach (self::MODULE_COLUMNS as $column) {
            $sql->addSelect($column);
        }
        $sql->addWhereOpr('module_id', $moduleId);
        $row = DB::query($sql->get(dsn()), 'row');

        return $row === false ? [] : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchConfigRows(int $moduleId, int $blogId): array
    {
        $sql = SQL::newSelect('config');
        $sql->addSelect('config_key');
        $sql->addSelect('config_value');
        $sql->addSelect('config_sort');
        $sql->addSelect('config_rule_id');
        $sql->addSelect('config_set_id');
        $sql->addWhereOpr('config_module_id', $moduleId);
        $sql->addWhereOpr('config_blog_id', $blogId);

        return DB::query($sql->get(dsn()), 'all');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSnapshot(int $snapshotId): array
    {
        $sql = SQL::newSelect('module_migration_snapshot');
        $sql->addSelect('snapshot_module_id');
        $sql->addSelect('snapshot_before_json');
        $sql->addSelect('snapshot_blog_id');
        $sql->addWhereOpr('snapshot_id', $snapshotId);
        $row = DB::query($sql->get(dsn()), 'row');

        if ($row === false) {
            throw new \RuntimeException("スナップショットが見つかりません: snapshot_id={$snapshotId}");
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $moduleData
     */
    private function restoreModuleRow(int $moduleId, int $blogId, array $moduleData): void
    {
        if ($moduleData === []) {
            return;
        }

        $sql = SQL::newUpdate('module');
        foreach (self::MODULE_COLUMNS as $column) {
            if (array_key_exists($column, $moduleData)) {
                $sql->addUpdate($column, $moduleData[$column]);
            }
        }
        $sql->addWhereOpr('module_id', $moduleId);
        $sql->addWhereOpr('module_blog_id', $blogId);
        $this->execOrFail($sql->get(dsn()), 'モジュール行の復元に失敗しました。');
    }

    /**
     * config行はスナップショット時点の内容で完全に置き換える(削除→再挿入)。
     * これにより、移行で新規に追加された行(スナップショット後に生じた行)も含めて
     * 元の状態へ戻す。
     *
     * @param list<array<string, mixed>> $configRows
     */
    private function restoreConfigRows(int $moduleId, int $blogId, array $configRows): void
    {
        $deleteSql = SQL::newDelete('config');
        $deleteSql->addWhereOpr('config_module_id', $moduleId);
        $deleteSql->addWhereOpr('config_blog_id', $blogId);
        $this->execOrFail($deleteSql->get(dsn()), 'config行の復元(削除)に失敗しました。');

        foreach ($configRows as $row) {
            $insertSql = SQL::newInsert('config');
            $insertSql->addInsert('config_key', $row['config_key']);
            $insertSql->addInsert('config_value', $row['config_value']);
            $insertSql->addInsert('config_sort', $row['config_sort']);
            $insertSql->addInsert('config_rule_id', $row['config_rule_id']);
            $insertSql->addInsert('config_module_id', $moduleId);
            $insertSql->addInsert('config_set_id', $row['config_set_id']);
            $insertSql->addInsert('config_blog_id', $blogId);
            $this->execOrFail($insertSql->get(dsn()), 'config行の復元(再挿入)に失敗しました。');
        }
    }

    /**
     * DB::query(..., 'exec') は本番環境(debug=false)では、クエリ失敗時に例外を投げず false を返す
     * (ModuleMigrationRepository::execOrFail()と同じ理由。詳細はそちらのdocblock参照)。
     *
     * @param array{sql: string, params: array<int<0, max>|string, mixed>}|string $sql
     */
    private function execOrFail(array|string $sql, string $errorMessage): void
    {
        if (DB::query($sql, 'exec') === false) {
            throw new \RuntimeException($errorMessage);
        }
    }

    /**
     * @param array{
     *     snapshot_id: int|string,
     *     snapshot_module_id: int|string,
     *     snapshot_before_json: string,
     *     snapshot_datetime: string,
     *     snapshot_user_id: int|string
     * } $row
     */
    private function toSnapshotSummary(array $row): SnapshotSummary
    {
        /** @var array{module?: array{module_name?: string}} $payload */
        $payload = json_decode($row['snapshot_before_json'], true, 512, JSON_THROW_ON_ERROR);
        $moduleName = $payload['module']['module_name'] ?? '';

        return new SnapshotSummary(
            snapshotId: (int) $row['snapshot_id'],
            moduleId: (int) $row['snapshot_module_id'],
            moduleName: $moduleName,
            snapshotDatetime: $row['snapshot_datetime'],
            userId: (int) $row['snapshot_user_id']
        );
    }
}
