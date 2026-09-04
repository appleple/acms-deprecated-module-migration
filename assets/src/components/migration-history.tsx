import Button from '@ablogcms/components/button';
import Spinner from '@ablogcms/components/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@ablogcms/components/table';
import { useEffect } from 'react';
import { blogLabel } from '../blog-label';
import { useMigrationHistory } from '../hooks/use-migration-history';

interface MigrationHistoryProps {
  blogId: number;
  includeChildren?: boolean;
  onRollbackSuccess?: () => void;
}

function MigrationHistory({ blogId, includeChildren = false, onRollbackSuccess }: MigrationHistoryProps) {
  const { snapshots, isLoading, error, isRollingBack, loadSnapshots, rollback } = useMigrationHistory(blogId, {
    onRollbackSuccess,
  });

  // includeChildrenは呼び出し元(ModuleMigrationAdmin)の「配下のブログを含める」チェックボックスと
  // 連動する。マウント時・チェック状態が変わるたびに、その範囲で一覧を取得し直す。
  useEffect(() => {
    void loadSnapshots(includeChildren);
  }, [includeChildren, loadSnapshots]);

  const handleRollback = async (snapshotId: number, snapshotBlogId: number) => {
    const confirmed = await ACMS.Library.dialog.confirm('このスナップショットの内容へ元に戻します。よろしいですか？');
    if (!confirmed) {
      return;
    }
    const succeeded = await rollback(snapshotId, snapshotBlogId);
    if (succeeded) {
      await ACMS.Library.dialog.alert('ロールバックしました');
    }
  };

  return (
    <div>
      <h2>移行履歴</h2>
      <p>過去に適用した移行のスナップショット一覧です。ページを離れた後もここから元に戻せます。</p>

      {error !== null && <p role="alert">{error}</p>}

      {isLoading ? (
        <Spinner size={20} />
      ) : snapshots.length === 0 ? (
        <p>移行履歴はありません</p>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>ブログ</TableHead>
              <TableHead>モジュールID</TableHead>
              <TableHead>移行前のモジュール</TableHead>
              <TableHead>適用日時</TableHead>
              <TableHead>&nbsp;</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {snapshots.map((snapshot) => (
              <TableRow key={snapshot.snapshotId}>
                <TableCell>{blogLabel(snapshot.blogName, snapshot.blogId)}</TableCell>
                <TableCell>{snapshot.moduleId}</TableCell>
                <TableCell>{snapshot.moduleName}</TableCell>
                <TableCell>{snapshot.snapshotDatetime}</TableCell>
                <TableCell>
                  <Button
                    onClick={() => void handleRollback(snapshot.snapshotId, snapshot.blogId)}
                    type="button"
                    disabled={isRollingBack}
                  >
                    ロールバック
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </div>
  );
}

export default MigrationHistory;
