import { afterEach, vi } from 'vitest';
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';

// vitest は globals:false なので testing-library の自動 cleanup を明示的に登録
afterEach(() => {
  cleanup();
});

// jsdom は未実装 (呼ぶと例外になる)。Modal のフォーカス制御等が呼ぶ可能性があるため既定でモックする。
Element.prototype.scrollIntoView = vi.fn();

// api.ts が参照する window.csrfToken (a-blog cms管理画面が実行時に注入するグローバル)。
window.csrfToken = 'test-csrf-token';

// @ablogcms/components (Modalの閉じるボタンのaria-label等)がwindow.ACMS.i18n()を参照する。
// a-blog cms本体のvitest.setup.tsと同じ最小モック(キーをそのまま返す)。
// Library.dialog は本体がdispatchDialog()で公開する共有インスタンス(window.ACMS.Library.dialog)
// のモック。@ablogcms/dialogを個別にバンドルせずこちらを呼ぶ(mount-module-migration-admin.tsx参照)。
(
  window as unknown as {
    ACMS: {
      i18n: (key: string) => string;
      Ready: (listener: () => void) => void;
      Library: {
        dialog: {
          confirm: ReturnType<typeof vi.fn>;
          alert: ReturnType<typeof vi.fn>;
          prompt: ReturnType<typeof vi.fn>;
        };
      };
    };
  }
).ACMS = {
  i18n: vi.fn((key: string) => key),
  // 本体のACMS.Readyは「index.js読み込み完了後(complete)なら同期的に即実行、まだなら
  // 完了を待つ」という挙動(acms.js参照)。テストでは常にcomplete相当として即時実行する。
  Ready: vi.fn((listener: () => void) => listener()),
  Library: {
    dialog: {
      confirm: vi.fn(),
      alert: vi.fn(),
      prompt: vi.fn(),
    },
  },
};
