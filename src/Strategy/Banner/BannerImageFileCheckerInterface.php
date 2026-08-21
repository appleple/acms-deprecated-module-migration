<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner;

/**
 * Banner → Media_Banner の画像移行における、旧banner_imgファイルの実在チェック。
 *
 * diff()(ドライラン)の時点でファイル実在チェックを行い、失敗するスロットは
 * 適用対象から除外して個別報告する(detailed-design.html「画像移行バッチの処理フロー」参照)。
 */
interface BannerImageFileCheckerInterface
{
    public function exists(int $blogId, string $relativePath): bool;
}
