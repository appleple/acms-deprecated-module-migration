<?php

namespace Acms\Plugins\DeprecatedModuleMigration;

/**
 * 非推奨モジュール移行における「実効値」の読み取り専用ビュー。
 *
 * config テーブルの行の有無や default.yaml のフォールバックといった解決過程を
 * 呼び出し側（ModuleMigrationManager）に閉じ込め、Strategy の diff() からは
 * 単純な key => value のルックアップとして扱えるようにする。
 *
 * Banner系のように1つのconfig_keyに複数値(スロット配列)を持つモジュールのため、
 * スカラー解決(resolver)とは別に配列解決(arrayResolver)を任意で持てる。
 */
final class ConfigCollection
{
    /** @var callable(string, mixed): mixed */
    private $resolver;

    /** @var (callable(string): array<int, mixed>)|null */
    private $arrayResolver;

    /** @var (callable(string): mixed)|null */
    private $defaultResolver;

    /**
     * @param callable(string, mixed): mixed $resolver
     * @param (callable(string): array<int, mixed>)|null $arrayResolver
     * @param (callable(string): mixed)|null $defaultResolver ブログ/module単位の上書きを含まない、
     *   システム既定値のみを返すresolver。unmapped-key警告のように「ユーザーがカスタマイズしたか」を
     *   判定したい場合に使う(空文字判定だけでは、既定値自体が非空文字のキーを誤検知するため)。
     */
    public function __construct(callable $resolver, ?callable $arrayResolver = null, ?callable $defaultResolver = null)
    {
        $this->resolver = $resolver;
        $this->arrayResolver = $arrayResolver;
        $this->defaultResolver = $defaultResolver;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        return new self(function (string $key, $default = null) use ($values) {
            return array_key_exists($key, $values) ? $values[$key] : $default;
        });
    }

    /**
     * @param array<string, mixed> $scalarValues
     * @param array<string, array<int, mixed>> $arrayValues
     */
    public static function fromArrays(array $scalarValues, array $arrayValues): self
    {
        $scalar = self::fromArray($scalarValues);

        return new self(
            static fn (string $key, $default = null) => $scalar->get($key, $default),
            static fn (string $key) => $arrayValues[$key] ?? []
        );
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return ($this->resolver)($key, $default);
    }

    /**
     * キーが登録されているかどうかを返す。
     *
     * resolver は「見つからない場合に渡した default をそのまま返す」契約に従う前提で、
     * 他のどの実際の値とも一致しないセンチネル値を default に渡すことで存在有無を判定する
     * （値そのものが null であっても、それは「存在する」として扱う）。
     */
    public function has(string $key): bool
    {
        $sentinel = new \stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
    }

    /**
     * Banner系の複数スロット値をまとめて取得する。arrayResolver未指定の場合は
     * get()の単一値を1要素配列として扱う(通常のモジュールとの後方互換)。
     *
     * @return array<int, mixed>
     */
    public function getArray(string $key): array
    {
        if ($this->arrayResolver !== null) {
            return ($this->arrayResolver)($key);
        }

        return $this->has($key) ? [$this->get($key)] : [];
    }

    /**
     * 実効値がシステム既定値と異なる(=ユーザーが何らかの階層でカスタマイズ済み)かどうかを返す。
     *
     * defaultResolver未指定の場合は「値が空文字でない」ことをカスタマイズ有無の代替指標として使う
     * (ConfigCollection::fromArray()等、既定値解決の仕組みを持たない単純なテスト用途向けの後方互換)。
     */
    public function differsFromDefault(string $key): bool
    {
        if ($this->defaultResolver !== null) {
            return (string) $this->get($key) !== (string) ($this->defaultResolver)($key);
        }

        return (string) $this->get($key, '') !== '';
    }
}
