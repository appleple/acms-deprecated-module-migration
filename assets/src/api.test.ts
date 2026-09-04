import { fetchClient } from '@ablogcms/fetch-client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { applyMigration, detectModules, fetchDiff, fetchSnapshots, rollbackMigration } from './api';
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
    expect(params.get('includeChildren')).toBe('0');
    expect(params.get('formToken')).toBe(window.csrfToken);
  });

  it('includeChildren:trueを渡した場合は"1"として送信する', async () => {
    mockedPost.mockReturnValue(respond({ success: true, modules: [] }));

    await detectModules(42, true);

    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('includeChildren')).toBe('1');
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

describe('CSRFトークンの解決', () => {
  const originalCsrfToken = window.csrfToken;

  afterEach(() => {
    window.csrfToken = originalCsrfToken;
    document.head.querySelectorAll('meta[name="csrf-token"]').forEach((el) => el.remove());
  });

  it('window.csrfTokenが未設定でも、meta[name="csrf-token"]から取得してformTokenに使う', async () => {
    // a-blog cms本体(js/src/index.js)はwindow.csrfTokenをmetaタグから同期的に設定するが、
    // プラグインの<script type="module">は仕様上deferされるため、実行順序次第では
    // window.csrfTokenがまだ空のままPOSTしてしまう競合状態が起こりうる。
    // (実機で「初回ロードで処理に失敗する」不具合として発現した。)
    window.csrfToken = '';
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'meta-fallback-token');
    document.head.appendChild(meta);
    mockedPost.mockReturnValue(respond({ success: true, modules: [] }));

    await detectModules(1);

    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('formToken')).toBe('meta-fallback-token');
  });

  it('window.csrfTokenが設定されていればそれを優先する', async () => {
    window.csrfToken = 'window-token';
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'meta-fallback-token');
    document.head.appendChild(meta);
    mockedPost.mockReturnValue(respond({ success: true, modules: [] }));

    await detectModules(1);

    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('formToken')).toBe('window-token');
  });
});

describe('fetchSnapshots', () => {
  it('blogIdをパラメータに含めてPOSTし、成功時はレスポンスをそのまま返す', async () => {
    mockedPost.mockReturnValue(respond({ success: true, snapshots: [] }));

    const result = await fetchSnapshots(5);

    expect(result).toEqual({ success: true, snapshots: [] });
    const [, params] = mockedPost.mock.calls[0] as [string, URLSearchParams];
    expect(params.get('ACMS_POST_ModuleMigrationSnapshots')).toBe('post');
    expect(params.get('blogId')).toBe('5');
  });

  it('success:falseの場合、messageをそのままエラーにして投げる', async () => {
    mockedPost.mockReturnValue(respond({ success: false, message: '権限がありません。', snapshots: [] }));

    await expect(fetchSnapshots(1)).rejects.toThrow('権限がありません。');
  });
});
