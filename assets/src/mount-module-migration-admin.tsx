import { render } from '@ablogcms/react-utils';
import ModuleMigrationAdmin from './components/module-migration-admin';

export interface ModuleMigrationAdminFieldController {
  unmount: () => void;
}

/**
 * `<acms-module-migration-admin>` の blog-id 属性から ModuleMigrationAdmin をマウントする。
 * element自身がマウント先コンテナになる。
 *
 * 確認/アラートダイアログは `window.ACMS.Library.dialog`(a-blog cms本体が管理画面バンドルの
 * 起動時に一度だけ dispatchDialog() でマウントし公開している共有インスタンス)を呼ぶ
 * (module-migration-admin.tsx 参照)。`@ablogcms/dialog` を npm 経由でこのプラグインに
 * 独立してバンドルすると、本体側とは別インスタンス(別store)の<DialogContainer>になり、
 * dialog.confirm() が本体の<DialogContainer>に届かず永久に未解決のまま止まる不具合が
 * ブラウザでの実機確認で見つかったため、この経路は使わない。
 */
export function mountModuleMigrationAdminFromAttributes(element: HTMLElement): ModuleMigrationAdminFieldController {
  const blogIdAttr = element.getAttribute('blog-id');
  if (!blogIdAttr) {
    throw new Error('blog-id is required');
  }
  const blogId = Number(blogIdAttr);

  const root = render(<ModuleMigrationAdmin blogId={blogId} />, element);

  return {
    unmount() {
      root.unmount();
    },
  };
}
