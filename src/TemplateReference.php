<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * テンプレート内で検出された BEGIN_MODULE 設置箇所。
 *
 * module_name のリネームだけを行うと、この設置箇所のタグ名が旧名のままの間は
 * 該当モジュールの設定が丸ごと外れる(detailed-design.html
 * 「設計原則: テンプレート参照の検出」参照)。本機能はファイルの書き換えは行わず、
 * 検出結果を制作者への手動対応の案内に使う。
 */
final class TemplateReference
{
    public function __construct(
        public readonly string $filePath,
        public readonly int $lineNumber,
        public readonly string $matchedLine
    ) {
    }
}
