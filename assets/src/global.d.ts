// @ablogcms/dialog をランタイムとしてバンドルせず、a-blog cms本体が dispatchDialog() で
// 一度だけ公開する window.ACMS.Library.dialog を直接呼ぶ(module-migration-admin.tsx 参照)。
// この side-effect import は型定義(AcmsLibrary.dialog の augmentation)のみを読み込み、
// ランタイムコードは含まない(@ablogcms/dialog/acms は空の .mjs)。
import '@ablogcms/types/acms';
import '@ablogcms/dialog/acms';

declare global {
  interface Window {
    csrfToken: string;
  }
}

export {};
