import { fetchClient } from '@ablogcms/fetch-client';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { applyMigration, detectModules, fetchDiff, rollbackMigration } from './api';
import type { FetchResponse } from '@ablogcms/fetch-client';

vi.mock('@ablogcms/fetch-client', () => ({
  fetchClient: { post: vi.fn() },
}));

const mockedPost = vi.mocked(fetchClient.post);

function respond<T>(data: T): Promise<FetchResponse<T>> {
  return Promise.resolve({ data, status: 200, statusText: 'OK', headers: new Headers() });
}

beforeEach(() => {
  mockedPost.mockReset();
});

describe('detectModules', () => {
  it('ハンドラ名マーカー・blogId・formTokenを付与してPOSTし、成功時はレスポンスをそのまま返す', async () => {
    mockedPost.mockReturnValue(respond({ success: true, modules: [] }));

    const result = await detectModules(42);

    expect(result).toEqual({ success: true, modules: [] });
    const [url, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(url).toBe(window.location.href);
    expect(params.get('ACMS_POST_ModuleMigrationDetect')).toBe('post');
    expect(params.get('blogId')).toBe('42');
    expect(params.get('formToken')).toBe(window.csrfToken);
  });

  it('success:falseの場合、messageをそのままエラーにして投げる', async () => {
    mockedPost.mockReturnValue(respond({ success: false, message: '権限がありません。', modules: [] }));

    await expect(detectModules(1)).rejects.toThrow('権限がありません。');
  });

  it('success:falseかつmessageが無い場合、既定のエラーメッセージを投げる', async () => {
    mockedPost.mockReturnValue(respond({ success: false, modules: [] }));

    await expect(detectModules(1)).rejects.toThrow('処理に失敗しました。');
  });
});

describe('fetchDiff', () => {
  it('blogIdとmoduleIdをパラメータに含めてPOSTする', async () => {
    mockedPost.mockReturnValue(
      respond({
        success: true,
        diff: {
          sourceModuleName: 'A',
          targetModuleName: 'B',
          items: [],
          warnings: [],
          unsupportedReasons: [],
          isBlocked: false,
        },
      })
    );

    await fetchDiff(1, 99);

    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('ACMS_POST_ModuleMigrationDiff')).toBe('post');
    expect(params.get('blogId')).toBe('1');
    expect(params.get('moduleId')).toBe('99');
  });
});

describe('applyMigration', () => {
  it('optInを省略した場合は"0"として送信する', async () => {
    mockedPost.mockReturnValue(
      respond({
        success: true,
        result: { moduleId: 1, oldModuleName: 'A', newModuleName: 'B', writtenConfig: {}, notes: [] },
        snapshotId: 1,
      })
    );

    await applyMigration(1, 2);

    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('optIn')).toBe('0');
  });

  it('optIn:trueを渡した場合は"1"として送信する', async () => {
    mockedPost.mockReturnValue(
      respond({
        success: true,
        result: { moduleId: 1, oldModuleName: 'A', newModuleName: 'B', writtenConfig: {}, notes: [] },
        snapshotId: 1,
      })
    );

    await applyMigration(1, 2, true);

    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('optIn')).toBe('1');
  });
});

describe('rollbackMigration', () => {
  it('blogIdとsnapshotIdをパラメータに含めてPOSTする', async () => {
    mockedPost.mockReturnValue(respond({ success: true }));

    await rollbackMigration(3, 7);

    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('ACMS_POST_ModuleMigrationRollback')).toBe('post');
    expect(params.get('blogId')).toBe('3');
    expect(params.get('snapshotId')).toBe('7');
  });
});
