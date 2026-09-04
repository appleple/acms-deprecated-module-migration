import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useMigrationHistory } from '../hooks/use-migration-history';
import type { SnapshotSummary } from '../types';
import MigrationHistory from './migration-history';

vi.mock('../hooks/use-migration-history');

const mockedUseMigrationHistory = vi.mocked(useMigrationHistory);
// window.ACMS.Library.dialog は vitest.setup.ts でモック済み(module-migration-admin.test.tsx参照)。
const mockedConfirm = vi.mocked(window.ACMS.Library.dialog.confirm);
const mockedAlert = vi.mocked(window.ACMS.Library.dialog.alert);

function summary(overrides: Partial<SnapshotSummary> = {}): SnapshotSummary {
  return {
    snapshotId: 10,
    moduleId: 1,
    moduleName: 'Plugin_Schedule',
    snapshotDatetime: '2026-08-30 12:00:00',
    userId: 1,
    blogId: 1,
    blogName: null,
    ...overrides,
  };
}

type HookReturn = ReturnType<typeof useMigrationHistory>;

function makeHookState(overrides: Partial<HookReturn> = {}): HookReturn {
  return {
    snapshots: [],
    isLoading: false,
    error: null,
    isRollingBack: false,
    loadSnapshots: vi.fn(),
    rollback: vi.fn(),
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('MigrationHistory', () => {
  it('マウント時に一覧取得を1回だけ呼ぶ', () => {
    const loadSnapshots = vi.fn();
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ loadSnapshots }));

    render(<MigrationHistory blogId={1} />);

    expect(loadSnapshots).toHaveBeenCalledTimes(1);
  });

  it('includeChildrenを省略した場合、falseでマウント時の一覧取得を呼ぶ', () => {
    const loadSnapshots = vi.fn();
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ loadSnapshots }));

    render(<MigrationHistory blogId={1} />);

    expect(loadSnapshots).toHaveBeenCalledWith(false);
  });

  it('includeChildren:trueを渡すと、trueで一覧取得する', () => {
    const loadSnapshots = vi.fn();
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ loadSnapshots }));

    render(<MigrationHistory blogId={1} includeChildren />);

    expect(loadSnapshots).toHaveBeenCalledWith(true);
  });

  it('includeChildrenが変化すると一覧を取得し直す', () => {
    const loadSnapshots = vi.fn();
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ loadSnapshots }));

    const { rerender } = render(<MigrationHistory blogId={1} includeChildren={false} />);
    expect(loadSnapshots).toHaveBeenLastCalledWith(false);

    rerender(<MigrationHistory blogId={1} includeChildren />);

    expect(loadSnapshots).toHaveBeenLastCalledWith(true);
  });

  it('スナップショットの所属ブログ名とブログIDを表示する', () => {
    mockedUseMigrationHistory.mockReturnValue(
      makeHookState({ snapshots: [summary({ blogName: '子ブログA', blogId: 5 })] })
    );

    render(<MigrationHistory blogId={1} />);

    expect(screen.getByText('子ブログA (5)')).toBeInTheDocument();
  });

  it('ブログ名が無い場合はブログIDのみ表示する', () => {
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ snapshots: [summary({ blogName: null, blogId: 5 })] }));

    render(<MigrationHistory blogId={1} />);

    expect(screen.getByText('(5)')).toBeInTheDocument();
  });

  it('移行履歴が無い場合はその旨を表示する', () => {
    mockedUseMigrationHistory.mockReturnValue(makeHookState());

    render(<MigrationHistory blogId={1} />);

    expect(screen.getByText('移行履歴はありません')).toBeInTheDocument();
  });

  it('移行履歴をテーブルとして表示する', () => {
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ snapshots: [summary()] }));

    render(<MigrationHistory blogId={1} />);

    expect(screen.getByText('Plugin_Schedule')).toBeInTheDocument();
    expect(screen.getByText('2026-08-30 12:00:00')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ロールバック' })).toBeInTheDocument();
  });

  it('errorがある場合はalertロールで表示する', () => {
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ error: '移行履歴の取得に失敗しました。' }));

    render(<MigrationHistory blogId={1} />);

    expect(screen.getByRole('alert')).toHaveTextContent('移行履歴の取得に失敗しました。');
  });

  it('ロールバックボタンを押し確認してOKした場合、snapshotIdと所属ブログIDでrollback()を呼び成功したらアラートを出す', async () => {
    const user = userEvent.setup();
    const rollback = vi.fn().mockResolvedValue(true);
    mockedConfirm.mockResolvedValue(true);
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ snapshots: [summary({ blogId: 7 })], rollback }));

    render(<MigrationHistory blogId={1} />);
    await user.click(screen.getByRole('button', { name: 'ロールバック' }));

    expect(mockedConfirm).toHaveBeenCalledTimes(1);
    expect(rollback).toHaveBeenCalledWith(10, 7);
    await waitFor(() => expect(mockedAlert).toHaveBeenCalledWith('ロールバックしました'));
  });

  it('確認でキャンセルした場合、rollback()は呼ばれない', async () => {
    const user = userEvent.setup();
    const rollback = vi.fn();
    mockedConfirm.mockResolvedValue(false);
    mockedUseMigrationHistory.mockReturnValue(makeHookState({ snapshots: [summary()], rollback }));

    render(<MigrationHistory blogId={1} />);
    await user.click(screen.getByRole('button', { name: 'ロールバック' }));

    expect(rollback).not.toHaveBeenCalled();
  });

  it('onRollbackSuccessを渡した場合、useMigrationHistory()にそのまま渡す', () => {
    const onRollbackSuccess = vi.fn();
    mockedUseMigrationHistory.mockReturnValue(makeHookState());

    render(<MigrationHistory blogId={1} onRollbackSuccess={onRollbackSuccess} />);

    expect(mockedUseMigrationHistory).toHaveBeenCalledWith(1, { onRollbackSuccess });
  });
});
