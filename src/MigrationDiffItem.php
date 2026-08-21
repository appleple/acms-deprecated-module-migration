<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * 新モジュール側の1設定項目についての差分。
 *
 * 「実効値ベースの差分計算」（detailed-design.html 2章）の中心となる値オブジェクト。
 * sourceEffectiveValue（旧モジュールの実効値。config行があればその値、無ければ旧モジュールの
 * 規定値）と targetDefaultValue（module_name だけ書き換えconfig行を追加しなかった場合に
 * 新モジュール側で有効になる実効値）を比較し、両者が異なる場合のみ新規config行の明示保存が
 * 必要と判定する。
 */
final class MigrationDiffItem
{
    public function __construct(
        public readonly string $targetConfigKey,
        public readonly mixed $sourceEffectiveValue,
        public readonly mixed $targetDefaultValue,
        public readonly ?string $sourceConfigKey = null
    ) {
    }

    /**
     * config行を追加しないまま移行した場合に挙動が変わるかどうか。
     *
     * 値の格納形式(int/string等)がconfig解決経路によって揺れるため、比較は文字列表現で行う。
     */
    public function willChangeIfUnset(): bool
    {
        return $this->normalize($this->sourceEffectiveValue) !== $this->normalize($this->targetDefaultValue);
    }

    /**
     * willChangeIfUnset() のエイリアス。diff結果を「明示的な書き込みが必要な項目」として
     * 扱う文脈で読みやすくするために用意している。
     */
    public function requiresExplicitWrite(): bool
    {
        return $this->willChangeIfUnset();
    }

    private function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
