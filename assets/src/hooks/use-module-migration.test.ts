import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { applyMigration, detectModules, fetchDiff, rollbackMigration } from '../api';
import type {
  MigrationApplyResponse,
  MigrationDiffResponse,
  MigrationRollbackResponse,
  ModuleMigrationCandidate,
} from '../types';
import { useModuleMigration } from './use-module-migration';

vi.mock('../api');

const mockedDetect = vi.mocked(detectModules);
const mockedFetchDiff = vi.mocked(fetchDiff);
const mockedApply = vi.mocked(applyMigration);
const mockedRollback = vi.mocked(rollbackMigration);

function candidate(overrides: Partial<ModuleMigrationCandidate> = {}): ModuleMigrationCandidate {
  return {
    moduleId: 1,
    moduleIdentifier: 'mod_schedule',
    moduleName: 'Plugin_Schedule',
    moduleBlogId: 1,
    moduleScope: 'local',
    targetModuleName: 'Schedule',
    rank: 'A',
    ...overrides,
  };
}

function diffResponse(sourceModuleName: string): MigrationDiffResponse {
  return {
    success: true,
    diff: {
      sourceModuleName,
      targetModuleName: 'Schedule',
      items: [],
      warnings: [],
      unsupportedReasons: [],
      isBlocked: false,
    },
  };
}

