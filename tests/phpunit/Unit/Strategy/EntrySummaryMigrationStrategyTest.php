<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Tests\Unit\Strategy;

use Acms\Plugins\DeprecatedModuleMigration\ConfigCollection;
use Acms\Plugins\DeprecatedModuleMigration\ModuleRow;
use Acms\Plugins\DeprecatedModuleMigration\Strategy\EntrySummaryMigrationStrategy;
use Acms\TestingFramework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\EntrySummaryMigrationStrategy
 *
 * Entry_Summary側の実効値(既定値)は Config::loadModuleConfig() が解決した「システム既定値込みの
 * 実効値」を想定し、実際の config.system.default.yaml の値をそのままテストの ConfigCollection に
 * 与える(detailed-design.html「2. 設計原則: 実効値ベースの差分計算」)。
 */
#[CoversClass(EntrySummaryMigrationStrategy::class)]
class EntrySummaryMigrationStrategyTest extends TestCase
{
    private EntrySummaryMigrationStrategy $strategy;

    /** Entry_Summary側の実効値(config.system.default.yaml準拠)。3モジュール共通。 */
    private const TARGET_DEFAULTS = [
        'entry_summary_order' => 'datetime-desc',
        'entry_summary_limit' => 6,
        'entry_summary_pager_on' => 'on',
        'entry_summary_simple_pager_on' => 'off',
        'mo_entry_summary_notfound' => 'on',
        'entry_summary_unit' => 3,
        'entry_summary_noimage' => 'on',
        'entry_summary_main_image_target' => 'field',
        'entry_summary_main_image_field_name' => '',
        'entry_summary_image_x' => 100,
        'entry_summary_image_y' => 100,
        'entry_summary_image_trim' => 'on',
        'entry_summary_image_zoom' => 'off',
        'entry_summary_image_center' => 'off',
        'entry_summary_image_on' => 'on',
        'entry_summary_category_on' => 'on',
        'entry_summary_blog_on' => 'off',
        'entry_summary_user_on' => 'off',
        'entry_summary_fulltext' => 'on',
        'entry_summary_related_entry_on' => 'off',
        'entry_summary_tag' => 'on',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new EntrySummaryMigrationStrategy();
    }

    #[Test]
    #[TestDox('supports()は3つの旧モジュール名を返す')]
    public function supportsReturnsThreeSourceModules(): void
    {
        $this->assertSame(['Entry_Headline', 'Entry_List', 'Entry_Photo'], $this->strategy->supports());
    }

    #[Test]
    #[TestDox('rank()はBを返す')]
    public function rankIsB(): void
    {
        $this->assertSame('B', $this->strategy->rank());
    }

