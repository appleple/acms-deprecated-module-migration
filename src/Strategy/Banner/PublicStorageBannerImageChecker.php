<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner;

use Acms\Services\Facades\PublicStorage;

/**
 * banner_img の実ファイルを、公開ストレージ(ARCHIVES_DIR配下)から実在チェックする。
 *
 * banner_img は管理画面から自由入力できたconfig値のため、"../" 等のパストラバーサル入力を
 * 拒否してからストレージAPIへ渡す(ARCHIVES_DIR外の非公開ファイルを移行対象にできてしまう
 * 事故を防ぐ。CMS-7815系のユニットパストラバーサル対応と同様の考え方)。
 */
final class PublicStorageBannerImageChecker implements BannerImageFileCheckerInterface
{
    public function exists(int $blogId, string $relativePath): bool
    {
        if (!PublicStorage::isSafeRelativePath($relativePath)) {
            return false;
        }

        return PublicStorage::exists(ARCHIVES_DIR . $relativePath);
    }
}
