<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiff;
use Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem;
use Acms\Plugins\DeprecatedModuleMigration\MigrationResult;
use Acms\Plugins\DeprecatedModuleMigration\MigrationStrategyInterface;
use Acms\Plugins\DeprecatedModuleMigration\ModuleMigrationRepository;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;

/**
 * Entry_Headline / Entry_List / Entry_Photo → Entry_Summary (ランクB)。
 *
 * 3モジュールともEntry_Summaryを継承しクエリ構築・テンプレート変数体系は共通だが、
 * 取得件数の既定値差・コード固定の表示項目(fulltext・タグ出力・カテゴリー/ユーザー/
 * ブログ情報出力等)があるため、machine-readableな対応表(FIELD_MAPS)で
 * モジュール種別ごとの「実効値ベースの差分計算」を行う
 * (detailed-design.html「詳細マッピング: Entry_Headline/List/Photo → Entry_Summary」参照)。
 */
final class EntrySummaryMigrationStrategy implements MigrationStrategyInterface
{
    private const TARGET_MODULE_NAME = 'Entry_Summary';

    /**
     * Entry_Headlineのみ、URLパラメータscope系カラムをコード側で 'global' に固定上書きしている
     * (旧 ACMS_GET_Entry_Headline::$_scope)。移行時は未設定(local)のままだと挙動が変わるため、
     * 明示的にglobalへ設定してから変換する。
     *
     * 'order'(module_order_scope)は実コードの$_scopeに含まれておらず、DBカラムの値がそのまま
     * 実効scopeとして使われる(ACMS_GET::exec()のフォールバック順序を実コードで確認済み)。
     * ここに含めてglobal強制すると、旧モジュールで効いていなかった場面までorderがglobalとして
     * 動くようになり、意図しない挙動変化(公開側の並び順変化)を招くため対象外とする。
     */
    private const SCOPE_COLUMNS = [
        'module_uid_scope',
        'module_cid_scope',
        'module_eid_scope',
        'module_keyword_scope',
        'module_tag_scope',
        'module_field_scope',
        'module_start_scope',
        'module_end_scope',
        'module_page_scope',
    ];

