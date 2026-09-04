import Badge from '@ablogcms/components/badge';
import Button from '@ablogcms/components/button';
import Checkbox from '@ablogcms/components/checkbox';
import Spinner from '@ablogcms/components/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@ablogcms/components/table';
import { useEffectOnce } from '@ablogcms/react-hooks';
import { useState } from 'react';
import { useModuleMigration } from '../hooks/use-module-migration';
import type { MigrationRank, ModuleMigrationCandidate } from '../types';
import DiffPreviewModal from './diff-preview-modal';
import MigrationHistory from './migration-history';

interface ModuleMigrationAdminProps {
  blogId: number;
}

function rankBadgeVariant(rank: MigrationRank | null): 'success' | 'warning' | 'danger' | 'info' {
  if (rank === 'A') {
    return 'success';
  }
  if (rank === 'B') {
    return 'warning';
  }
  if (rank === 'C') {
    return 'danger';
  }
  return 'info';
}

function blogLabel(blogName: string | null, blogId: number): string {
  return blogName !== null ? `${blogName} (${blogId})` : `(${blogId})`;
}

function rankLabel(rank: MigrationRank | null): string {
  if (rank === 'A') {
    return 'A: 自動移行可能';
  }
  if (rank === 'B') {
    return 'B: 要レビュー';
  }
  if (rank === 'C') {
    return 'C: 要手動対応(自動移行なし/一部オプトイン)';
  }
  return '対応する移行戦略がありません';
}

function ModuleMigrationAdmin({ blogId }: ModuleMigrationAdminProps) {
  const {
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
  } = useModuleMigration(blogId);
  const [selectedModule, setSelectedModule] = useState<ModuleMigrationCandidate | null>(null);
  const [includeChildren, setIncludeChildren] = useState(true);
  const [pendingApplyAlert, setPendingApplyAlert] = useState<string | null>(null);

  useEffectOnce(() => {
    void loadModules(includeChildren);
  });

  const handleIncludeChildrenChange = (checked: boolean) => {
    setIncludeChildren(checked);
    void loadModules(checked);
  };

  const handleShowDiff = (candidate: ModuleMigrationCandidate) => {
    setSelectedModule(candidate);
    void loadDiff(candidate.moduleId, candidate.moduleBlogId);
  };

  const handleCloseDiff = () => {
    setSelectedModule(null);
    clearDiff();
  };

  const handleApply = async () => {
    if (selectedModule === null) {
      return;
    }
    const confirmed = await ACMS.Library.dialog.confirm(
      '移行を適用します。適用前の状態はスナップショットとして保存され、後から元に戻せます。よろしいですか？'
    );
    if (!confirmed) {
      return;
    }
    // ランクC(Banner→Media_Banner)は画像の実データ移行(メディアライブラリへの新規登録)を
    // 伴うため、既定で無効化された機能への明示的なオプトインとして扱う
    // (basic-design.html「5. スコープ外・非対応事項」)。
    const optIn = selectedModule.rank === 'C';
    const response = await apply(selectedModule.moduleId, selectedModule.moduleBlogId, optIn);
    if (response !== null) {
      // DiffPreviewModalのfocus-trap/スクロールロックがクローズアニメーション完了(onAfterClose)
      // まで生き続けるため、ここで直接dialog.alertを開くと本体側のfocus-trapと同時に活性化し、
      // 互いにフォーカスを奪い合って無限再帰(Maximum call stack size exceeded)に陥り、
      // スクロールロックも解除されないまま残ってしまう。モーダルが完全に閉じ終わってから
      // alertを開くよう、表示内容だけを保持してonAfterCloseに委ねる。
      setPendingApplyAlert('移行を適用しました');
      setSelectedModule(null);
    }
  };

  const handleDiffModalAfterClose = () => {
    if (pendingApplyAlert !== null) {
      const message = pendingApplyAlert;
      setPendingApplyAlert(null);
      void ACMS.Library.dialog.alert(message);
    }
  };

  const handleRollback = async () => {
    if (applyResult === null || appliedBlogId === null) {
      return;
    }
    const confirmed = await ACMS.Library.dialog.confirm('直前に適用した移行を元に戻します。よろしいですか？');
    if (!confirmed) {
      return;
    }
    const succeeded = await rollback(applyResult.snapshotId, appliedBlogId);
    if (succeeded) {
      await ACMS.Library.dialog.alert('ロールバックしました');
    }
  };

  return (
    <div>
      <p>旧モジュールから代替モジュールへの設定移行を支援します。テンプレートファイルの書き換えは行いません。</p>

      {error !== null && <p role="alert">{error}</p>}

      <Checkbox
        label="配下のブログを含める"
        checked={includeChildren}
        onChange={(e) => handleIncludeChildrenChange(e.target.checked)}
      />

      {applyResult !== null && (
        <Button onClick={() => void handleRollback()} type="button" disabled={isRollingBack}>
          直前の適用を元に戻す
        </Button>
      )}

      {isLoading ? (
        <Spinner size={20} />
      ) : modules.length === 0 ? (
        <p>対象の非推奨モジュールは見つかりませんでした</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>ブログ</TableHead>
              <TableHead>モジュールID</TableHead>
              <TableHead>現在のモジュール</TableHead>
              <TableHead>移行先</TableHead>
              <TableHead>難易度</TableHead>
              <TableHead>&nbsp;</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {modules.map((candidate) => (
              <TableRow key={`${candidate.moduleBlogId}-${candidate.moduleId}`}>
                <TableCell>{blogLabel(candidate.blogName, candidate.moduleBlogId)}</TableCell>
                <TableCell>{candidate.moduleIdentifier}</TableCell>
                <TableCell>{candidate.moduleName}</TableCell>
                <TableCell>{candidate.targetModuleName ?? '-'}</TableCell>
                <TableCell>
                  <Badge variant={rankBadgeVariant(candidate.rank)}>{rankLabel(candidate.rank)}</Badge>
                </TableCell>
                <TableCell>
                  <Button onClick={() => handleShowDiff(candidate)} type="button" disabled={candidate.rank === null}>
                    差分を確認
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <DiffPreviewModal
        isOpen={selectedModule !== null}
        onClose={handleCloseDiff}
        onAfterClose={handleDiffModalAfterClose}
        diffResult={diffResult}
        isLoading={isDiffLoading}
        error={diffError}
        onApply={() => void handleApply()}
        isApplying={isApplying}
      />

      <MigrationHistory blogId={blogId} onRollbackSuccess={() => void loadModules()} />
    </div>
  );
}

export default ModuleMigrationAdmin;
