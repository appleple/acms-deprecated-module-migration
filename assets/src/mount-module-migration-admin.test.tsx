import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { applyMigration, detectModules, fetchDiff } from './api';
import { mountModuleMigrationAdminFromAttributes } from './mount-module-migration-admin';

// api.ts をモックする。確認/アラートダイアログは window.ACMS.Library.dialog(vitest.setup.tsで
// モック済み)を呼ぶ経路になっており、@ablogcms/dialogを独立してバンドルしないため
// DialogContainerのマウントは不要(mount-module-migration-admin.tsx参照)。
vi.mock('./api');

const mockedDetect = vi.mocked(detectModules);
const mockedFetchDiff = vi.mocked(fetchDiff);
const mockedApply = vi.mocked(applyMigration);
const mockedConfirm = vi.mocked(window.ACMS.Library.dialog.confirm);
const mockedAlert = vi.mocked(window.ACMS.Library.dialog.alert);

let container: HTMLElement;

beforeEach(() => {
  vi.clearAllMocks();
  container = document.createElement('div');
  container.setAttribute('blog-id', '1');
  document.body.appendChild(container);
});

afterEach(() => {
  container.remove();
});

describe('mountModuleMigrationAdminFromAttributes', () => {
  it('window.ACMS.Library.dialog経由で確認し、確認後にapplyMigration()が呼ばれる', async () => {
    const user = userEvent.setup();
    mockedDetect.mockResolvedValue({
      success: true,
      modules: [
        {
          moduleId: 1,
          moduleIdentifier: 'mod_schedule',
          moduleName: 'Plugin_Schedule',
          moduleBlogId: 1,
          moduleScope: 'local',
          targetModuleName: 'Schedule',
          rank: 'A',
          blogName: null,
        },
      ],
    });
    mockedFetchDiff.mockResolvedValue({
      success: true,
      diff: {
        sourceModuleName: 'Plugin_Schedule',
        targetModuleName: 'Schedule',
        items: [],
        warnings: [],
        unsupportedReasons: [],
        isBlocked: false,
      },
    });
    mockedConfirm.mockResolvedValue(true);
    mockedApply.mockResolvedValue({
      success: true,
      result: {
        moduleId: 1,
        oldModuleName: 'Plugin_Schedule',
        newModuleName: 'Schedule',
        writtenConfig: {},
        notes: [],
      },
      snapshotId: 10,
    });

    const controller = mountModuleMigrationAdminFromAttributes(container);

    await waitFor(() => expect(mockedDetect).toHaveBeenCalled());
    await user.click(await screen.findByRole('button', { name: '差分を確認' }));
    await user.click(await screen.findByRole('button', { name: 'この内容で適用する' }));

    await waitFor(() => expect(mockedConfirm).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(mockedApply).toHaveBeenCalledWith(1, 1, false));
    await waitFor(() => expect(mockedAlert).toHaveBeenCalledWith('移行を適用しました'));

    controller.unmount();
  });
});