function applyResponse(snapshotId: number): MigrationApplyResponse {
  return {
    success: true,
    result: { moduleId: 1, oldModuleName: 'Plugin_Schedule', newModuleName: 'Schedule', writtenConfig: {}, notes: [] },
    snapshotId,
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

describe('loadModules', () => {
  it('取得中はisLoadingがtrueになり、成功したらmodulesに反映してisLoadingをfalseへ戻す', async () => {
    const d = deferred<{ success: true; modules: ModuleMigrationCandidate[] }>();
    mockedDetect.mockReturnValue(d.promise);
    const { result } = renderHook(() => useModuleMigration(1));

    act(() => {
      void result.current.loadModules();
    });
    expect(result.current.isLoading).toBe(true);

    await act(async () => {
      d.resolve({ success: true, modules: [candidate()] });
      await d.promise;
    });

    expect(result.current.isLoading).toBe(false);
    expect(result.current.modules).toEqual([candidate()]);
    expect(result.current.error).toBeNull();
  });

  it('Errorが投げられた場合そのmessageをerrorに反映する', async () => {
    mockedDetect.mockRejectedValue(new Error('権限がありません。'));
    const { result } = renderHook(() => useModuleMigration(1));

    await act(async () => {
      await result.current.loadModules();
    });

    expect(result.current.error).toBe('権限がありません。');
    expect(result.current.modules).toEqual([]);
  });

  it('Errorインスタンスでない例外はフォールバックメッセージになる', async () => {
    mockedDetect.mockRejectedValue('boom');
    const { result } = renderHook(() => useModuleMigration(1));

    await act(async () => {
      await result.current.loadModules();
    });

    expect(result.current.error).toBe('一覧の取得に失敗しました。');
  });
});

describe('loadDiff', () => {
  it('後発のloadDiff呼び出し後に先発のレスポンスが届いても結果を反映しない(競合状態ガード)', async () => {
    const first = deferred<MigrationDiffResponse>();
    const second = deferred<MigrationDiffResponse>();
    mockedFetchDiff.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    const { result } = renderHook(() => useModuleMigration(1));

    act(() => {
      void result.current.loadDiff(1);
    });
    act(() => {
      void result.current.loadDiff(2);
    });

    await act(async () => {
      first.resolve(diffResponse('先発モジュール'));
      // 先発リクエストのマイクロタスクだけを消化する。
      await Promise.resolve().then(() => Promise.resolve());
    });
    expect(result.current.diffResult).toBeNull();

    await act(async () => {
      second.resolve(diffResponse('後発モジュール'));
      await second.promise;
    });
    expect(result.current.diffResult?.diff.sourceModuleName).toBe('後発モジュール');
  });

  it('clearDiff()後に届いた古いレスポンスは反映されない', async () => {
    const d = deferred<MigrationDiffResponse>();
    mockedFetchDiff.mockReturnValue(d.promise);
    const { result } = renderHook(() => useModuleMigration(1));

    act(() => {
      void result.current.loadDiff(1);
    });
    act(() => {
      result.current.clearDiff();
    });

    await act(async () => {
      d.resolve(diffResponse('古いモジュール'));
      await d.promise;
    });

    expect(result.current.diffResult).toBeNull();
    expect(result.current.isDiffLoading).toBe(false);
  });

  it('取得に失敗した場合diffErrorにメッセージが入る', async () => {
    mockedFetchDiff.mockRejectedValue(new Error('差分の取得に失敗しました。'));
    const { result } = renderHook(() => useModuleMigration(1));

    await act(async () => {
      await result.current.loadDiff(1);
    });

    expect(result.current.diffError).toBe('差分の取得に失敗しました。');
    expect(result.current.isDiffLoading).toBe(false);
  });
});

describe('apply', () => {
  it('連続で呼び出しても実行中はAPIを1回しか呼ばない(二重送信防止)', async () => {
    const d = deferred<MigrationApplyResponse>();
    mockedApply.mockReturnValue(d.promise);
    mockedDetect.mockResolvedValue({ success: true, modules: [] });
    const { result } = renderHook(() => useModuleMigration(1));

    let secondResult: MigrationApplyResponse | null = null;
    act(() => {
      void result.current.apply(1);
      void result.current.apply(1).then((r) => {
        secondResult = r;
      });
    });

    expect(mockedApply).toHaveBeenCalledTimes(1);

    await act(async () => {
      d.resolve(applyResponse(10));
      await d.promise;
    });

    expect(secondResult).toBeNull();
    expect(result.current.applyResult).toEqual(applyResponse(10));
  });

  it('成功したら差分をクリアしモジュール一覧を再取得する', async () => {
    mockedApply.mockResolvedValue(applyResponse(10));
    mockedDetect.mockResolvedValue({ success: true, modules: [candidate()] });
    const { result } = renderHook(() => useModuleMigration(1));

    await act(async () => {
      await result.current.apply(1);
    });

    expect(result.current.diffResult).toBeNull();
    await waitFor(() => expect(result.current.modules).toEqual([candidate()]));
    expect(mockedDetect).toHaveBeenCalledTimes(1);
  });

  it('失敗したらerrorにメッセージを入れnullを返す(applyResultは更新しない)', async () => {
    mockedApply.mockRejectedValue(new Error('適用に失敗しました。'));
    const { result } = renderHook(() => useModuleMigration(1));

    const response = await act(async () => result.current.apply(1));

    expect(response).toBeNull();
    expect(result.current.error).toBe('適用に失敗しました。');
    expect(result.current.applyResult).toBeNull();
  });
});

describe('rollback', () => {
  it('連続で呼び出しても実行中はAPIを1回しか呼ばない(二重送信防止)', async () => {
    const d = deferred<MigrationRollbackResponse>();
    mockedRollback.mockReturnValue(d.promise);
    mockedDetect.mockResolvedValue({ success: true, modules: [] });
    const { result } = renderHook(() => useModuleMigration(1));

    let secondResult: boolean | null = null;
    act(() => {
      void result.current.rollback(10);
      void result.current.rollback(10).then((r) => {
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

  it('成功したらapplyResultをnullに戻しモジュール一覧を再取得する', async () => {
    mockedApply.mockResolvedValue(applyResponse(10));
    mockedRollback.mockResolvedValue({ success: true });
    mockedDetect.mockResolvedValue({ success: true, modules: [] });
    const { result } = renderHook(() => useModuleMigration(1));

    await act(async () => {
      await result.current.apply(1);
    });
    expect(result.current.applyResult).not.toBeNull();

    await act(async () => {
      await result.current.rollback(10);
    });

    expect(result.current.applyResult).toBeNull();
    expect(mockedDetect).toHaveBeenCalledTimes(2);
  });

  it('失敗したらerrorにメッセージが入りfalseを返す', async () => {
    mockedRollback.mockRejectedValue(new Error('ロールバックに失敗しました。'));
    const { result } = renderHook(() => useModuleMigration(1));

    const succeeded = await act(async () => result.current.rollback(10));

    expect(succeeded).toBe(false);
    expect(result.current.error).toBe('ロールバックに失敗しました。');
  });
});
