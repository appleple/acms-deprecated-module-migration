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

  const loadSnapshots = useCallback(async (): Promise<void> => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await fetchSnapshots(blogId);
      setSnapshots(response.snapshots);
    } catch (e) {
      setError(toErrorMessage(e, '移行履歴の取得に失敗しました。'));
    } finally {
      setIsLoading(false);
    }
  }, [blogId]);

  const rollback = useCallback(
    async (snapshotId: number): Promise<boolean> => {
      if (isRollingBackRef.current) {
        return false;
      }
      isRollingBackRef.current = true;
      setIsRollingBack(true);
      setError(null);
      try {
        await rollbackMigration(blogId, snapshotId);
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
    [blogId, loadSnapshots, onRollbackSuccess]
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
