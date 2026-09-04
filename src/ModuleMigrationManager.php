<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

use ACMS_Filter;
use Acms\Services\Facades\Config;
use Acms\Services\Facades\Database as DB;
use Acms\Plugins\DeprecatedModuleMigration\Snapshot\ModuleSnapshotRepository;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\CategoryEntrySummaryMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\EntrySummaryMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\MediaBannerMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\ScheduleMigrationStrategy;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\UserSearchAdvisoryStrategy;
use AcmsLogger;
use SQL;

/**
 * 非推奨モジュール自動移行機能の司令塔。対象moduleの検出・Strategyの解決・
 * 実効値解決済みConfigCollectionの構築を担う
 * (detailed-design.html「1. アーキテクチャ・クラス設計」「9. 処理シーケンス」参照)。
 *
 * 検出・適用は module_blog_id(実際の所有ブログ)を基準に列挙する。global scope の
 * モジュールは所有ブログ以外の子ブログからも参照されうるため、呼び出し側
 * (ハンドラ層)で対象ブログに対する編集権限チェックを別途行うこと。
 */
final class ModuleMigrationManager
{
    private const SCOPE_COLUMNS = [
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

    /** @var MigrationStrategyInterface[] */
    private readonly array $strategies;
    private readonly ModuleSnapshotRepository $snapshotRepository;
    private readonly ModuleMigrationRepository $repository;

    /**
     * @param MigrationStrategyInterface[]|null $strategies 省略時は標準の5戦略を登録する
     */
    public function __construct(
        ?array $strategies = null,
        ?ModuleSnapshotRepository $snapshotRepository = null,
        ?ModuleMigrationRepository $repository = null
    ) {
        $this->strategies = $strategies ?? [
            new ScheduleMigrationStrategy(),
            new EntrySummaryMigrationStrategy(),
            new CategoryEntrySummaryMigrationStrategy(),
            new MediaBannerMigrationStrategy(),
            new UserSearchAdvisoryStrategy(),
        ];
        $this->snapshotRepository = $snapshotRepository ?? new ModuleSnapshotRepository();
        $this->repository = $repository ?? new ModuleMigrationRepository();
    }

    /**
     * @return string[]
     */
    public function supportedModuleNames(): array
    {
        $names = [];
        foreach ($this->strategies as $strategy) {
            foreach ($strategy->supports() as $moduleName) {
                $names[] = $moduleName;
            }
        }

        return $names;
    }

    public function resolveStrategy(string $moduleName): ?MigrationStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if (in_array($moduleName, $strategy->supports(), true)) {
                return $strategy;
            }
        }

