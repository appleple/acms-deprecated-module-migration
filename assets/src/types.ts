export type MigrationRank = 'A' | 'B' | 'C';

export interface ModuleMigrationCandidate {
  moduleId: number;
  moduleIdentifier: string;
  moduleName: string;
  moduleBlogId: number;
  moduleScope: 'local' | 'global';
  targetModuleName: string | null;
  rank: MigrationRank | null;
}

export interface MigrationDiffItem {
  targetConfigKey: string;
  sourceConfigKey: string | null;
  sourceEffectiveValue: unknown;
  targetDefaultValue: unknown;
  willChangeIfUnset: boolean;
}

export interface MigrationDiff {
  sourceModuleName: string;
  targetModuleName: string;
  items: MigrationDiffItem[];
  warnings: string[];
  unsupportedReasons: string[];
  isBlocked: boolean;
}

export interface MigrationResultInfo {
  moduleId: number;
  oldModuleName: string;
  newModuleName: string;
  writtenConfig: Record<string, unknown>;
  notes: string[];
}

export interface DetectModulesResponse {
  success: boolean;
  message?: string;
  modules: ModuleMigrationCandidate[];
}

export interface MigrationDiffResponse {
  success: boolean;
  message?: string;
  diff: MigrationDiff;
}

export interface MigrationApplyResponse {
  success: boolean;
  message?: string;
  result: MigrationResultInfo;
  snapshotId: number;
}

export interface MigrationRollbackResponse {
  success: boolean;
  message?: string;
}

export interface SnapshotSummary {
  snapshotId: number;
  moduleId: number;
  moduleName: string;
  snapshotDatetime: string;
  userId: number;
}

export interface MigrationSnapshotsResponse {
  success: boolean;
  message?: string;
  snapshots: SnapshotSummary[];
}
