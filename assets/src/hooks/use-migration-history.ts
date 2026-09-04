import { useCallback, useRef, useState } from 'react';
import { fetchSnapshots, rollbackMigration } from '../api';
import type { SnapshotSummary } from '../types';

function toErrorMessage(error: unknown, fallback: string): string {
  return error instanceof Error ? error.message : fallback;
}

interface UseMigrationHistoryOptions {
  onRollbackSuccess?: () => void;
}

export function useMigrationHistory(blogId: number, options: UseMigrationHistoryOptions = {}) {
  const { onRollbackSuccess } = options;
  const [snapshots, setSnapshots] = useState<SnapshotSummary[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [isRollingBack, setIsRollingBack] = useState(false);

  // use-module-migration.tsのapply()/rollback()と同じ理由(state更新は非同期でバッチされ
  // 連打の2回目が同期的にすり抜けうる)でrefを使う。
  const isRollingBackRef = useRef(false);
  // 直近のloadSnapshots()呼び出しで使われたincludeChildrenを覚えておき、rollback()成功後の
  // 再取得で同じ範囲(自ブログのみ/子ブログ込み)を維持する(use-module-migration.tsの
  // includeChildrenRefと同じ理由)。
  const includeChildrenRef = useRef(false);

  const loadSnapshots = useCallback(
    async (includeChildren?: boolean): Promise<void> => {
      if (includeChildren !== undefined) {
        includeChildrenRef.current = includeChildren;
      }
      setIsLoading(true);
      setError(null);
      try {
        const response = await fetchSnapshots(blogId, includeChildrenRef.current);
        setSnapshots(response.snapshots);
      } catch (e) {
        setError(toErrorMessage(e, '移行履歴の取得に失敗しました。'));
      } finally {
        setIsLoading(false);
      }
    },
    [blogId]
  );

  const rollback = useCallback(
    // snapshotBlogIdは、そのスナップショットの所有ブログ(=ロールバック時のblogIdパラメータ)。
    // 「配下のブログを含める」表示時はhookのblogId(表示起点のブログ)と一致しないことがあるため、
    // 呼び出し元がスナップショットごとの実際のblogIdを渡す(use-module-migration.tsのrollback()と
    // 同じ理由)。
    async (snapshotId: number, snapshotBlogId: number): Promise<boolean> => {
      if (isRollingBackRef.current) {
        return false;
      }
      isRollingBackRef.current = true;
      setIsRollingBack(true);
      setError(null);
      try {
        await rollbackMigration(snapshotBlogId, snapshotId);
        await loadSnapshots();
        onRollbackSuccess?.();
        return true;
      } catch (e) {
        setError(toErrorMessage(e, 'ロールバックに失敗しました。'));
        return false;
      } finally {
        isRollingBackRef.current = false;
        setIsRollingBack(false);
      }
    },
    [loadSnapshots, onRollbackSuccess]
  );

  return {
    snapshots,
    isLoading,
    error,
    isRollingBack,
    loadSnapshots,
    rollback,
  };
}