    /**
     * モジュール種別ごとの「新config key => 旧の実効値の求め方」対応表。
     *
     * - sourceKey が設定されている項目は、旧モジュールにも同種のconfigキーが存在し、
     *   config行が無ければ sourceDefault (旧モジュールの規定値) にフォールバックする。
     * - sourceHardcoded のみの項目は、旧モジュールにそもそもconfigキー自体が存在せず、
     *   コード上の固定表示(true/false相当)を表す。
     *
     * @var array<string, list<array{target: string, sourceKey?: string, sourceDefault?: mixed, sourceHardcoded?: mixed}>>
     */
    private const FIELD_MAPS = [
        'Entry_Headline' => [
            ['target' => 'entry_summary_order', 'sourceKey' => 'entry_headline_order', 'sourceDefault' => 'datetime-desc'],
            ['target' => 'entry_summary_limit', 'sourceKey' => 'entry_headline_limit', 'sourceDefault' => 5],
            // pager_on・notfound は Entry_Headline では実は設定可能キー(コード上の固定値ではない)。
            // 旧ACMS_GET_Entry_Headline::initConfig()を実コードで確認済み(design docの「キー無し
            // (固定off)」記載は誤り)。config行が無い場合の実効値は既定off相当(configの素の呼び出しが
            // 空文字を返し'on'と一致しないためfalse)なのでsourceDefaultは従来通りoffを維持する。
            ['target' => 'entry_summary_pager_on', 'sourceKey' => 'entry_headline_pager_on', 'sourceDefault' => 'off'],
            // simple_pager_onはEntry_Headlineでは設定可能キー(実コードのconfig('entry_headline_simple_pager_on')
            // を確認済み)。Entry_List/Entry_Photoにはこの概念自体が存在しない。
            ['target' => 'entry_summary_simple_pager_on', 'sourceKey' => 'entry_headline_simple_pager_on', 'sourceDefault' => 'off'],
            ['target' => 'mo_entry_summary_notfound', 'sourceKey' => 'mo_entry_headline_notfound', 'sourceDefault' => 'off'],
            // unit・noimageはEntry_Headlineでも実コード上の設定可能キー(initConfig()確認済み)。
            // 旧既定値(1・on)がEntry_Summary既定値(3・on)と食い違うキーがあるため個別にマッピングする。
            ['target' => 'entry_summary_unit', 'sourceKey' => 'entry_headline_unit', 'sourceDefault' => 1],
            ['target' => 'entry_summary_noimage', 'sourceKey' => 'entry_headline_noimage', 'sourceDefault' => 'on'],
            // mainImageTargetはEntry_Headlineでは実コード上 'unit' に固定されている(config()経由ではない。
            // ACMS_GET_Entry_Headline::initConfig()確認済み)。Entry_Summary既定値は'field'のため、
            // 未対応のままだと画像の取得元が黙って変わる。
            ['target' => 'entry_summary_main_image_target', 'sourceHardcoded' => 'unit'],
            ['target' => 'entry_summary_main_image_field_name', 'sourceHardcoded' => ''],
            ['target' => 'entry_summary_image_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_category_on', 'sourceHardcoded' => 'on'],
            ['target' => 'entry_summary_blog_on', 'sourceHardcoded' => 'on'],
            ['target' => 'entry_summary_user_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_fulltext', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_related_entry_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_tag', 'sourceHardcoded' => 'off'],
        ],
        'Entry_List' => [
            ['target' => 'entry_summary_order', 'sourceKey' => 'entry_list_order', 'sourceDefault' => 'datetime-desc'],
            ['target' => 'entry_summary_limit', 'sourceKey' => 'entry_list_limit', 'sourceDefault' => 10],
            // pager_onはEntry_Listでは実コード上も固定false(ACMS_GET_Entry_List::initConfig()確認済み)。
            ['target' => 'entry_summary_pager_on', 'sourceHardcoded' => 'off'],
            // simple_pager機能自体がEntry_Listには存在しない(実コード確認済み)。
            ['target' => 'entry_summary_simple_pager_on', 'sourceHardcoded' => 'off'],
            // notfoundはEntry_Listでは設定可能キー(mo_entry_list_notfound)。Entry_Headlineと同様の理由。
            ['target' => 'mo_entry_summary_notfound', 'sourceKey' => 'mo_entry_list_notfound', 'sourceDefault' => 'off'],
            // unit・noimageはEntry_Listでも実コード上の設定可能キー。Entry_Headlineと同様の理由。
            ['target' => 'entry_summary_unit', 'sourceKey' => 'entry_list_unit', 'sourceDefault' => 1],
            ['target' => 'entry_summary_noimage', 'sourceKey' => 'entry_list_noimage', 'sourceDefault' => 'on'],
            // mainImageTargetはEntry_Listでも実コード上 'unit' に固定されている
            // (ACMS_GET_Entry_List::initConfig()確認済み)。Entry_Headlineと同様の理由。
            ['target' => 'entry_summary_main_image_target', 'sourceHardcoded' => 'unit'],
            ['target' => 'entry_summary_main_image_field_name', 'sourceHardcoded' => ''],
            ['target' => 'entry_summary_image_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_category_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_blog_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_user_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_fulltext', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_related_entry_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_tag', 'sourceHardcoded' => 'off'],
        ],
        'Entry_Photo' => [
            ['target' => 'entry_summary_order', 'sourceKey' => 'entry_photo_order', 'sourceDefault' => 'datetime-desc'],
            ['target' => 'entry_summary_limit', 'sourceKey' => 'entry_photo_limit', 'sourceDefault' => 3],
            // pager_onはEntry_Photoでは実コード上も固定true(ACMS_GET_Entry_Photo::initConfig()確認済み)。
            ['target' => 'entry_summary_pager_on', 'sourceHardcoded' => 'on'],
            // simple_pager機能自体がEntry_Photoには存在しない(実コード確認済み)。
            ['target' => 'entry_summary_simple_pager_on', 'sourceHardcoded' => 'off'],
            // notfoundはEntry_Photoでは設定可能キー(mo_entry_photo_notfound)。
            ['target' => 'mo_entry_summary_notfound', 'sourceKey' => 'mo_entry_photo_notfound', 'sourceDefault' => 'off'],
            ['target' => 'entry_summary_unit', 'sourceKey' => 'entry_photo_unit', 'sourceDefault' => 1],
            // noimage・main_image_target/field_name・image_x/y/trim/zoom/centerはEntry_Photoが
            // 画像表示を主体とするモジュールであるため実コード上も設定可能キー
            // (ACMS_GET_Entry_Photo::initConfig()確認済み)。Entry_Headline/Entry_Listは
            // これらのconfigキー自体を参照するコードが無いため対象外(FIELD_MAPSに含めない)。
            ['target' => 'entry_summary_noimage', 'sourceKey' => 'entry_photo_noimage', 'sourceDefault' => 'off'],
            ['target' => 'entry_summary_main_image_target', 'sourceKey' => 'entry_photo_main_image_target', 'sourceDefault' => 'field'],
            ['target' => 'entry_summary_main_image_field_name', 'sourceKey' => 'entry_photo_main_image_field_name', 'sourceDefault' => ''],
            ['target' => 'entry_summary_image_x', 'sourceKey' => 'entry_photo_image_x', 'sourceDefault' => 160],
            ['target' => 'entry_summary_image_y', 'sourceKey' => 'entry_photo_image_y', 'sourceDefault' => 160],
            ['target' => 'entry_summary_image_trim', 'sourceKey' => 'entry_photo_image_trim', 'sourceDefault' => 'off'],
            ['target' => 'entry_summary_image_zoom', 'sourceKey' => 'entry_photo_image_zoom', 'sourceDefault' => 'off'],
            ['target' => 'entry_summary_image_center', 'sourceKey' => 'entry_photo_image_center', 'sourceDefault' => 'on'],
            ['target' => 'entry_summary_image_on', 'sourceHardcoded' => 'on'],
            ['target' => 'entry_summary_category_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_blog_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_user_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_fulltext', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_related_entry_on', 'sourceHardcoded' => 'off'],
            ['target' => 'entry_summary_tag', 'sourceHardcoded' => 'off'],
        ],
    ];

    /**
     * FIELD_MAPSでは移行しきれない設定可能キー(実コード上は存在するが対応表を持たない項目)。
     * 値がシステム既定値と異なる場合は「自動移行の対象外」として警告する安全網とする
     * (旧ACMS_GET_Entry_Headline/List/Photo::initConfig()を実コードで確認して列挙。
     * order2・field_name・offset・loop_class・newtime・indexing・members_only・sub_category・
     * secret・notfound_status_404・hidden_current/private_entry・pager_delta・pager_cur_attrは
     * 3モジュール共通。unit・noimage・main_image_target・main_image_field_name・
     * image_x/y/trim/zoom/centerは新旧で既定値が食い違いうるためFIELD_MAPSへ実マッピング済み
     * (unit: 1→3、Entry_Photoのnoimage: off→on 等。旧既定のままでも黙って挙動が変わるのを防ぐ)。
     *
     * @var list<string>
     */
    private const UNMAPPED_KNOWN_KEY_SUFFIXES = [
        'order2', 'order_field_name', 'no_narrow_down_sort', 'offset', 'loop_class',
        'newtime', 'indexing', 'members_only', 'sub_category', 'secret', 'notfound_status_404',
        'hidden_current_entry', 'hidden_private_entry', 'pager_delta', 'pager_cur_attr',
    ];


    public function __construct(private readonly ModuleMigrationRepository $repository = new ModuleMigrationRepository())
    {
    }

    public function supports(): array
    {
        return array_keys(self::FIELD_MAPS);
    }

    public function targetModuleName(string $sourceModuleName): string
    {
        return self::TARGET_MODULE_NAME;
    }

    public function rank(): string
    {
        return 'B';
    }

    public function diff(ModuleRow $module, ConfigCollection $configs): MigrationDiff
    {
        $this->assertSupported($module);

        $items = [];
        foreach (self::FIELD_MAPS[$module->moduleName] as $field) {
            $sourceValue = $this->resolveSourceValue($field, $configs);
            $targetDefault = $configs->get($field['target']);
            $items[] = new MigrationDiffItem($field['target'], $sourceValue, $targetDefault, $field['sourceKey'] ?? null);
        }

        $warnings = $this->unmappedKeyWarnings($module->moduleName, $configs);
        if ($module->moduleName === 'Entry_Headline') {
            $nonGlobalColumns = $this->nonGlobalScopeColumns($module);
            if ($nonGlobalColumns !== []) {
                $warnings[] = $this->scopeWarningMessage($nonGlobalColumns);
            }
        }

        return new MigrationDiff($module->moduleName, self::TARGET_MODULE_NAME, $items, $warnings);
    }

    public function apply(ModuleRow $module, ConfigCollection $configs, MigrationDiff $approvedDiff): MigrationResult
    {
        $this->assertSupported($module);

        $this->repository->renameModule($module->moduleId, $module->moduleBlogId, self::TARGET_MODULE_NAME);

        $written = $this->writeConfigItems($module, null, $approvedDiff);

        $notes = [];
        if ($module->moduleName === 'Entry_Headline') {
            $nonGlobalColumns = $this->nonGlobalScopeColumns($module);
            if ($nonGlobalColumns !== []) {
                $this->repository->forceGlobalScope($module->moduleId, $module->moduleBlogId, $nonGlobalColumns);
                $notes[] = sprintf(
                    'URLパラメータscopeを明示的にglobalへ設定しました: %s',
                    implode(', ', $nonGlobalColumns)
                );
            }
        }

        return new MigrationResult($module->moduleId, $module->moduleName, self::TARGET_MODULE_NAME, $written, $notes);
    }

    public function applyForRule(ModuleRow $module, ConfigCollection $configs, MigrationDiff $ruleDiff, int $ruleId): void
    {
        $this->assertSupported($module);

        if ($ruleDiff->isBlocked()) {
            return;
        }

        $this->writeConfigItems($module, $ruleId, $ruleDiff);
    }

    /**
     * @return array<string, string>
     */
    private function writeConfigItems(ModuleRow $module, ?int $ruleId, MigrationDiff $diff): array
    {
        $written = [];
        foreach ($diff->itemsRequiringExplicitWrite() as $item) {
            $value = $this->toStorableValue($item->sourceEffectiveValue);
            $this->repository->upsertModuleConfig(
                $module->moduleBlogId,
                $ruleId,
                $module->moduleId,
                $item->targetConfigKey,
                $value
            );
            $written[$item->targetConfigKey] = $value;
        }

        $this->repository->forgetModuleConfigCache($module->moduleBlogId, $ruleId, $module->moduleId);

        return $written;
    }

    /**
     * @param array{target: string, sourceKey?: string, sourceDefault?: mixed, sourceHardcoded?: mixed} $field
     */
    private function resolveSourceValue(array $field, ConfigCollection $configs): mixed
    {
        if (array_key_exists('sourceKey', $field)) {
            return $configs->get($field['sourceKey'], $field['sourceDefault'] ?? null);
        }

        return $field['sourceHardcoded'] ?? null;
    }

    /**
     * FIELD_MAPSで移行対象にしていない設定可能キーに、既定でない値が入っている場合は
     * 「自動移行の対象外」として警告する(High優先度の安全網。全キーを網羅した完全対応は
     * V1のスコープ外とし、値が捨てられることに制作者が気付けるようにする)。
     *
     * @return string[]
     */
    private function unmappedKeyWarnings(string $sourceModuleName, ConfigCollection $configs): array
    {
        $prefix = 'entry_' . strtolower(substr($sourceModuleName, strlen('Entry_')));
        $keys = array_map(
            static fn (string $suffix): string => "{$prefix}_{$suffix}",
            self::UNMAPPED_KNOWN_KEY_SUFFIXES
        );

        $nonDefaultKeys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => $configs->differsFromDefault($key)
        ));

        if ($nonDefaultKeys === []) {
            return [];
        }

        return [sprintf(
            '以下の設定キーは自動移行の対象外です。値が設定されているため、Entry_Summary側で手動確認してください: %s',
            implode(', ', $nonDefaultKeys)
        )
        ];
    }

    /**
     * @return string[]
     */
    private function nonGlobalScopeColumns(ModuleRow $module): array
    {
        $columns = [];
        foreach (self::SCOPE_COLUMNS as $column) {
            if (!array_key_exists($column, $module->scopeColumns)) {
                continue;
            }
            if ($module->scopeColumns[$column] !== 'global') {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param string[] $columns
     */
    private function scopeWarningMessage(array $columns): string
    {
        return sprintf(
            'Entry_Headlineは全項目のURLパラメータscopeをglobalに固定しているため、'
                . '以下のscopeカラムを明示的にglobalへ設定してから変換します: %s',
            implode(', ', $columns)
        );
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
