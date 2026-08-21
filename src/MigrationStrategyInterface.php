<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

use Acms\Plugins\DeprecatedModuleMigration\Exceptions\UnsupportedMigrationException;

/**
 * 非推奨モジュール1ペアぶんの移行ロジックを表すインターフェース。
 *
 * diff() はDBアクセスを伴わない純粋関数として実装し、Unitテストで検証する。
 * apply() のみ実際のDB更新を担当し、Integrationテストで検証する
 * (detailed-design.html「1. アーキテクチャ・クラス設計」参照)。
 */
interface MigrationStrategyInterface
{
    /**
     * このStrategyが対応する旧 module_name の一覧(例: ['Entry_Headline', 'Entry_List', 'Entry_Photo'])。
     *
     * @return string[]
     */
    public function supports(): array;

    /**
     * 変換先の module_name を返す。
     */
    public function targetModuleName(string $sourceModuleName): string;

    /**
     * ランク。'A' | 'B' | 'C'
     */
    public function rank(): string;

    /**
     * 対象モジュール行・実効値解決済みのconfigを受け取り、変換後の値と警告一覧を算出する。
     * DBは更新しない。
     */
    public function diff(ModuleRow $module, ConfigCollection $configs): MigrationDiff;

    /**
     * diff() の結果(制作者が確認・上書きしたもの)を実際に適用する。
     *
     * ランクC(自動適用なし)のStrategyはこのメソッドを呼び出せず、
     * UnsupportedMigrationException をスローする。
     *
     * @throws UnsupportedMigrationException
     */
    public function apply(ModuleRow $module, ConfigCollection $configs, MigrationDiff $approvedDiff): MigrationResult;

    /**
     * apply()と同じフィールドマッピングで、他のルール(config_rule_id)にスコープされた
     * config行を移行する。呼び出し元(ModuleMigrationManager)がルール別の実効値を
     * 解決した $configs と、それに基づく $ruleDiff を渡す。
     *
     * module_nameのリネームはapply()側で既に完了している前提のため、ここでは行わない。
     * $ruleDiff->isBlocked() が true の場合は何も書き込まず、呼び出し元にその旨を
     * 委ねる(例外は投げない。1つのルールが自動移行不可でも他のルール・本体の適用結果を
     * 損なわないようにするため)。
     *
     * config行を一切書き込まないStrategy(diff()が常に空items、または自動適用非対応)は
     * 何もしなくてよい。
     */
    public function applyForRule(ModuleRow $module, ConfigCollection $configs, MigrationDiff $ruleDiff, int $ruleId): void;
}
