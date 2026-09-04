import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useMigrationHistory } from '../hooks/use-migration-history';
import { useModuleMigration } from '../hooks/use-module-migration';
import type { MigrationApplyResponse, MigrationDiffResponse, ModuleMigrationCandidate } from '../types';
import ModuleMigrationAdmin from './module-migration-admin';

vi.mock('../hooks/use-module-migration');
vi.mock('../hooks/use-migration-history');

const mockedUseModuleMigration = vi.mocked(useModuleMigration);
const mockedUseMigrationHistory = vi.mocked(useMigrationHistory);
// window.ACMS.Library.dialog は vitest.setup.ts でモック済み(@ablogcms/dialogは
// バンドルせず本体が公開する共有インスタンスを呼ぶ。mount-module-migration-admin.tsx参照)。
const mockedConfirm = vi.mocked(window.ACMS.Library.dialog.confirm);
const mockedAlert = vi.mocked(window.ACMS.Library.dialog.alert);

function candidate(overrides: Partial<ModuleMigrationCandidate> = {}): ModuleMigrationCandidate {
  return {
    moduleId: 1,
    moduleIdentifier: 'mod_schedule',
    moduleName: 'Plugin_Schedule',
    moduleBlogId: 1,
    moduleScope: 'local',
    targetModuleName: 'Schedule',
    rank: 'A',
    blogName: 'テスト用ブログ',
    ...overrides,
  };
}

function diffResponse(): MigrationDiffResponse {
  return {
    success: true,
    diff: {
      sourceModuleName: 'Plugin_Schedule',
      targetModuleName: 'Schedule',
      items: [],
      warnings: [],
      unsupportedReasons: [],
      isBlocked: false,
    },
  };
}

function applyResponse(): MigrationApplyResponse {
  return {
    success: true,
    result: { moduleId: 1, oldModuleName: 'Plugin_Schedule', newModuleName: 'Schedule', writtenConfig: {}, notes: [] },
    snapshotId: 10,
  };
}

type HookReturn = ReturnType<typeof useModuleMigration>;

function makeHookState(overrides: Partial<HookReturn> = {}): HookReturn {
  return {
    modules: [],
    isLoading: false,
    error: null,
    diffResult: null,
    isDiffLoading: false,
    diffError: null,
    applyResult: null,
    appliedBlogId: null,
    isApplying: false,
    isRollingBack: false,
    loadModules: vi.fn(),
    loadDiff: vi.fn(),
    clearDiff: vi.fn(),
    apply: vi.fn(),
    rollback: vi.fn(),
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  mockedUseMigrationHistory.mockReturnValue({
    snapshots: [],
    isLoading: false,
    error: null,
    isRollingBack: false,
    loadSnapshots: vi.fn(),
    rollback: vi.fn(),
  });
});

