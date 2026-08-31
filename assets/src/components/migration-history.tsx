import Button from '@ablogcms/components/button';
import Spinner from '@ablogcms/components/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@ablogcms/components/table';
import { useEffectOnce } from '@ablogcms/react-hooks';
import { useMigrationHistory } from '../hooks/use-migration-history';

interface MigrationHistoryProps {
  blogId: number;
  onRollbackSuccess?: () => void;
}

function MigrationHistory({ blogId, onRollbackSuccess }: MigrationHistoryProps) {
  const { snapshots, isLoading, error, isRollingBack, loadSnapshots, rollback } = useMigrationHistory(blogId, {
    onRollbackSuccess,
  });

  useEffectOnce(() => {
    void loadSnapshots();
  });

  const handleRollback = async (snapshotId: number) => {
    const confirmed = await ACMS.Library.dialog.confirm('このスナップショットの内容へ元に戻します。よろしいですか？');
    if (!confirmed) {
      return;
    }
    const succeeded = await rollback(snapshotId);
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
              <TableHead>モジュールID</TableHead>
              <TableHead>移行前のモジュール</TableHead>
              <TableHead>適用日時</TableHead>
              <TableHead>&nbsp;</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {snapshots.map((snapshot) => (
              <TableRow key={snapshot.snapshotId}>
                <TableCell>{snapshot.moduleId}</TableCell>
                <TableCell>{snapshot.moduleName}</TableCell>
                <TableCell>{snapshot.snapshotDatetime}</TableCell>
                <TableCell>
                  <Button
                    onClick={() => void handleRollback(snapshot.snapshotId)}
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
