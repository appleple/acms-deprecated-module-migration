// @ablogcms/dialog をランタイムとしてバンドルせず、a-blog cms本体が dispatchDialog() で
// 一度だけ公開する window.ACMS.Library.dialog を直接呼ぶ(module-migration-admin.tsx 参照)。
import '@ablogcms/types/acms';

declare module '@ablogcms/types/acms' {
  interface AcmsLibrary {
    // @ablogcms/dialog/acms の型augmentationを読み込む方式だと、そのside-effect importが
    // 何らかの理由(削除・パス変更・importの誤削除)で効かなくなった場合、AcmsLibraryの
    // `[key: string]: any` index signatureにフォールバックしてdialogがanyになり、
    // メソッド名のtypoをtscが検知できなくなる(このプラグインが実際に呼ぶconfirm/alertの
    // シグネチャだけをここで自己完結して宣言し、外部パッケージの型構成に依存しない)。
    dialog: {
      confirm(message: string): Promise<boolean>;
      alert(message: string): Promise<void>;
    };
  }
}

declare global {
  interface Window {
    csrfToken: string;
  }
}

export {};