    #[Test]
    #[TestDox('targetModuleName()は常にEntry_Summaryを返す')]
    public function targetModuleNameIsAlwaysEntrySummary(): void
    {
        $this->assertSame('Entry_Summary', $this->strategy->targetModuleName('Entry_Headline'));
        $this->assertSame('Entry_Summary', $this->strategy->targetModuleName('Entry_List'));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function moduleFixtureProvider(): array
    {
        return [
            // Entry_PhotoのみFIELD_MAPSに画像系5キー(image_x/y/trim/zoom/center)が追加でマッピングされる。
            'Entry_Headline' => ['Entry_Headline', 16],
            'Entry_List' => ['Entry_List', 16],
            'Entry_Photo' => ['Entry_Photo', 21],
        ];
    }

    #[Test]
    #[TestDox('diff()はconfig行が存在しないブログでも実効値ベースで全項目を評価する')]
    #[DataProvider('moduleFixtureProvider')]
    public function diffEvaluatesAllItemsFromEffectiveValues(string $sourceModuleName, int $expectedItemCount): void
    {
        $module = new ModuleRow(1, 'mod_1', $sourceModuleName, 1, 'local');
        $configs = ConfigCollection::fromArray(self::TARGET_DEFAULTS);

        $diff = $this->strategy->diff($module, $configs);

        $this->assertSame($sourceModuleName, $diff->sourceModuleName);
        $this->assertSame('Entry_Summary', $diff->targetModuleName);
        $this->assertCount($expectedItemCount, $diff->items);
    }

    #[Test]
    #[TestDox('Entry_Headline: simple_pager_onは実コード上も設定可能キーのため、旧設定値をそのままEntry_Summaryへ引き継ぐ')]
    public function headlineSimplePagerOnIsMappedToItsOwnSourceKey(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'entry_headline_simple_pager_on' => 'on',
        ]));

        $diff = $this->strategy->diff($module, $configs);
        $item = $this->findItem($diff, 'entry_summary_simple_pager_on');

        $this->assertSame('on', $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_List/Entry_Photo: simple_pager機能自体が存在しないため、Entry_Summary既定値(off)と一致し書き込み不要')]
    public function listAndPhotoSimplePagerOnMatchesTargetDefault(): void
    {
        foreach (['Entry_List', 'Entry_Photo'] as $moduleName) {
            $module = new ModuleRow(1, 'mod_1', $moduleName, 1, 'local');
            $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

            $this->assertFalse(
                $this->findItem($diff, 'entry_summary_simple_pager_on')->requiresExplicitWrite(),
                "{$moduleName} は simple_pager_on を書き込み不要と判定すべき"
            );
        }
    }

    #[Test]
    #[TestDox('unit: 旧既定値1と新既定値3が異なるため、3モジュールとも明示書き込みが必要')]
    #[DataProvider('moduleFixtureProvider')]
    public function unitRequiresExplicitWriteAcrossAllModules(string $sourceModuleName): void
    {
        $module = new ModuleRow(1, 'mod_1', $sourceModuleName, 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));
        $item = $this->findItem($diff, 'entry_summary_unit');

        $this->assertSame(1, $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline/Entry_List: noimageは旧既定on・新既定onで一致するため書き込み不要')]
    public function headlineAndListNoimageMatchesTargetDefault(): void
    {
        foreach (['Entry_Headline', 'Entry_List'] as $moduleName) {
            $module = new ModuleRow(1, 'mod_1', $moduleName, 1, 'local');
            $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

            $this->assertFalse(
                $this->findItem($diff, 'entry_summary_noimage')->requiresExplicitWrite(),
                "{$moduleName} は noimage を書き込み不要と判定すべき"
            );
        }
    }

    #[Test]
    #[TestDox('Entry_Photo: noimageは旧既定off・新既定onと異なるため明示書き込みが必要')]
    public function photoNoimageRequiresExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Photo', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));
        $item = $this->findItem($diff, 'entry_summary_noimage');

        $this->assertSame('off', $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline/Entry_List: mainImageTargetは実コード上\'unit\'に固定されており、新既定\'field\'と異なるため明示書き込みが必要')]
    public function headlineAndListMainImageTargetRequiresExplicitWrite(): void
    {
        foreach (['Entry_Headline', 'Entry_List'] as $moduleName) {
            $module = new ModuleRow(1, 'mod_1', $moduleName, 1, 'local');
            $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));
            $item = $this->findItem($diff, 'entry_summary_main_image_target');

            $this->assertSame('unit', $item->sourceEffectiveValue, "{$moduleName} のmainImageTargetは'unit'固定のはず");
            $this->assertTrue($item->requiresExplicitWrite(), "{$moduleName} は明示書き込みが必要なはず");
        }
    }

    #[Test]
    #[TestDox('Entry_Photo: mainImageTargetは旧既定・新既定ともfieldで一致するため書き込み不要')]
    public function photoMainImageTargetMatchesTargetDefault(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Photo', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertFalse($this->findItem($diff, 'entry_summary_main_image_target')->requiresExplicitWrite());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function photoImageSettingMismatchProvider(): array
    {
        return [
            'image_x: 160→100' => ['entry_summary_image_x', 160],
            'image_y: 160→100' => ['entry_summary_image_y', 160],
            'image_trim: off→on' => ['entry_summary_image_trim', 'off'],
            'image_center: on→off' => ['entry_summary_image_center', 'on'],
        ];
    }

    #[Test]
    #[TestDox('Entry_Photo: 画像設定は新旧で既定値が異なるため明示書き込みが必要')]
    #[DataProvider('photoImageSettingMismatchProvider')]
    public function photoImageSettingsRequireExplicitWrite(string $targetConfigKey, mixed $expectedSourceValue): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Photo', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));
        $item = $this->findItem($diff, $targetConfigKey);

        $this->assertSame($expectedSourceValue, $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Photo: image_zoomは旧既定・新既定ともoffで一致するため書き込み不要')]
    public function photoImageZoomMatchesTargetDefault(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Photo', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertFalse($this->findItem($diff, 'entry_summary_image_zoom')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline: limitは旧既定値5とEntry_Summary既定値6が異なるため明示書き込みが必要')]
    public function headlineLimitRequiresExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $configs = ConfigCollection::fromArray(self::TARGET_DEFAULTS);

        $diff = $this->strategy->diff($module, $configs);
        $limitItem = $this->findItem($diff, 'entry_summary_limit');

        $this->assertSame(5, $limitItem->sourceEffectiveValue);
        $this->assertTrue($limitItem->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline: limitのconfig行が実在し旧実効値が6ならEntry_Summary既定値と一致し書き込み不要')]
    public function headlineLimitDoesNotRequireWriteWhenEffectiveValueMatchesTarget(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'entry_headline_limit' => 6,
        ]));

        $diff = $this->strategy->diff($module, $configs);
        $limitItem = $this->findItem($diff, 'entry_summary_limit');

        $this->assertFalse($limitItem->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline: fulltextは機能自体が存在しないため既定onと差分になり明示offの書き込みが必要')]
    public function headlineFulltextRequiresExplicitOff(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $configs = ConfigCollection::fromArray(self::TARGET_DEFAULTS);

        $diff = $this->strategy->diff($module, $configs);
        $item = $this->findItem($diff, 'entry_summary_fulltext');

        $this->assertSame('off', $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline: tagは機能自体が存在しないため既定onと差分になり明示offの書き込みが必要')]
    public function headlineTagRequiresExplicitOff(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertTrue($this->findItem($diff, 'entry_summary_tag')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline: categoryは旧true・新既定onで一致するため書き込み不要')]
    public function headlineCategoryOnDoesNotRequireWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertFalse($this->findItem($diff, 'entry_summary_category_on')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline: blogは旧true・新既定offで不一致のため明示onの書き込みが必要')]
    public function headlineBlogOnRequiresExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));
        $item = $this->findItem($diff, 'entry_summary_blog_on');

        $this->assertSame('on', $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_List: categoryは旧false・新既定onで不一致のため明示offの書き込みが必要')]
    public function listCategoryOnRequiresExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_List', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));
        $item = $this->findItem($diff, 'entry_summary_category_on');

        $this->assertSame('off', $item->sourceEffectiveValue);
        $this->assertTrue($item->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_List: blogは旧false・新既定offで一致するため書き込み不要')]
    public function listBlogOnDoesNotRequireWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_List', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertFalse($this->findItem($diff, 'entry_summary_blog_on')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Photo: image_onは旧true・新既定onで一致するため書き込み不要')]
    public function photoImageOnDoesNotRequireWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Photo', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertFalse($this->findItem($diff, 'entry_summary_image_on')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Photo: pager_onは旧true・新既定onで一致するため書き込み不要')]
    public function photoPagerOnDoesNotRequireWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Photo', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertFalse($this->findItem($diff, 'entry_summary_pager_on')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headline: pager_onは旧false・新既定onで不一致のため明示offの書き込みが必要')]
    public function headlinePagerOnRequiresExplicitWrite(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertTrue($this->findItem($diff, 'entry_summary_pager_on')->requiresExplicitWrite());
    }

    #[Test]
    #[TestDox('Entry_Headlineでscopeカラムが未設定(local)の場合、警告にscopeカラム名が含まれる')]
    public function headlineDiffWarnsAboutNonGlobalScopeColumns(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local', [
            'module_cid_scope' => 'local',
            'module_uid_scope' => 'global',
        ]);

        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertCount(1, $diff->warnings);
        $this->assertStringContainsString('module_cid_scope', $diff->warnings[0]);
        $this->assertStringNotContainsString('module_uid_scope', $diff->warnings[0]);
    }

    #[Test]
    #[TestDox('Entry_Headlineはorderをscope固定していない(実コード$_scopeに存在しない)ため、module_order_scopeがlocalでも警告・強制対象にしない')]
    public function headlineDoesNotForceOrderScopeToGlobal(): void
    {
        // ACMS_GET_Entry_Headline::$_scope (実コード) は uid/cid/eid/keyword/tag/field/start/end/page の
        // 9項目のみをglobal固定しており、orderは含まれない。module_order_scopeがlocalのままでも
        // 移行対象外(=警告・強制対象に含めない)と判定すべき。
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local', [
            'module_order_scope' => 'local',
        ]);

        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertSame([], $diff->warnings);
    }

    #[Test]
    #[TestDox('Entry_Headlineでscopeカラムが全てglobalの場合は警告を出さない')]
    public function headlineDiffHasNoWarningWhenAllScopeColumnsAreGlobal(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local', [
            'module_cid_scope' => 'global',
        ]);

        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertSame([], $diff->warnings);
    }

    #[Test]
    #[TestDox('Entry_List/Entry_Photoではscopeカラムの警告は発生しない')]
    public function nonHeadlineModulesNeverWarnAboutScope(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_List', 1, 'local', [
            'module_cid_scope' => 'local',
        ]);

        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertSame([], $diff->warnings);
    }

    #[Test]
    #[TestDox('Entry_Headline: 未対応キー(offsetやunit等)に値が設定されている場合、警告を出す')]
    public function warnsAboutUnmappedKeysWithNonDefaultValues(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_Headline', 1, 'local');
        $configs = ConfigCollection::fromArray(array_merge(self::TARGET_DEFAULTS, [
            'entry_headline_offset' => '10',
        ]));

        $diff = $this->strategy->diff($module, $configs);

        $joined = implode(' / ', $diff->warnings);
        $this->assertStringContainsString('entry_headline_offset', $joined);
    }

    #[Test]
    #[TestDox('未対応キーがすべて未設定(空)の場合、警告を出さない')]
    public function doesNotWarnWhenUnmappedKeysAreAllEmpty(): void
    {
        $module = new ModuleRow(1, 'mod_1', 'Entry_List', 1, 'local');
        $diff = $this->strategy->diff($module, ConfigCollection::fromArray(self::TARGET_DEFAULTS));

        $this->assertSame([], $diff->warnings);
    }

    #[Test]
    #[TestDox('diff()はサポート外のmodule_nameに対してInvalidArgumentExceptionをスローする')]
    public function diffThrowsForUnsupportedModuleName(): void
    {
        $module = new ModuleRow(1, 'mod_x', 'Category_EntryList', 1, 'local');

        $this->expectException(\InvalidArgumentException::class);
        $this->strategy->diff($module, ConfigCollection::fromArray([]));
    }

    private function findItem(\Acms\Plugins\DeprecatedModuleMigration\MigrationDiff $diff, string $targetConfigKey): \Acms\Plugins\DeprecatedModuleMigration\MigrationDiffItem
    {
        foreach ($diff->items as $item) {
            if ($item->targetConfigKey === $targetConfigKey) {
                return $item;
            }
        }

        $this->fail(sprintf('targetConfigKey "%s" の項目が見つかりませんでした。', $targetConfigKey));
    }
}
