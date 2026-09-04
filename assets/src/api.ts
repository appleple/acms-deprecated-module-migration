import { fetchClient } from '@ablogcms/fetch-client';
import type {
  DetectModulesResponse,
  MigrationApplyResponse,
  MigrationDiffResponse,
  MigrationRollbackResponse,
  MigrationSnapshotsResponse,
} from './types';

/**
 * window.csrfTokenはa-blog cms本体(js/src/index.js)がmeta[name="csrf-token"]から同期的に
 * 設定するグローバル変数。プラグインの<script type="module">は仕様上deferされ本体スクリプトとの
 * 実行順序が保証されないため、まだ設定される前に参照されるとformTokenが空になり送信が失敗する
 * (module-migration-admin-element.tsでACMS.Ready後にしか初期化しないようにした後も、念のため
 * ここでも同じmetaタグから直接読むフォールバックを持たせる)。
 */
function getCsrfToken(): string {
  if (window.csrfToken) {
    return window.csrfToken;
  }
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function buildParams(handlerName: string, fields: Record<string, string | number>): URLSearchParams {
  const params = new URLSearchParams();
  params.append(handlerName, 'post');
  Object.entries(fields).forEach(([key, value]) => {
    params.append(key, String(value));
  });
  params.append('formToken', getCsrfToken());
  return params;
}

async function postAcms<T extends { success: boolean; message?: string }>(
  handlerName: string,
  fields: Record<string, string | number>
): Promise<T> {
  const params = buildParams(handlerName, fields);
  const response = await fetchClient.post<T>(window.location.href, params);
  if (!response.data.success) {
    throw new Error(response.data.message ?? '処理に失敗しました。');
  }
  return response.data;
}

export function detectModules(blogId: number, includeChildren: boolean = false): Promise<DetectModulesResponse> {
  return postAcms<DetectModulesResponse>('ACMS_POST_ModuleMigrationDetect', {
    blogId,
    includeChildren: includeChildren ? '1' : '0',
  });
}

export function fetchDiff(blogId: number, moduleId: number): Promise<MigrationDiffResponse> {
  return postAcms<MigrationDiffResponse>('ACMS_POST_ModuleMigrationDiff', { blogId, moduleId });
}

export function applyMigration(
  blogId: number,
  moduleId: number,
  optIn: boolean = false
): Promise<MigrationApplyResponse> {
  return postAcms<MigrationApplyResponse>('ACMS_POST_ModuleMigrationApply', {
    blogId,
    moduleId,
    optIn: optIn ? '1' : '0',
  });
}

export function rollbackMigration(blogId: number, snapshotId: number): Promise<MigrationRollbackResponse> {
  return postAcms<MigrationRollbackResponse>('ACMS_POST_ModuleMigrationRollback', { blogId, snapshotId });
}

export function fetchSnapshots(blogId: number, includeChildren: boolean = false): Promise<MigrationSnapshotsResponse> {
  return postAcms<MigrationSnapshotsResponse>('ACMS_POST_ModuleMigrationSnapshots', {
    blogId,
    includeChildren: includeChildren ? '1' : '0',
  });
}
