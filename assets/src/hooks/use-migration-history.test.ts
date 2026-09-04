import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchSnapshots, rollbackMigration } from '../api';
import type { MigrationRollbackResponse, MigrationSnapshotsResponse, SnapshotSummary } from '../types';
import { useMigrationHistory } from './use-migration-history';

vi.mock('../api');

const mockedFetchSnapshots = vi.mocked(fetchSnapshots);
const mockedRollback = vi.mocked(rollbackMigration);

function summary(overrides: Partial<SnapshotSummary> = {}): SnapshotSummary {
  return {
    snapshotId: 1,
    moduleId: 1,
    moduleName: 'Plugin_Schedule',
    snapshotDatetime: '2026-08-30 12:00:00',
    userId: 1,
    blogId: 1,
    blogName: null,
    ...overrides,
  };
}

/** テストから任意のタイミングでresolve/rejectできるPromiseを作る。 */
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('loadSnapshots', () => {
  it('取得中はisLoadingがtrueになり、成功したらsnapshotsに反映してisLoadingをfalseへ戻す', async () => {
    const d = deferred<MigrationSnapshotsResponse>();
    mockedFetchSnapshots.mockReturnValue(d.promise);
    const { result } = renderHook(() => useMigrationHistory(1));

    act(() => {
      void result.current.loadSnapshots();
    });
    expect(result.current.isLoading).toBe(true);

    await act(async () => {
      d.resolve({ success: true, snapshots: [summary()] });
      await d.promise;
    });

    expect(result.current.isLoading).toBe(false);
    expect(result.current.snapshots).toEqual([summary()]);
    expect(result.current.error).toBeNull();
  });

  it('Errorが投げられた場合そのmessageをerrorに反映する', async () => {
    mockedFetchSnapshots.mockRejectedValue(new Error('権限がありません。'));
    const { result } = renderHook(() => useMigrationHistory(1));

    await act(async () => {
      await result.current.loadSnapshots();
    });

    expect(result.current.error).toBe('権限がありません。');
    expect(result.current.snapshots).toEqual([]);
  });

  it('includeChildren:trueを渡すとfetchSnapshotsにそのまま渡す', async () => {
    mockedFetchSnapshots.mockResolvedValue({ success: true, snapshots: [] });
    const { result } = renderHook(() => useMigrationHistory(1));

    await act(async () => {
      await result.current.loadSnapshots(true);
    });

    expect(mockedFetchSnapshots).toHaveBeenCalledWith(1, true);
  });

  it('引数を省略した場合、直近に指定したincludeChildrenを維持する', async () => {
    mockedFetchSnapshots.mockResolvedValue({ success: true, snapshots: [] });
    const { result } = renderHook(() => useMigrationHistory(1));

    await act(async () => {
      await result.current.loadSnapshots(true);
    });
    await act(async () => {
      await result.current.loadSnapshots();
    });

    expect(mockedFetchSnapshots).toHaveBeenLastCalledWith(1, true);
  });
});

describe('rollback', () => {
  it('連続で呼び出しても実行中はAPIを1回しか呼ばない(二重送信防止)', async () => {
    const d = deferred<MigrationRollbackResponse>();
    mockedRollback.mockReturnValue(d.promise);
    mockedFetchSnapshots.mockResolvedValue({ success: true, snapshots: [] });
    const { result } = renderHook(() => useMigrationHistory(1));

    let secondResult: boolean | null = null;
    act(() => {
      void result.current.rollback(10, 1);
      void result.current.rollback(10, 1).then((r) => {
        secondResult = r;
      });
    });

    expect(mockedRollback).toHaveBeenCalledTimes(1);

    await act(async () => {
      d.resolve({ success: true });
      await d.promise;
    });

    expect(secondResult).toBe(false);
  });

  it('スナップショットの所有ブログIDでrollbackMigration()を呼ぶ(hookのblogIdとは独立)', async () => {
    mockedRollback.mockResolvedValue({ success: true });
    mockedFetchSnapshots.mockResolvedValue({ success: true, snapshots: [] });
    const { result } = renderHook(() => useMigrationHistory(1));

    await act(async () => {
      await result.current.rollback(10, 5);
    });

    expect(mockedRollback).toHaveBeenCalledWith(5, 10);
  });

  it('成功したら一覧を再取得する', async () => {
    mockedRollback.mockResolvedValue({ success: true });
    mockedFetchSnapshots.mockResolvedValue({ success: true, snapshots: [summary()] });
    const { result } = renderHook(() => useMigrationHistory(1));

    await act(async () => {
      await result.current.rollback(10, 1);
    });

    expect(mockedFetchSnapshots).toHaveBeenCalledTimes(1);
    expect(result.current.snapshots).toEqual([summary()]);
  });

  it('成功したらonRollbackSuccessコールバックを呼ぶ', async () => {
    mockedRollback.mockResolvedValue({ success: true });
    mockedFetchSnapshots.mockResolvedValue({ success: true, snapshots: [] });
    const onRollbackSuccess = vi.fn();
    const { result } = renderHook(() => useMigrationHistory(1, { onRollbackSuccess }));

    await act(async () => {
      await result.current.rollback(10, 1);
    });

    expect(onRollbackSuccess).toHaveBeenCalledTimes(1);
  });

  it('失敗したらerrorにメッセージが入りfalseを返す', async () => {
    mockedRollback.mockRejectedValue(new Error('ロールバックに失敗しました。'));
    const { result } = renderHook(() => useMigrationHistory(1));

    const succeeded = await act(async () => result.current.rollback(10, 1));

    expect(succeeded).toBe(false);
    expect(result.current.error).toBe('ロールバックに失敗しました。');
  });
});
