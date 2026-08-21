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
(window as unknown as { ACMS: { i18n: (key: string) => string } }).ACMS = {
  i18n: vi.fn((key: string) => key),
};