describe('ModuleMigrationAdmin', () => {
  it('マウント時に一覧取得を1回だけ呼ぶ', () => {
    const loadModules = vi.fn();
    mockedUseModuleMigration.mockReturnValue(makeHookState({ loadModules }));

    render(<ModuleMigrationAdmin blogId={1} />);

    expect(loadModules).toHaveBeenCalledTimes(1);
  });

  it('対象モジュールが無い場合はその旨を表示する', () => {
    mockedUseModuleMigration.mockReturnValue(makeHookState());

    render(<ModuleMigrationAdmin blogId={1} />);

    expect(screen.getByText('対象の非推奨モジュールは見つかりませんでした')).toBeInTheDocument();
  });

  it('モジュール一覧をテーブルとして表示し、rankがnullの行は差分確認ボタンを無効化する', () => {
    mockedUseModuleMigration.mockReturnValue(
      makeHookState({
        modules: [
          candidate(),
          candidate({
            moduleId: 2,
            moduleIdentifier: 'mod_profile',
            moduleName: 'User_Profile',
            rank: null,
            targetModuleName: null,
          }),
        ],
      })
    );

    render(<ModuleMigrationAdmin blogId={1} />);

    expect(screen.getByText('mod_schedule')).toBeInTheDocument();
    expect(screen.getByText('Plugin_Schedule')).toBeInTheDocument();
    const buttons = screen.getAllByRole('button', { name: '差分を確認' });
    expect(buttons[0]).not.toBeDisabled();
    expect(buttons[1]).toBeDisabled();
  });

  it('モジュール一覧に所属ブログ名とブログIDの列を表示する', () => {
    mockedUseModuleMigration.mockReturnValue(
      makeHookState({ modules: [candidate({ blogName: '子ブログA', moduleBlogId: 5 })] })
    );

    render(<ModuleMigrationAdmin blogId={1} />);

    expect(screen.getByText('子ブログA (5)')).toBeInTheDocument();
  });

  it('ブログ名が無い場合はブログIDのみ表示する', () => {
    mockedUseModuleMigration.mockReturnValue(
      makeHookState({ modules: [candidate({ blogName: null, moduleBlogId: 5 })] })
    );

    render(<ModuleMigrationAdmin blogId={1} />);

    expect(screen.getByText('(5)')).toBeInTheDocument();
  });

  it('errorがある場合はalertロールで表示する', () => {
    mockedUseModuleMigration.mockReturnValue(makeHookState({ error: '一覧の取得に失敗しました。' }));

    render(<ModuleMigrationAdmin blogId={1} />);

    expect(screen.getByRole('alert')).toHaveTextContent('一覧の取得に失敗しました。');
  });

  it('applyResultがある場合のみ「直前の適用を元に戻す」ボタンを表示する', () => {
    mockedUseModuleMigration.mockReturnValue(makeHookState());
    const { rerender } = render(<ModuleMigrationAdmin blogId={1} />);
    expect(screen.queryByRole('button', { name: '直前の適用を元に戻す' })).not.toBeInTheDocument();

    mockedUseModuleMigration.mockReturnValue(makeHookState({ applyResult: applyResponse(), appliedBlogId: 1 }));
    rerender(<ModuleMigrationAdmin blogId={1} />);
    expect(screen.getByRole('button', { name: '直前の適用を元に戻す' })).toBeInTheDocument();
  });

  it('差分を確認ボタンを押すとmoduleIdとmoduleBlogIdでloadDiff()を呼び、差分モーダルを開く', async () => {
    const user = userEvent.setup();
    const loadDiff = vi.fn();
    mockedUseModuleMigration.mockReturnValue(
      makeHookState({ modules: [candidate({ moduleId: 1, moduleBlogId: 7 })], loadDiff })
    );

    render(<ModuleMigrationAdmin blogId={1} />);
    await user.click(screen.getByRole('button', { name: '差分を確認' }));

    expect(loadDiff).toHaveBeenCalledWith(1, 7);
    expect(screen.getByText('差分プレビュー')).toBeInTheDocument();
  });

  describe('配下のブログを含める', () => {
    it('既定で有効(チェック済み)で、マウント時にincludeChildren:trueで一覧取得する', () => {
      const loadModules = vi.fn();
      mockedUseModuleMigration.mockReturnValue(makeHookState({ loadModules }));

      render(<ModuleMigrationAdmin blogId={1} />);

      expect(screen.getByRole('checkbox', { name: '配下のブログを含める' })).toBeChecked();
      expect(loadModules).toHaveBeenCalledWith(true);
    });

    it('チェックを外すと子ブログを含めずにloadModules()を呼び直す', async () => {
      const user = userEvent.setup();
      const loadModules = vi.fn();
      mockedUseModuleMigration.mockReturnValue(makeHookState({ loadModules }));

      render(<ModuleMigrationAdmin blogId={1} />);
      await user.click(screen.getByRole('checkbox', { name: '配下のブログを含める' }));

      expect(loadModules).toHaveBeenLastCalledWith(false);
    });

    it('チェックを外して再度チェックすると子ブログを含めてloadModules()を呼び直す', async () => {
      const user = userEvent.setup();
      const loadModules = vi.fn();
      mockedUseModuleMigration.mockReturnValue(makeHookState({ loadModules }));

      render(<ModuleMigrationAdmin blogId={1} />);
      const checkbox = screen.getByRole('checkbox', { name: '配下のブログを含める' });
      await user.click(checkbox);
      await user.click(checkbox);

      expect(loadModules).toHaveBeenLastCalledWith(true);
    });
  });

  describe('ロールバック', () => {
    it('確認してOKした場合、snapshotIdと適用したブログIDでrollback()を呼び成功したらアラートを出す', async () => {
      const user = userEvent.setup();
      const rollback = vi.fn().mockResolvedValue(true);
      mockedConfirm.mockResolvedValue(true);
      mockedUseModuleMigration.mockReturnValue(
        makeHookState({ applyResult: applyResponse(), appliedBlogId: 7, rollback })
      );

      render(<ModuleMigrationAdmin blogId={1} />);
      await user.click(screen.getByRole('button', { name: '直前の適用を元に戻す' }));

      expect(mockedConfirm).toHaveBeenCalledTimes(1);
      expect(rollback).toHaveBeenCalledWith(10, 7);
      expect(mockedAlert).toHaveBeenCalledWith('ロールバックしました');
    });

    it('確認でキャンセルした場合、rollback()は呼ばれない', async () => {
      const user = userEvent.setup();
      const rollback = vi.fn();
      mockedConfirm.mockResolvedValue(false);
      mockedUseModuleMigration.mockReturnValue(
        makeHookState({ applyResult: applyResponse(), appliedBlogId: 1, rollback })
      );

      render(<ModuleMigrationAdmin blogId={1} />);
      await user.click(screen.getByRole('button', { name: '直前の適用を元に戻す' }));

      expect(rollback).not.toHaveBeenCalled();
    });
  });

  describe('適用', () => {
    it('ランクCのモジュールを適用する場合、moduleBlogIdとoptIn:trueで呼ぶ', async () => {
      const user = userEvent.setup();
      const apply = vi.fn().mockResolvedValue(applyResponse());
      mockedConfirm.mockResolvedValue(true);
      mockedUseModuleMigration.mockReturnValue(
        makeHookState({
          modules: [candidate({ rank: 'C', moduleName: 'Banner', targetModuleName: 'Media_Banner', moduleBlogId: 7 })],
          diffResult: diffResponse(),
          apply,
        })
      );

      render(<ModuleMigrationAdmin blogId={1} />);
      await user.click(screen.getByRole('button', { name: '差分を確認' }));
      await user.click(screen.getByRole('button', { name: 'この内容で適用する' }));

      await waitFor(() => expect(apply).toHaveBeenCalledWith(1, 7, true));
      await waitFor(() => expect(mockedAlert).toHaveBeenCalledWith('移行を適用しました'));
    });

    it('ランクA/Bのモジュールを適用する場合、moduleBlogIdとoptIn:falseで呼ぶ', async () => {
      const user = userEvent.setup();
      const apply = vi.fn().mockResolvedValue(applyResponse());
      mockedConfirm.mockResolvedValue(true);
      mockedUseModuleMigration.mockReturnValue(
        makeHookState({ modules: [candidate({ rank: 'A', moduleBlogId: 7 })], diffResult: diffResponse(), apply })
      );

      render(<ModuleMigrationAdmin blogId={1} />);
      await user.click(screen.getByRole('button', { name: '差分を確認' }));
      await user.click(screen.getByRole('button', { name: 'この内容で適用する' }));

      await waitFor(() => expect(apply).toHaveBeenCalledWith(1, 7, false));
    });

    it('確認でキャンセルした場合、apply()は呼ばれない', async () => {
      const user = userEvent.setup();
      const apply = vi.fn();
      mockedConfirm.mockResolvedValue(false);
      mockedUseModuleMigration.mockReturnValue(
        makeHookState({ modules: [candidate()], diffResult: diffResponse(), apply })
      );

      render(<ModuleMigrationAdmin blogId={1} />);
      await user.click(screen.getByRole('button', { name: '差分を確認' }));
      await user.click(screen.getByRole('button', { name: 'この内容で適用する' }));

      await waitFor(() => expect(mockedConfirm).toHaveBeenCalledTimes(1));
      expect(apply).not.toHaveBeenCalled();
    });
  });

  describe('移行履歴', () => {
    it('移行履歴セクションを表示し、そこでのロールバック成功時にモジュール一覧を再取得する', () => {
      const loadModules = vi.fn();
      mockedUseModuleMigration.mockReturnValue(makeHookState({ loadModules }));

      render(<ModuleMigrationAdmin blogId={1} />);

      expect(screen.getByText('移行履歴')).toBeInTheDocument();
      const { onRollbackSuccess } = mockedUseMigrationHistory.mock.calls[0][1] ?? {};
      onRollbackSuccess?.();

      expect(loadModules).toHaveBeenCalledTimes(2);
    });
  });
});