        return null;
    }

    /**
     * 対象ブログが所有する(module_blog_id が一致する)非推奨モジュールを列挙する。
     *
     * @return ModuleRow[]
     */
    public function detect(int $blogId): array
    {
        $moduleNames = $this->supportedModuleNames();
        if ($moduleNames === []) {
            return [];
        }

        $sql = SQL::newSelect('module');
        $sql->addSelect('module_id');
        $sql->addSelect('module_identifier');
        $sql->addSelect('module_name');
        $sql->addSelect('module_blog_id');
        $sql->addSelect('module_scope');
        foreach (self::SCOPE_COLUMNS as $column) {
            $sql->addSelect($column);
        }
        $sql->addWhereOpr('module_blog_id', $blogId);
        $sql->addWhereIn('module_name', $moduleNames);
        $sql->addWhereOpr('module_status', 'open');
        $sql->setOrder('module_id');

        $rows = DB::query($sql->get(dsn()), 'all');

        return array_map(fn (array $row): ModuleRow => $this->toModuleRow($row), $rows);
    }

    public function diff(ModuleRow $module): MigrationDiff
    {
        $strategy = $this->requireStrategy($module);
        $diff = $strategy->diff($module, $this->buildConfigCollection($module));

        $ruleIds = $this->repository->findRuleIdsWithConfig($module->moduleBlogId, $module->moduleId);
        if ($ruleIds === []) {
            return $diff;
        }

        return new MigrationDiff(
            sourceModuleName: $diff->sourceModuleName,
            targetModuleName: $diff->targetModuleName,
            items: $diff->items,
            warnings: [
                ...$diff->warnings,
                sprintf(
                    'この設定には %d 件のルール別上書き(URLパターン別設定)があります。'
                        . '適用時にそれぞれ同じ変換ルールで移行されます。',
                    count($ruleIds)
                ),
            ],
            unsupportedReasons: $diff->unsupportedReasons
        );
    }

    /**
     * @throws \RuntimeException リネーム先が同一ブログ内の別モジュールとUNIQUE制約
     *         (module_identifier, module_name, module_blog_id, module_scope)で衝突する場合。
     *         同一ブログで複数の旧モジュール(例: Entry_Headline と Entry_List)が同じ
     *         module_identifier を持ったまま両方を同名の新モジュールへ移行しようとすると、
     *         2件目のリネームがUNIQUE制約違反で失敗し、config行だけ書き込まれた中途半端な
     *         状態になりうるため、apply()の実DB書き込みより前に検出してブロックする。
     */
    public function apply(ModuleRow $module, MigrationDiff $approvedDiff): MigrationResult
    {
        $strategy = $this->requireStrategy($module);
        $targetModuleName = $strategy->targetModuleName($module->moduleName);

        if (
            $this->repository->renameWouldCollide(
                $module->moduleId,
                $module->moduleIdentifier,
                $targetModuleName,
                $module->moduleBlogId,
                $module->moduleScope
            )
        ) {
            throw new \RuntimeException(sprintf(
                '同一ブログ内に identifier="%s" を持つ "%s" が既に存在するため、この移行は実行できません'
                    . '(module_identifier, module_name, module_blog_id, module_scope の組み合わせが重複します)。'
                    . '先に一方のmodule_identifierを変更してから再度実行してください。',
                $module->moduleIdentifier,
                $targetModuleName
            ));
        }

        $result = $strategy->apply($module, $this->buildConfigCollection($module), $approvedDiff);

        return $this->applyToRuleScopedConfigs($module, $strategy, $result);
    }

    /**
     * ベース(ルール無し)のapply()が完了した後、この module に対してルール単位で
     * 上書きされているconfig行があれば、同じStrategyの変換ルールでそれぞれ移行する
     * (buildConfigCollection()のdocblockに記載の既知の制約「ルール単位のコンフィグセットは
     * 対象外」を解消する)。
     *
     * ルールごとに診断(diff)し直すのは、フィールドマッピングはmodule種別に対して一意だが、
     * 実効値はルールごとに異なるため(detailed-design.html「2. 設計原則」参照)。
     * あるルールの診断がisBlocked()になった場合はそのルールだけ書き込みをスキップし、
     * その旨をMigrationResult::notesに追記する(1ルールの自動移行不可が、本体やほかの
     * ルールの適用結果を損なわないようにするため)。
     */
    private function applyToRuleScopedConfigs(
        ModuleRow $module,
        MigrationStrategyInterface $strategy,
        MigrationResult $result
    ): MigrationResult {
        $ruleIds = $this->repository->findRuleIdsWithConfig($module->moduleBlogId, $module->moduleId);
        if ($ruleIds === []) {
            return $result;
        }

        $additionalNotes = [];
        foreach ($ruleIds as $ruleId) {
            $ruleConfigs = $this->buildConfigCollection($module, $ruleId);
            $ruleDiff = $strategy->diff($module, $ruleConfigs);
            if ($ruleDiff->isBlocked()) {
                $additionalNotes[] = sprintf(
                    'ルールID %d の設定は自動移行できませんでした。手動で確認してください: %s',
                    $ruleId,
                    implode(' / ', $ruleDiff->unsupportedReasons)
                );
                continue;
            }

            $strategy->applyForRule($module, $ruleConfigs, $ruleDiff, $ruleId);
        }

        if ($additionalNotes === []) {
            return $result;
        }

        return new MigrationResult(
            moduleId: $result->moduleId,
            oldModuleName: $result->oldModuleName,
            newModuleName: $result->newModuleName,
            writtenConfig: $result->writtenConfig,
            notes: [...$result->notes, ...$additionalNotes]
        );
    }

    /**
     * 適用前スナップショットを保存したうえで apply() を実行し、Configキャッシュを無効化する。
     * 管理画面・CLIから呼び出す想定の本線経路(detailed-design.html「9. 処理シーケンス」参照)。
     *
     * モジュール単位でトランザクション化し、途中で例外が発生した場合はスナップショット保存・
     * module/config書き込みのいずれも巻き戻す(detailed-design.html「9. 処理シーケンス」
     * 「13. ロールバック設計」が要求するモジュール単位のトランザクション分離)。
     * Banner移行のファイルコピー(Media\Helper経由)はDBトランザクションの対象外であるため、
     * 途中でDBが巻き戻ってもコピー済みファイル・作成済みmediaレコードは残る
     * (detailed-design.html「画像移行バッチの処理フロー」に記載のロールバックの限界と同じ)。
     *
     * @return array{result: MigrationResult, snapshotId: int}
     */
    public function applyWithSnapshot(ModuleRow $module, MigrationDiff $approvedDiff, int $userId): array
    {
        return DB::transaction(function () use ($module, $approvedDiff, $userId): array {
            $snapshotId = $this->snapshotRepository->save($module, $userId);
            $result = $this->apply($module, $approvedDiff);
            Config::forgetCache($module->moduleBlogId, null, $module->moduleId);

            AcmsLogger::info(sprintf(
                '非推奨モジュール移行を適用しました(module_id=%d, %s → %s)',
                $module->moduleId,
                $result->oldModuleName,
                $result->newModuleName
            ), [
                'module_id' => $module->moduleId,
                'blog_id' => $module->moduleBlogId,
                'snapshot_id' => $snapshotId,
                'old_module_name' => $result->oldModuleName,
                'new_module_name' => $result->newModuleName,
                'user_id' => $userId,
            ]);

            return ['result' => $result, 'snapshotId' => $snapshotId];
        });
    }

    /**
     * スナップショットの内容で module・config を復元し、Configキャッシュを無効化する。
     *
     * $expectedBlogId には呼び出し元(ハンドラ層)が権限チェック済みのブログIDを渡すこと。
     * snapshotId のみを信頼すると、権限チェックがblogId単位であるにもかかわらず他ブログの
     * スナップショットを復元できてしまうため、所有ブログの一致を必須とする。
     *
     * @throws \RuntimeException expectedBlogIdがスナップショットの所有ブログと一致しない場合
     */
    public function rollback(int $snapshotId, int $expectedBlogId): void
    {
        DB::transaction(function () use ($snapshotId, $expectedBlogId): void {
            $identifiers = $this->snapshotRepository->restore($snapshotId, $expectedBlogId);
            Config::forgetCache($identifiers['blogId'], null, $identifiers['moduleId']);

            AcmsLogger::info(sprintf(
                '非推奨モジュール移行をロールバックしました(module_id=%d, snapshot_id=%d)',
                $identifiers['moduleId'],
                $snapshotId
            ), [
                'module_id' => $identifiers['moduleId'],
                'blog_id' => $identifiers['blogId'],
                'snapshot_id' => $snapshotId,
            ]);
        });
    }

    /**
     * 指定ブログ配下の子孫ブログ(全階層・公開状態のみ)のIDをblog_left昇順(深さ優先)で
     * 列挙する(ルートブログから子ブログ横断で非推奨モジュールを検出する
     * 「配下のブログを含める」オプション向け)。
     *
     * コア標準の ACMS_Filter::blogTree($SQL, $bid, 'descendant') に委譲する
     * (blogテーブルのネステッドセット方式(blog_left/blog_right)によるツリー検索は
     * コア側の実装に追従し、プラグイン側で独自にnested setの比較ロジックを
     * 再実装しない)。
     *
     * @return int[]
     */
    public function descendantBlogIds(int $blogId): array
    {
        $sql = SQL::newSelect('blog');
        $sql->addSelect('blog_id');
        ACMS_Filter::blogTree($sql, $blogId, 'descendant');
        $sql->addWhereOpr('blog_status', 'open');
        $sql->setOrder('blog_left');

        /** @var list<array{blog_id: int|string}> $rows */
        $rows = DB::query($sql->get(dsn()), 'all');

        return array_map(fn (array $row): int => (int) $row['blog_id'], $rows);
    }

    /**
     * 指定したblogIdをキーにブログ名を引けるマップを返す(一覧画面で所属ブログ名を
     * 表示するため)。
     *
     * @param int[] $blogIds
     * @return array<int, string>
     */
    public function blogNames(array $blogIds): array
    {
        if ($blogIds === []) {
            return [];
        }

        $sql = SQL::newSelect('blog');
        $sql->addSelect('blog_id');
        $sql->addSelect('blog_name');
        $sql->addWhereIn('blog_id', $blogIds);

        /** @var list<array{blog_id: int|string, blog_name: string}> $rows */
        $rows = DB::query($sql->get(dsn()), 'all');

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['blog_id']] = $row['blog_name'];
        }

        return $names;
    }

    /**
     * 対象ブログが所有するスナップショットを新しい順に列挙する(移行履歴一覧画面向け)。
     *
     * @return \Acms\Plugins\DeprecatedModuleMigration\Snapshot\SnapshotSummary[]
     */
    public function listSnapshots(int $blogId): array
    {
        return $this->snapshotRepository->findAllByBlogId($blogId);
    }

    private function requireStrategy(ModuleRow $module): MigrationStrategyInterface
    {
        $strategy = $this->resolveStrategy($module->moduleName);
        if ($strategy === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s に対応する移行戦略が登録されていません。',
                $module->moduleName
            ));
        }

        return $strategy;
    }

    /**
     * moduleの実効値を実際のリクエストコンテキストで解決し、ConfigCollectionへ包む。
     *
     * Config::loadModuleConfig($mid) 単体は「config_module_id=$midの行だけ」を返し、
     * システム既定値(default.yaml)・ブログ/コンフィグセット単位の上書きを含まない
     * (Services/Config/Helper::loadModuleConfig()の実装、および通常のページ表示時に
     * function.php:boot()が `$Config->overload(loadModuleConfig($mid, RID))` を
     * 「既にシステム既定値+ブログ/コンフィグセットが重ね合わせ済みの$Config」に対して
     * 行っている点から確認済み)。実効値ベースの差分計算が成立するには、この重ね合わせを
     * Manager側で再現する必要がある。
     *
     * 実効値の解決経路そのものは独自実装せず、既存のConfigサービスの各メソッド
     * (loadDefaultField() → loadBlogConfigSet() → loadModuleConfig())を
     * 「システム既定値 → ブログ/コンフィグセット単位の上書き → module単位の上書き」の順で
     * overloadすることで組み立てる(detailed-design.html「2. 設計原則: 実効値ベースの差分計算」参照)。
     *
     * $ruleId を渡すと、そのルールでのみ上書きされた値も反映した実効値になる
     * (Config::loadModuleConfig($mid, $rid)は内部でルール無し→指定ルールの順に自前で
     * overloadするため、呼び出し側で二重にoverloadする必要はない。
     * Services/Config/Helper::loadModuleConfig()の実装で確認済み)。diff()/apply()は
     * ルール無し(既定)の実効値で判断し、ルール別上書きの移行はManagerが別途
     * ルールごとにこのメソッドを呼び直して行う(applyToRuleScopedConfigs()参照)。
     */
    private function buildConfigCollection(ModuleRow $module, ?int $ruleId = null): ConfigCollection
    {
        // システム既定値のみのFieldを別途保持し、ConfigCollectionのdefaultResolverとして渡す。
        // Strategy側の「未対応キーに非既定値が入っていたら警告する」判定(unmapped-key警告)は、
        // 実効値が「空文字かどうか」ではなく「システム既定値と異なるかどうか」で行う必要がある
        // (order2やoffset等、既定値自体が非空文字のキーが多数あり、空文字判定だと誤検知するため)。
        //
        // Config::loadDefaultField()をそのまま2回呼んで使い分けるのではなく、$defaultFieldは
        // 一度だけ取得し、上書き用の$fieldは`new Field($defaultField)`でコピーする。
        // loadDefaultField()はキャッシュ経由でFieldを返すが、その実装がシリアライズを介さない
        // (同一インスタンスをそのまま返す)キャッシュドライバに変わった場合、$fieldへのoverload()が
        // $defaultField(および他の呼び出し元とキャッシュを共有する既定値Field)まで汚染しうる。
        // Field::overload()は引数側を読み取るだけでレシーバのみを変異させるため、明示的にコピーを
        // 作ってから変異させることで、この前提に依存せず安全性を保証する。
        $defaultField = Config::loadDefaultField();
        $field = new \Field($defaultField);
        $field->overload(Config::loadBlogConfigSet($module->moduleBlogId));
        $field->overload(Config::loadModuleConfig($module->moduleId, $ruleId));

        return new ConfigCollection(
            static fn (string $key, $default = null) => $field->get($key, $default),
            static fn (string $key) => $field->getArray($key),
            static fn (string $key) => $defaultField->get($key)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toModuleRow(array $row): ModuleRow
    {
        $scopeColumns = [];
        foreach (self::SCOPE_COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $scopeColumns[$column] = $row[$column];
            }
        }

        return new ModuleRow(
            moduleId: (int) $row['module_id'],
            moduleIdentifier: (string) $row['module_identifier'],
            moduleName: (string) $row['module_name'],
            moduleBlogId: (int) $row['module_blog_id'],
            moduleScope: (string) $row['module_scope'],
            scopeColumns: $scopeColumns
        );
    }
}
