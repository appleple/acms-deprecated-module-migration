<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner;

/**
 * banner_img(ファイルパス文字列)を Media_Banner のメディアレコードへ実データ移行する。
 *
 * 既存のメディア登録経路(Acms\Services\Media\Helper::insertMedia())を利用し、
 * 必須カラムの穴埋め・サムネイル生成・画像最適化を再利用する
 * (detailed-design.html「画像移行バッチの処理フロー」参照)。
 */
interface BannerImageMigratorInterface
{
    /**
     * @return int 新規発行された media_id
     */
    public function migrate(int $blogId, string $relativePath, string $linkUrl): int;
}
