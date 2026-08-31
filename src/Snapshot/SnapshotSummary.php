<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Snapshot;

/**
 * 移行履歴一覧に表示する、スナップショット1件分の要約情報を表す値オブジェクト。
 *
 * moduleName はスナップショット取得時点(=適用直前)の値であり、ロールバックすると
 * 復元される名前を表す(現在の module.module_name とは異なりうる)。
 */
final class SnapshotSummary
{
    public function __construct(
        public readonly int $snapshotId,
        public readonly int $moduleId,
        public readonly string $moduleName,
        public readonly string $snapshotDatetime,
        public readonly int $userId
    ) {
    }
}
