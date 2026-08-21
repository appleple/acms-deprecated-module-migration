import { DialogContainer } from '@ablogcms/dialog';
import { render } from '@ablogcms/react-utils';
import ModuleMigrationAdmin from './components/module-migration-admin';

export interface ModuleMigrationAdminFieldController {
  unmount: () => void;
}

/**
 * `<acms-module-migration-admin>` の blog-id 属性から ModuleMigrationAdmin をマウントする。
 * element自身がマウント先コンテナになる。
 *
 * `@ablogcms/dialog` の dialog.confirm()/alert() はモジュール内のシングルトンstoreを
 * 購読する<DialogContainer>が必要。a-blog cms本体の管理画面バンドル(admin.js)は自前で
 * dispatchDialog()を呼びグローバルに1つ<DialogContainer>をマウントしているが、それは
 * 本体バンドルが読み込む@ablogcms/dialogのモジュールインスタンス限定のstoreを購読している。
 * このプラグインはtsdownで@ablogcms/*を自身のadmin.jsへ独立してバンドルしており、本体側
 * とは別インスタンス(別store)になるため、本体の<DialogContainer>には届かず
 * dialog.confirm()が永久に未解決のまま止まる(ブラウザでの実機確認で検出)。
 * そのためプラグイン自身のReactツリー内にも<DialogContainer>を用意し、
 * バンドルされた自分自身のdialogインスタンスに対して解決させる。
 */
export function mountModuleMigrationAdminFromAttributes(element: HTMLElement): ModuleMigrationAdminFieldController {
  const blogIdAttr = element.getAttribute('blog-id');
  if (!blogIdAttr) {
    throw new Error('blog-id is required');
  }
  const blogId = Number(blogIdAttr);

  const root = render(
    <>
      <ModuleMigrationAdmin blogId={blogId} />
      <DialogContainer />
    </>,
    element
  );

  return {
    unmount() {
      root.unmount();
    },
  };
}
