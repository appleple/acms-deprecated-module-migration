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

export interface TemplateReferenceInfo {
  filePath: string;
  lineNumber: number;
  matchedLine: string;
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
  templateReferences: TemplateReferenceInfo[];
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
