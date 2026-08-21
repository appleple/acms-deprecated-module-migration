<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiff;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem;
use Acms\Plugins\DeprecatedModuleMigration\MigrationResult;
use Acms\Plugins\DeprecatedModuleMigration\MigrationStrategyInterface;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\BannerImageFileCheckerInterface;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\BannerImageMigratorInterface;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\MediaHelperBannerImageMigrator;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\Banner\PublicStorageBannerImageChecker;

/**
 * Banner → Media_Banner (ランクC・オプトイン)。
 *
 * 画像の持ち方が構造的に異なる(パス文字列 vs media_id参照)ため実データ移行を伴う。
 * 本Strategyが呼び出されること自体が「制作者が画像移行をオプトインした」ことを表す
 * (basic-design.html「5. スコープ外・非対応事項」「本ペアは既定で無効化し、管理画面上で
 * 明示的にオプトインした場合のみ画像移行バッチを実行する」を、UI/Manager側の呼び出し制御で
 * 実現する設計とし、本クラス自体には別途の内部フラグを持たせない)。
 *
 * banner_status 等はモジュール1つにつき複数スロット(配列)を持てるため、diff()の
 * プレビュー表示ではスロットごとに "media_banner_status@0" のような合成キーで
 * MigrationDiffItem を表す(あくまでUI向けの識別子であり、実際のDB上のconfig_keyは
 * "@N" を含まない。a-blog cmsの複数値configは同一config_keyの複数行をconfig_sort順に
 * 並べる方式のため、apply()は buildSlotArrays() で位置の揃った配列を再構築して書き込む)。
 */
final class MediaBannerMigrationStrategy implements MigrationStrategyInterface
{
    private const SOURCE_MODULE_NAME = 'Banner';
    private const TARGET_MODULE_NAME = 'Media_Banner';

    /**
     * モジュール単位(スロット非依存)のスカラー項目。ロジック共通のためキー名変換のみ。
     *
     * @var list<array{target: string, sourceKey: string}>
     */
    private const SCALAR_FIELD_MAP = [
        ['target' => 'media_banner_order', 'sourceKey' => 'banner_order'],
        ['target' => 'media_banner_limit', 'sourceKey' => 'banner_limit'],
        ['target' => 'media_banner_loop_class', 'sourceKey' => 'banner_loop_class'],
    ];

    /** そのままコピーするスロット単位の項目 */
    private const SLOT_COPY_FIELD_MAP = [
        ['target' => 'media_banner_datestart', 'sourceKey' => 'banner_datestart'],
        ['target' => 'media_banner_timestart', 'sourceKey' => 'banner_timestart'],
        ['target' => 'media_banner_dateend', 'sourceKey' => 'banner_dateend'],
        ['target' => 'media_banner_timeend', 'sourceKey' => 'banner_timeend'],
        ['target' => 'media_banner_link', 'sourceKey' => 'banner_url'],
        ['target' => 'media_banner_alt', 'sourceKey' => 'banner_alt'],
        ['target' => 'media_banner_attr1', 'sourceKey' => 'banner_attr1'],
        ['target' => 'media_banner_attr2', 'sourceKey' => 'banner_attr2'],
    ];

    private readonly BannerImageFileCheckerInterface $fileChecker;
    private readonly BannerImageMigratorInterface $imageMigrator;
    private readonly ModuleMigrationRepository $repository;

    public function __construct(
        ?ModuleMigrationRepository $repository = null,
        ?BannerImageFileCheckerInterface $fileChecker = null,
        ?BannerImageMigratorInterface $imageMigrator = null
    ) {
        $this->repository = $repository ?? new ModuleMigrationRepository();
        $this->fileChecker = $fileChecker ?? new PublicStorageBannerImageChecker();
        $this->imageMigrator = $imageMigrator ?? new MediaHelperBannerImageMigrator();
    }

    public function supports(): array
    {
        return [self::SOURCE_MODULE_NAME];
    }

