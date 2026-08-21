import { fetchClient } from '@ablogcms/fetch-client';
import type {
  DetectModulesResponse,
  MigrationApplyResponse,
  MigrationDiffResponse,
  MigrationRollbackResponse,
} from './types';

function buildParams(handlerName: string, fields: Record<string, string | number>): URLSearchParams {
  const params = new URLSearchParams();
  params.append(handlerName, 'post');
  Object.entries(fields).forEach(([key, value]) => {
    params.append(key, String(value));
  });
  params.append('formToken', window.csrfToken);
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

export function detectModules(blogId: number): Promise<DetectModulesResponse> {
  return postAcms<DetectModulesResponse>('ACMS_POST_ModuleMigrationDetect', { blogId });
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
