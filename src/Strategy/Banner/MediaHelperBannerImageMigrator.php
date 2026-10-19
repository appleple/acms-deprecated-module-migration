<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner;

use Acms\Services\Facades\Application;
use Acms\Services\Facades\LocalStorage;
use Acms\Services\Facades\Media;
use Acms\Services\Media\Helper as MediaHelper;
use Acms\Services\Facades\PublicStorage;

/**
 * banner_img(ファイルパス文字列)を、コアのメディア登録 API(Media::registerLocalFile())で
 * Media_Banner のメディアレコードへ実データ移行する。
 *
 * registerLocalFile() は CSV インポートのメディア取り込み(Ver. 3.2.36 から)と同じ入口で、
 * 画像の保存(一覧用の縮小画像を含む)・内容ハッシュの計算・media テーブルへの登録を
 * まとめて行う。内容が同じ画像が既にメディアにあれば、新規登録せずそのメディアを再利用する。
 * 以前は Media::storeImage() と Media::insertMedia() を直接呼んでいたが、一時ファイルに
 * 拡張子が無く、一覧用の縮小画像(tiny-)が作られないせいで、メディアの一覧と編集画面の
 * サムネイルが空になっていた。ハッシュも登録されていなかった。
 *
 * Why not MediaSourceImporter(media.source-importer): 取り込める場所が CSV インポート専用の
 * ディレクトリ(archives/csv-import/source/)に限られ、Banner の画像が置かれている
 * archives/ 配下の任意のパスを渡せない。
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

        $tmpFile = self::temporaryFilePath($relativePath);

        try {
            $contents = PublicStorage::get(ARCHIVES_DIR . $relativePath);
            if ($contents === false) {
                throw new \RuntimeException("banner_img の読み込みに失敗しました: {$relativePath}");
            }
            LocalStorage::put($tmpFile, $contents);

            $filename = LocalStorage::mbBasename($relativePath);
            // 内容が同じ既存メディアが再利用される場合、そのメディア自身のリンク先は上書きしない
            $media = Application::make('media');
            assert($media instanceof MediaHelper);
            $isReused = $media->previewRegisterLocalFile($tmpFile, $filename)->isDuplicate();
            $mediaId = $media->registerLocalFile($tmpFile, $filename);

            if (!$isReused && $linkUrl !== '') {
                Media::updateMedia($mediaId, ['field_2' => $linkUrl]);
            }
        } finally {
            LocalStorage::remove($tmpFile);
        }

        return $mediaId;
    }

    /**
     * コアへ渡す一時ファイルのパスを返す。元のファイルの拡張子を保つ。
     *
     * Why not tempnam(): 拡張子の無い一時ファイルを渡すと、コアが縮小画像を作れず、
     * メディアの一覧と編集画面のサムネイルが空になる。
     */
    public static function temporaryFilePath(string $relativePath): string
    {
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.' . $extension : '';

        return rtrim(sys_get_temp_dir(), '/') . '/acms_banner_migration_' . bin2hex(random_bytes(8)) . $suffix;
    }
}
