<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner;

use Acms\Services\Facades\Database as DB;
use Acms\Services\Facades\PublicStorage;
use Acms\Services\Media\Helper as MediaHelper;
use SQL;

/**
 * banner_img(ファイルパス文字列)を、既存のメディア登録経路
 * (Acms\Services\Media\Helper::storeImage() / insertMedia())を通じて
 * Media_Bannerのメディアレコードへ実データ移行する。
 *
 * 独自にmediaテーブルへ直接INSERTすると必須カラムの欠落・不整合のリスクが高いため、
 * 既存の画像最適化・サムネイル生成ロジックをそのまま再利用する
 * (detailed-design.html「画像移行バッチの処理フロー」参照)。
 *
 * 既知の制約: Media\Helper::insertMedia() は media_blog_id にグローバル定数 BID
 * (現在のリクエストコンテキストのブログ)をそのまま使う実装になっており、移行対象の
 * $blogId を明示的に指定する経路が無い。グローバル定数を書き換えるのは副作用が大きく
 * 安全でないため、$blogId が現在の管理画面コンテキスト(BID)と一致しない場合は
 * 「別ブログのメディアとして誤登録される」事故を避けるため実行を拒否する。
 */
final class MediaHelperBannerImageMigrator implements BannerImageMigratorInterface
{
    public function migrate(int $blogId, string $relativePath, string $linkUrl): int
    {
        if (!PublicStorage::isSafeRelativePath($relativePath)) {
            throw new \RuntimeException("banner_img が不正なパスのため移行できません: {$relativePath}");
        }
        if ($blogId !== BID) {
            throw new \RuntimeException(
                '現在の管理画面コンテキストと異なるブログのBanner画像移行はサポートしていません。'
                    . 'このブログの管理画面から実行してください。'
            );
        }

        $mediaId = (int) DB::query(SQL::nextval('media_id', dsn()), 'seq');
        $tmpFile = tempnam(sys_get_temp_dir(), 'acms_banner_migration_');
        if ($tmpFile === false) {
            throw new \RuntimeException('一時ファイルの作成に失敗しました。');
        }

        try {
            $contents = PublicStorage::get(ARCHIVES_DIR . $relativePath);
            if ($contents === false) {
                throw new \RuntimeException("banner_img の読み込みに失敗しました: {$relativePath}");
            }
            file_put_contents($tmpFile, $contents);

            $mediaHelper = new MediaHelper();
            $stored = $mediaHelper->storeImage($tmpFile, basename($relativePath));

            $mediaHelper->insertMedia($mediaId, [
                'type' => 'image',
                'extension' => $stored['type'],
                'path' => $stored['path'],
                'name' => $stored['name'],
                'filesize' => $stored['filesize'],
                'size' => $stored['size'],
                'field_2' => $linkUrl,
            ]);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        return $mediaId;
    }
}
