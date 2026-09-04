import { useCallback, useRef, useState } from 'react';
import { applyMigration, detectModules, fetchDiff, rollbackMigration } from '../api';
import type { MigrationApplyResponse, MigrationDiffResponse, ModuleMigrationCandidate } from '../types';

function toErrorMessage(error: unknown, fallback: string): string {
  return error instanceof Error ? error.message : fallback;
}

export function useModuleMigration(blogId: number) {
  const [modules, setModules] = useState<ModuleMigrationCandidate[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [diffResult, setDiffResult] = useState<MigrationDiffResponse | null>(null);
  const [isDiffLoading, setIsDiffLoading] = useState(false);
  const [diffError, setDiffError] = useState<string | null>(null);
  const [applyResult, setApplyResult] = useState<MigrationApplyResponse | null>(null);
  // 直前に適用したモジュールの所属ブログID。applyResponseにはblogIdが含まれないため、
  // ここに保持しておきロールバック時(rollbackMigrationにはsnapshotの所有ブログIDが
  // 必要)に使う。
  const [appliedBlogId, setAppliedBlogId] = useState<number | null>(null);
  const [isApplying, setIsApplying] = useState(false);
  const [isRollingBack, setIsRollingBack] = useState(false);

  // loadDiff()の競合状態(古いモジュールのレスポンスが後発モジュール選択後に届く)を防ぐための
  // リクエスト世代カウンタ。stateではなくrefにするのは、応答到着時点の「今の世代」と
  // 比較する必要があり、クロージャに閉じ込めたstateの古い値を参照してしまうと判定できないため。
  const diffRequestSeq = useRef(0);
  // apply()/rollback()の二重送信防止。state(isApplying/isRollingBack)だけだと、
  // setStateが非同期でバッチされるため連打の2回目が同期的にすり抜けうる。refで即時に
  // 判定できるようにする。
  const isApplyingRef = useRef(false);
  const isRollingBackRef = useRef(false);
  // 直近のloadModules()呼び出しで使われたincludeChildrenを覚えておき、apply()/rollback()
  // 成功後の再取得で同じ範囲(自ブログのみ/子ブログ込み)を維持する。
  const includeChildrenRef = useRef(false);

  const loadModules = useCallback(
    async (includeChildren?: boolean): Promise<void> => {
      if (includeChildren !== undefined) {
        includeChildrenRef.current = includeChildren;
      }
      setIsLoading(true);
      setError(null);
      try {
        const response = await detectModules(blogId, includeChildrenRef.current);
        setModules(response.modules);
      } catch (e) {
        setError(toErrorMessage(e, '一覧の取得に失敗しました。'));
      } finally {
        setIsLoading(false);
      }
    },
    [blogId]
  );

  const loadDiff = useCallback(async (moduleId: number, moduleBlogId: number): Promise<void> => {
    const requestId = ++diffRequestSeq.current;
    setDiffError(null);
    setDiffResult(null);
    setIsDiffLoading(true);
    try {
      const response = await fetchDiff(moduleBlogId, moduleId);
      if (diffRequestSeq.current !== requestId) {
        // 別のモジュールへ切り替える(=clearDiff/loadDiffが呼ばれ世代が進んだ)などして
        // このリクエストが既に古くなっている場合、結果を反映しない。
        return;
      }
      setDiffResult(response);
    } catch (e) {
      if (diffRequestSeq.current !== requestId) {
        return;
      }
      setDiffError(toErrorMessage(e, '差分の取得に失敗しました。'));
    } finally {
      if (diffRequestSeq.current === requestId) {
        setIsDiffLoading(false);
      }
    }
  }, []);

  const clearDiff = useCallback((): void => {
    diffRequestSeq.current += 1;
    setDiffResult(null);
    setDiffError(null);
    setIsDiffLoading(false);
  }, []);

  const apply = useCallback(
    async (moduleId: number, moduleBlogId: number, optIn: boolean = false): Promise<MigrationApplyResponse | null> => {
      if (isApplyingRef.current) {
        return null;
      }
      isApplyingRef.current = true;
      setIsApplying(true);
      setError(null);
      try {
        const response = await applyMigration(moduleBlogId, moduleId, optIn);
        setApplyResult(response);
        setAppliedBlogId(moduleBlogId);
        clearDiff();
        await loadModules();
        return response;
      } catch (e) {
        setError(toErrorMessage(e, '適用に失敗しました。'));
        return null;
      } finally {
        isApplyingRef.current = false;
        setIsApplying(false);
      }
    },
    [loadModules, clearDiff]
  );

  const rollback = useCallback(
    async (snapshotId: number, snapshotBlogId: number): Promise<boolean> => {
      if (isRollingBackRef.current) {
        return false;
      }
      isRollingBackRef.current = true;
      setIsRollingBack(true);
      setError(null);
      try {
        await rollbackMigration(snapshotBlogId, snapshotId);
        setApplyResult(null);
        setAppliedBlogId(null);
        await loadModules();
        return true;
      } catch (e) {
        setError(toErrorMessage(e, 'ロールバックに失敗しました。'));
        return false;
      } finally {
        isRollingBackRef.current = false;
        setIsRollingBack(false);
      }
    },
    [loadModules]
  );

  return {
    modules,
    isLoading,
    error,
    diffResult,
    isDiffLoading,
    diffError,
    applyResult,
    appliedBlogId,
    isApplying,
    isRollingBack,
    loadModules,
    loadDiff,
    clearDiff,
    apply,
    rollback,
  };
}
