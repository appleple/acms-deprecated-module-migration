<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner;

use Acms\Services\Facades\Database as DB;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Media;
use Acms\Services\Facades\PublicStorage;
use SQL;

/**
 * banner_img(ファイルパス文字列)を、既存のメディア登録経路
 * (Media::storeImage() / Media::insertMedia())を通じてMedia_Bannerのメディアレコードへ
 * 実データ移行する。
 *
 * 独自にmediaテーブルへ直接INSERTすると必須カラムの欠落・不整合のリスクが高いため、
 * 既存の画像最適化・サムネイル生成ロジックをそのまま再利用する
 * (detailed-design.html「画像移行バッチの処理フロー」参照)。Mediaファサードは
 * DIコンテナが保持するシングルトンを解決するため、`new Media\Helper()` で直接
 * インスタンス化せずファサード経由で呼ぶ(本体のサービス層規約と同じ)。
 *
 * 既知の制約: Media::insertMedia() は media_blog_id にグローバル定数 BID
 * (現在のリクエストコンテキストのブログ)をそのまま使う実装になっており、移行対象の
 * $blogId を明示的に指定する経路が無い。グローバル定数を書き換えるのは副作用が大きく
 * 安全でないため、$blogId が現在の管理画面コンテキスト(BID)と一致しない場合は
 * 「別ブログのメディアとして誤登録される」事故を避けるため実行を拒否する。
 */
final class MediaHelperBannerImageMigrator implements BannerImageMigratorInterface
{
    public function migrate(int $blogId, string $relativePath, string $linkUrl): int
    {
        if (!SafeRelativePath::isSafe($relativePath)) {
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
            LocalStorage::put($tmpFile, $contents);

            $stored = Media::storeImage($tmpFile, LocalStorage::mbBasename($relativePath));

            Media::insertMedia($mediaId, [
                'type' => 'image',
                'extension' => $stored['type'],
                'path' => $stored['path'],
                'name' => $stored['name'],
                'filesize' => $stored['filesize'],
                'size' => $stored['size'],
                'field_2' => $linkUrl,
            ]);
        } finally {
            LocalStorage::remove($tmpFile);
        }

        return $mediaId;
    }
}
