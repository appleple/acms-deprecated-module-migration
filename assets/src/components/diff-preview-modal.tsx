import Badge from '@ablogcms/components/badge';
import Button from '@ablogcms/components/button';
import Modal, { ModalBody, ModalFooter, ModalHeader } from '@ablogcms/components/modal';
import { HStack } from '@ablogcms/components/stack';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@ablogcms/components/table';
import type { MigrationDiffResponse } from '../types';

interface DiffPreviewModalProps {
  isOpen: boolean;
  onClose: () => void;
  diffResult: MigrationDiffResponse | null;
  isLoading?: boolean;
  error?: string | null;
  onApply: () => void;
  isApplying?: boolean;
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined) {
    return '-';
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  return String(value);
}

function DiffPreviewModal({
  isOpen,
  onClose,
  diffResult,
  isLoading = false,
  error = null,
  onApply,
  isApplying = false,
}: DiffPreviewModalProps) {
  const diff = diffResult?.diff ?? null;

  return (
    <Modal isOpen={isOpen} onClose={onClose} size="large" isScrollable>
      <ModalHeader>差分プレビュー</ModalHeader>
      <ModalBody>
        {error !== null ? (
          <p role="alert">{error}</p>
        ) : diff === null ? (
          <p>{isLoading ? '読み込み中です' : ''}</p>
        ) : (
          <>
            <p>
              {diff.sourceModuleName} → {diff.targetModuleName}
            </p>

            {diff.unsupportedReasons.length > 0 && (
              <div className="acms-admin-mb10">
                <strong>自動移行できない理由</strong>
                <ul>
                  {diff.unsupportedReasons.map((reason) => (
                    <li key={reason}>{reason}</li>
                  ))}
                </ul>
              </div>
            )}

            {diff.warnings.length > 0 && (
              <div className="acms-admin-mb10">
                <strong>注意事項</strong>
                <ul>
                  {diff.warnings.map((warning) => (
                    <li key={warning}>{warning}</li>
                  ))}
                </ul>
              </div>
            )}

            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>設定キー</TableHead>
                  <TableHead>現在の実効値</TableHead>
                  <TableHead>移行先の既定値</TableHead>
                  <TableHead>&nbsp;</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {diff.items.map((item) => (
                  <TableRow key={item.targetConfigKey}>
                    <TableCell>{item.targetConfigKey}</TableCell>
                    <TableCell>{formatValue(item.sourceEffectiveValue)}</TableCell>
                    <TableCell>{formatValue(item.targetDefaultValue)}</TableCell>
                    <TableCell>
                      {item.willChangeIfUnset ? (
                        <Badge variant="warning">明示的に書き込みます</Badge>
                      ) : (
                        <Badge variant="info">変更なし</Badge>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </>
        )}
      </ModalBody>
      <ModalFooter>
        <HStack display="inline-flex">
          <Button onClick={onClose} type="button">
            閉じる
          </Button>
          <Button
            onClick={onApply}
            type="button"
            variant="primary"
            disabled={diff === null || diff.isBlocked || isApplying}
          >
            この内容で適用する
          </Button>
        </HStack>
      </ModalFooter>
    </Modal>
  );
}

export default DiffPreviewModal;
