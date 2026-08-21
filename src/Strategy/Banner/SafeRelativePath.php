<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner;

/**
 * ARCHIVES_DIR等の基準ディレクトリへ連結してよい相対パスかどうかを判定する。
 *
 * a-blog cms コア側の `Acms\Services\Storage\Filesystem::isSafeRelativePath()` と同じ判定
 * ロジックをプラグイン側で自己完結させたもの。当初はコアの `PublicStorage::isSafeRelativePath()`
 * を直接呼んでいたが、このメソッドはCMS-7815/CMS-7817のパストラバーサル対策で追加された
 * ばかりで、まだリリースされたa-blog cms 3.2.x(このプラグインが要件とするバージョン)には
 * 存在しない(配布用DockerイメージでのCI実行時にstaticMethod.notFoundで検出)。プラグインが
 * 未リリースのコアAPIに依存してしまうと対応バージョンの幅が意図せず狭まるため、判定ロジックを
 * ここに複製して依存を切る。
 */
final class SafeRelativePath
{
    public static function isSafe(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        if (strpos($path, "\0") !== false) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        if (strpos($normalized, '://') !== false) {
            return false;
        }
        if (substr($normalized, 0, 1) === '/') {
            return false;
        }
        if (preg_match('@^[A-Za-z]:@', $normalized) === 1) {
            return false;
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
