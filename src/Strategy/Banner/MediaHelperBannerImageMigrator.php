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
 * 登録先のブログ: コアの Ver. 3.2.39 から、registerLocalFile() に登録先のブログ ID を渡せる。
 * それより前のコアは media_blog_id に定数 BID(現在の管理画面のブログ)をそのまま使い、
 * 指定する経路が無い。グローバル定数を書き換えるのは副作用が大きく安全でないため、
 * 登録先を指定できないコアでは、$blogId が現在のブログ(BID)と一致しない場合に
 * 「別ブログのメディアとして誤登録される」事故を避けるため実行を拒否する。
 * 指定できるかどうかは、メソッドの引数を調べて判断する(バージョン番号には頼らない)。
 */
final class MediaHelperBannerImageMigrator implements BannerImageMigratorInterface
{
    public function migrate(int $blogId, string $relativePath, string $linkUrl): int
    {
        if (!SafeRelativePath::isSafe($relativePath)) {
            throw new \RuntimeException("banner_img が不正なパスのため移行できません: {$relativePath}");
        }
        $media = Application::make('media');
        assert($media instanceof MediaHelper);
        $coreSupportsBlogId = self::coreSupportsBlogId($media);
        if (!self::canRegisterForBlog($blogId, BID, $coreSupportsBlogId)) {
            throw new \RuntimeException(
                '現在の管理画面コンテキストと異なるブログのBanner画像移行は、お使いのa-blog cmsではサポートしていません。'
                    . 'Ver. 3.2.39 以降にアップデートするか、このブログの管理画面から実行してください。'
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
            if ($coreSupportsBlogId) {
                $isReused = $media->previewRegisterLocalFile($tmpFile, $filename, $blogId)->isDuplicate();
                $mediaId = $media->registerLocalFile($tmpFile, $filename, $blogId);
            } else {
                $isReused = $media->previewRegisterLocalFile($tmpFile, $filename)->isDuplicate();
                $mediaId = $media->registerLocalFile($tmpFile, $filename);
            }

            if (!$isReused && $linkUrl !== '') {
                Media::updateMedia($mediaId, ['field_2' => $linkUrl]);
            }
        } finally {
            LocalStorage::remove($tmpFile);
        }

        return $mediaId;
    }

    /**
     * 移行先のブログへメディアを登録できるか。
     *
     * 今のブログと同じなら、コアが登録先を指定できなくても登録できる。別のブログは、
     * コアが登録先のブログを指定できるときだけ登録できる。
     */
    public static function canRegisterForBlog(int $blogId, int $currentBlogId, bool $coreSupportsBlogId): bool
    {
        return $blogId === $currentBlogId || $coreSupportsBlogId;
    }

    /**
     * コアの Media::registerLocalFile() / previewRegisterLocalFile() が、登録先のブログ ID を
     * 引数で受け取れるか。受け取れない版(Ver. 3.2.38 まで)では引数が2つしかない。
     */
    private static function coreSupportsBlogId(MediaHelper $media): bool
    {
        foreach (['registerLocalFile', 'previewRegisterLocalFile'] as $method) {
            if ((new \ReflectionMethod($media, $method))->getNumberOfParameters() < 3) {
                return false;
            }
        }

        return true;
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
