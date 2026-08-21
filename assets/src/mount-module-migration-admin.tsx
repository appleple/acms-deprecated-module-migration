import { render } from '@ablogcms/react-utils';
import ModuleMigrationAdmin from './components/module-migration-admin';

export interface ModuleMigrationAdminFieldController {
  unmount: () => void;
}

/**
 * `<acms-module-migration-admin>` の blog-id 属性から ModuleMigrationAdmin をマウントする。
 * element自身がマウント先コンテナになる。
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
