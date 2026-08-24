import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import DiffPreviewModal from './diff-preview-modal';
import type { MigrationDiffResponse } from '../types';

function makeDiffResult(overrides: Partial<MigrationDiffResponse['diff']> = {}): MigrationDiffResponse {
  return {
    success: true,
    diff: {
      sourceModuleName: 'Plugin_Schedule',
      targetModuleName: 'Schedule',
      items: [
        {
          targetConfigKey: 'schedule_unit',
          sourceConfigKey: 'schedule_unit',
          sourceEffectiveValue: '2',
          targetDefaultValue: '1',
          willChangeIfUnset: true,
        },
      ],
      warnings: [],
      unsupportedReasons: [],
      isBlocked: false,
      ...overrides,
    },
  };
}

describe('DiffPreviewModal', () => {
  it('isOpenがfalseの場合は何も表示しない', () => {
    render(<DiffPreviewModal isOpen={false} onClose={vi.fn()} diffResult={null} onApply={vi.fn()} />);

    expect(screen.queryByText('差分プレビュー')).not.toBeInTheDocument();
  });

  it('読み込み中は読み込み中メッセージを表示する', () => {
    render(<DiffPreviewModal isOpen onClose={vi.fn()} diffResult={null} isLoading onApply={vi.fn()} />);

    expect(screen.getByText('読み込み中です')).toBeInTheDocument();
  });

  it('エラー時はalertロールでメッセージを表示し、差分テーブルは表示しない', () => {
    render(
      <DiffPreviewModal
        isOpen
        onClose={vi.fn()}
        diffResult={null}
        error="対象モジュールが見つかりません。"
        onApply={vi.fn()}
      />
    );

    expect(screen.getByRole('alert')).toHaveTextContent('対象モジュールが見つかりません。');
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });

  it('差分結果がある場合、移行元→移行先と設定キー一覧を表示する', () => {
    render(<DiffPreviewModal isOpen onClose={vi.fn()} diffResult={makeDiffResult()} onApply={vi.fn()} />);

    expect(screen.getByText(/Plugin_Schedule/)).toBeInTheDocument();
    expect(screen.getByText(/Schedule/)).toBeInTheDocument();
    expect(screen.getByText('schedule_unit')).toBeInTheDocument();
    expect(screen.getByText('明示的に書き込みます')).toBeInTheDocument();
  });

  it('unsupportedReasons/warningsがある場合はそれぞれのセクションを表示する', () => {
    render(
      <DiffPreviewModal
        isOpen
        onClose={vi.fn()}
        diffResult={makeDiffResult({ unsupportedReasons: ['banner_imgが空です'], warnings: ['画像は移行されません'] })}
        onApply={vi.fn()}
      />
    );

    expect(screen.getByText('自動移行できない理由')).toBeInTheDocument();
    expect(screen.getByText('banner_imgが空です')).toBeInTheDocument();
    expect(screen.getByText('注意事項')).toBeInTheDocument();
    expect(screen.getByText('画像は移行されません')).toBeInTheDocument();
  });

  it('isBlocked:trueの場合、適用ボタンは無効化される', () => {
    render(
      <DiffPreviewModal isOpen onClose={vi.fn()} diffResult={makeDiffResult({ isBlocked: true })} onApply={vi.fn()} />
    );

    expect(screen.getByRole('button', { name: 'この内容で適用する' })).toBeDisabled();
  });

  it('isApplying:trueの場合、適用ボタンは無効化される', () => {
    render(<DiffPreviewModal isOpen onClose={vi.fn()} diffResult={makeDiffResult()} onApply={vi.fn()} isApplying />);

    expect(screen.getByRole('button', { name: 'この内容で適用する' })).toBeDisabled();
  });

  it('適用ボタンをクリックするとonApplyが呼ばれる', async () => {
    const user = userEvent.setup();
    const onApply = vi.fn();
    render(<DiffPreviewModal isOpen onClose={vi.fn()} diffResult={makeDiffResult()} onApply={onApply} />);

    await user.click(screen.getByRole('button', { name: 'この内容で適用する' }));

    expect(onApply).toHaveBeenCalledTimes(1);
  });

  it('閉じるボタンをクリックするとonCloseが呼ばれる', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<DiffPreviewModal isOpen onClose={onClose} diffResult={makeDiffResult()} onApply={vi.fn()} />);

    await user.click(screen.getByRole('button', { name: '閉じる' }));

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
