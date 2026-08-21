import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { applyMigration, detectModules, fetchDiff } from './api';
import { mountModuleMigrationAdminFromAttributes } from './mount-module-migration-admin';

// api.ts だけをモックし、@ablogcms/dialog は実物を使う。
// このプラグインは@ablogcms/*を自身のadmin.jsへ独立してバンドルしているため、dialog.confirm()を
// 解決させるにはmount関数自身がバンドルされた自分の<DialogContainer>を用意している必要がある
// (実際のブラウザでの確認で、これが無いとdialog.confirm()が永久に未解決のまま止まる不具合を発見した)。
// モックしてしまうとこの結線をテストで検証できなくなるため、意図的に実物を使う。
vi.mock('./api');

const mockedDetect = vi.mocked(detectModules);
const mockedFetchDiff = vi.mocked(fetchDiff);
const mockedApply = vi.mocked(applyMigration);

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
  it('dialog.confirm()が実際に解決し、確認後にapplyMigration()が呼ばれる(DialogContainer結線の実機相当テスト)', async () => {
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
    mockedApply.mockResolvedValue({
      success: true,
      result: { moduleId: 1, oldModuleName: 'Plugin_Schedule', newModuleName: 'Schedule', writtenConfig: {}, notes: [] },
      snapshotId: 10,
    });

    const controller = mountModuleMigrationAdminFromAttributes(container);

    await waitFor(() => expect(mockedDetect).toHaveBeenCalled());
    await screen.findByRole('button', { name: '差分を確認' });
    await user.click(await screen.findByRole('button', { name: '差分を確認' }));
    await user.click(await screen.findByRole('button', { name: 'この内容で適用する' }));

    // dialog.confirm()が実際にDialogContainer経由でモーダルを表示することを確認する。
    // (window.ACMS.i18nはテスト環境ではキーをそのまま返すモックのため、ラベルは"dialog.ok"になる。
    // React StrictModeの開発時二重マウントの影響で、直前に開いていたDiffPreviewModalが
    // 一時的にaria-hidden化されるタイミングと重なりうるため、hidden:trueで検索する)
    await waitFor(() => {
      expect(screen.getAllByRole('button', { name: 'dialog.ok', hidden: true }).length).toBeGreaterThan(0);
    });
    const [okButton] = screen.getAllByRole('button', { name: 'dialog.ok', hidden: true });
    await user.click(okButton);

    await waitFor(() => expect(mockedApply).toHaveBeenCalledWith(1, 1, false));

    controller.unmount();
  });
});
