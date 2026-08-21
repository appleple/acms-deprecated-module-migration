<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\Exceptions\UnsupportedMigrationException;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiff;
use Acms\Plugins\DeprecatedModuleMigration\MigrationResult;
use Acms\Plugins\DeprecatedModuleMigration\MigrationStrategyInterface;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;

/**
 * User_Profile → User_Search (ランクC・自動適用なし)。
 *
 * Profileの簡易一覧機能とSearchの検索一覧機能は機能の厚みが異なり、認可ロールの型変換
 * (個別flag→配列)・公開ステータス既定値の逆転(Profileは常時公開のみ、Searchは既定で
 * 非公開ユーザーも表示されうる)・テンプレート変数のタイポ不一致(mail_magazine と
 * mail_magaginze)など、1対1の自動移行が構造的に成立しない。差分計算は行わず、
 * チェックリストの提示のみを行う(detailed-design.html
 * 「詳細マッピング: User_Profile → User_Search」参照)。
 */
final class UserSearchAdvisoryStrategy implements MigrationStrategyInterface
{
    private const SOURCE_MODULE_NAME = 'User_Profile';
    private const TARGET_MODULE_NAME = 'User_Search';

    private const CHECKLIST = [
        'Profileの簡易一覧機能(既定limit 5)とSearchの検索一覧機能は厚みが異なるため、'
            . '1対1のモジュール設置変換にならない場合があります。',
        '認可ロールの型が異なります。Profileの個別on/offフラグ(administrator/editor/contributor/subscriber)を、'
            . 'Searchの配列設定(user_search_auth)へ手動で組み替えてください。',
        '公開ステータスの既定値が逆転しています。Profileは常時「公開状態のユーザーのみ」を暗黙で固定表示しますが、'
            . 'Searchは既定で無制限(非公開ユーザーも表示されうる)のため、移行時は必ず'
            . 'user_search_status = [\'open\'] を明示設定してください。',
        '並び順の既定値・選択肢体系が異なります(Profile既定 sort-asc、Search既定 field-asc)。',
        'Search側のテンプレート変数は mail_magaginze という誤字で実装されているため、'
            . 'Profileのテンプレートにある {mail_magazine} 参照はそのままでは機能しません。'
            . 'テンプレートの変数名を手動で修正してください。',
        'Profileが出力する user_mail_mobile_magazine(モバイル向けメルマガ購読状態)はSearchでは'
            . '取得・変数化されません。該当表示があるテンプレートは移行後に空になります。',
    ];

    public function supports(): array
    {
        return [self::SOURCE_MODULE_NAME];
    }

    public function targetModuleName(string $sourceModuleName): string
    {
        return self::TARGET_MODULE_NAME;
    }

    public function rank(): string
    {
        return 'C';
    }

    public function diff(ModuleRow $module, ConfigCollection $configs): MigrationDiff
    {
        $this->assertSupported($module);

        return new MigrationDiff(
            sourceModuleName: $module->moduleName,
            targetModuleName: self::TARGET_MODULE_NAME,
            items: [],
            warnings: self::CHECKLIST,
            unsupportedReasons: [
                'User_Profile → User_Search は自動移行を提供していません。'
                    . '上記チェックリストに沿って手動で移行してください。',
            ]
        );
    }

    public function apply(ModuleRow $module, ConfigCollection $configs, MigrationDiff $approvedDiff): MigrationResult
    {
        throw new UnsupportedMigrationException(
            'User_Profile → User_Search は自動適用に対応していません。手動で移行してください。'
        );
    }

    public function applyForRule(ModuleRow $module, ConfigCollection $configs, MigrationDiff $ruleDiff, int $ruleId): void
    {
        // apply()自体が常に例外を投げるため、呼び出し元(ModuleMigrationManager)の
        // ルール別移行ループへ到達することはない。interfaceの契約を明示するためだけに用意する。
        throw new UnsupportedMigrationException(
            'User_Profile → User_Search は自動適用に対応していません。手動で移行してください。'
        );
    }

    private function assertSupported(ModuleRow $module): void
    {
        if (!in_array($module->moduleName, $this->supports(), true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s は %s がサポートしていない module_name です。',
                $module->moduleName,
                self::class
            ));
        }
    }
}