    public function targetModuleName(string $sourceModuleName): string
    {
        return self::TARGET_MODULE_NAME;
    }

    public function rank(): string
    {
        return 'C';
    }

    public function diff(ModuleRow $module, ConfigCollection $configs): MigrationDiff
    {
        $this->assertSupported($module);

        $items = [];
        foreach (self::SCALAR_FIELD_MAP as $field) {
            $items[] = new MigrationDiffItem(
                $field['target'],
                $configs->get($field['sourceKey']),
                $configs->get($field['target']),
                $field['sourceKey']
            );
        }

        $warnings = [];
        $statuses = $configs->getArray('banner_status');
        foreach (array_keys($statuses) as $i) {
            $items = array_merge($items, $this->buildSlotItems($configs, $i, $warnings, $module->moduleBlogId));
        }

        return new MigrationDiff($module->moduleName, self::TARGET_MODULE_NAME, $items, $warnings);
    }

    /**
     * banner_status等はスロットの複数値を持つ配列configのため、apply()はdiffの
     * フラット化された項目からではなく、$configsから直接「スロット位置が揃った完全な配列」を
     * 再構築して書き込む(スカラー項目(order/limit/loop_class)のみdiffの結果を使う)。
     *
     * 理由: a-blog cmsの複数値configは「同一config_keyの複数行をconfig_sort順に並べる」
     * 方式で、位置(配列インデックス)そのものがスロット番号を表す。あるスロットで
     * banner_img が空(banner_srcのみ設定)のような「一部のキーだけ値がある」状態でも、
     * 実際の管理画面保存処理(Services/Config/Helper::fix()のbanner正規化)は全スロットに
     * 対して全キーを書き込んでおり(空文字で埋める)、位置を揃えている。diffの
     * itemsRequiringExplicitWrite()は「値があるスロットの項目だけ」を返すため、そこから
     * 配列を組み立てると欠番で位置がずれる。
     */
    public function apply(ModuleRow $module, ConfigCollection $configs, MigrationDiff $approvedDiff): MigrationResult
    {
        $this->assertSupported($module);

        $this->repository->renameModule($module->moduleId, $module->moduleBlogId, self::TARGET_MODULE_NAME);

        $written = [];
        foreach ($approvedDiff->itemsRequiringExplicitWrite() as $item) {
            if (str_contains($item->targetConfigKey, '@')) {
                continue;
            }
            $value = $this->toStorableValue($item->sourceEffectiveValue);
            $this->repository->upsertModuleConfig($module->moduleBlogId, null, $module->moduleId, $item->targetConfigKey, $value);
            $written[$item->targetConfigKey] = $value;
        }

        foreach ($this->buildSlotArrays($configs, $module->moduleBlogId) as $key => $values) {
            $this->repository->replaceModuleConfigArray($module->moduleBlogId, null, $module->moduleId, $key, $values);
            $written[$key] = $values;
        }

        $this->repository->forgetModuleConfigCache($module->moduleBlogId, null, $module->moduleId);

        return new MigrationResult(
            moduleId: $module->moduleId,
            oldModuleName: $module->moduleName,
            newModuleName: self::TARGET_MODULE_NAME,
            writtenConfig: $written,
            notes: $approvedDiff->warnings
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function buildSlotArrays(ConfigCollection $configs, int $blogId): array
    {
        $statuses = $configs->getArray('banner_status');
        $slotCount = count($statuses);
        if ($slotCount === 0) {
            return [];
        }

        $arrays = [];
        foreach (self::SLOT_COPY_FIELD_MAP as $field) {
            $arrays[$field['target']] = [];
        }
        $arrays['media_banner_status'] = [];
        $arrays['media_banner_target'] = [];
        $arrays['media_banner_mid'] = [];
        $arrays['media_banner_source'] = [];
        $arrays['media_banner_type'] = [];

        for ($i = 0; $i < $slotCount; $i++) {
            $arrays['media_banner_status'][] = ($statuses[$i] ?? null) === 'open' ? 'true' : 'false';

            foreach (self::SLOT_COPY_FIELD_MAP as $field) {
                $arrays[$field['target']][] = (string) ($configs->getArray($field['sourceKey'])[$i] ?? '');
            }

            $target = $configs->getArray('banner_target')[$i] ?? null;
            $arrays['media_banner_target'][] = $target === '_blank' ? 'true' : 'false';

            $img = $configs->getArray('banner_img')[$i] ?? null;
            $src = $configs->getArray('banner_src')[$i] ?? null;
            $hasImg = is_string($img) && $img !== '';
            $hasSrc = is_string($src) && $src !== '';

            if ($hasImg && $this->fileChecker->exists($blogId, $img)) {
                $linkUrl = (string) ($configs->getArray('banner_url')[$i] ?? '');
                $mediaId = $this->imageMigrator->migrate($blogId, $img, $linkUrl);
                $arrays['media_banner_mid'][] = (string) $mediaId;
                $arrays['media_banner_source'][] = '';
                $arrays['media_banner_type'][] = 'image';
            } elseif ($hasSrc) {
                $arrays['media_banner_mid'][] = '';
                $arrays['media_banner_source'][] = $src;
                $arrays['media_banner_type'][] = 'source';
            } else {
                $arrays['media_banner_mid'][] = '';
                $arrays['media_banner_source'][] = '';
                $arrays['media_banner_type'][] = '';
            }
        }

        return $arrays;
    }

    /**
     * @param string[] $warnings
     * @return MigrationDiffItem[]
     */
    private function buildSlotItems(ConfigCollection $configs, int $i, array &$warnings, int $blogId): array
    {
        $items = [];
        $status = $configs->getArray('banner_status')[$i] ?? null;
        $items[] = new MigrationDiffItem(
            "media_banner_status@{$i}",
            $status === 'open' ? 'true' : 'false',
            $configs->get("media_banner_status@{$i}"),
            'banner_status'
        );

        foreach (self::SLOT_COPY_FIELD_MAP as $field) {
            $sourceValue = $configs->getArray($field['sourceKey'])[$i] ?? null;
            $items[] = new MigrationDiffItem(
                "{$field['target']}@{$i}",
                $sourceValue,
                $configs->get("{$field['target']}@{$i}"),
                $field['sourceKey']
            );
        }

        $target = $configs->getArray('banner_target')[$i] ?? null;
        $items[] = new MigrationDiffItem(
            "media_banner_target@{$i}",
            $target === '_blank' ? 'true' : 'false',
            $configs->get("media_banner_target@{$i}"),
            'banner_target'
        );

        $img = $configs->getArray('banner_img')[$i] ?? null;
        $src = $configs->getArray('banner_src')[$i] ?? null;
        $hasImg = is_string($img) && $img !== '';
        $hasSrc = is_string($src) && $src !== '';

        if ($hasImg) {
            if ($this->fileChecker->exists($blogId, $img)) {
                $items[] = new MigrationDiffItem("media_banner_mid@{$i}", $img, null, 'banner_img');
                $items[] = new MigrationDiffItem("media_banner_type@{$i}", 'image', $configs->get("media_banner_type@{$i}"));
            } else {
                $warnings[] = sprintf(
                    'スロット%d: banner_img "%s" の実ファイルが見つからないため、このスロットの画像移行は対象から除外しました。',
                    $i,
                    $img
                );
            }
        } elseif ($hasSrc) {
            $items[] = new MigrationDiffItem("media_banner_source@{$i}", $src, $configs->get("media_banner_source@{$i}"), 'banner_src');
            $items[] = new MigrationDiffItem("media_banner_type@{$i}", 'source', $configs->get("media_banner_type@{$i}"));
        }

        return $items;
    }

    private function toStorableValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'on' : 'off';
        }

        return (string) $value;
    }

    private function assertSupported(ModuleRow $module): void
    {
        if (!in_array($module->moduleName, $this->supports(), true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s は %s がサポートしていない module_name です。',
                $module->moduleName,
                self::class
            ));
        }
    }
}
