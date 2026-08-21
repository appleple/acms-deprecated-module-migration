import {
  mountModuleMigrationAdminFromAttributes,
  type ModuleMigrationAdminFieldController,
} from '../mount-module-migration-admin';

/**
 * `@ablogcms/react-utils`のrender()は、コンテナに既にReact rootがマウント済みなら
 * (`container._reactRoot`が真なら)再マウントをスキップする。disconnectedCallbackで
 * root.unmount()した後にこのマーカーが残っていると、再接続時にrender()が何もせず
 * 古い(unmount済みの)rootを返してしまうため、unmount直後に消しておく。
 */
function resetMountMarker(container: HTMLElement): void {
  delete (container as unknown as { _reactRoot?: unknown })._reactRoot;
}

/**
 * `<acms-module-migration-admin>` カスタム要素。
 *
 * 非推奨モジュール移行画面(ModuleMigrationAdmin)をマウントする。Light DOMに直接Reactを
 * マウントし、自身は追加のDOMを持たない。
 */
export class ModuleMigrationAdminElement extends HTMLElement {
  private controller: ModuleMigrationAdminFieldController | null = null;

  connectedCallback(): void {
    if (this.controller) {
      return;
    }

    try {
      this.controller = mountModuleMigrationAdminFromAttributes(this);
    } catch (error) {
      // 単一要素の初期化失敗が管理画面全体を止めないよう、ここで握りつぶして報告する。
      console.error(error);
    }
  }

  disconnectedCallback(): void {
    const controller = this.controller;
    // 物理的なDOM移動によるdisconnectedCallback→connectedCallbackの同期的な再発火に
    // 対応するため、後片付け全体をマイクロタスクへ遅らせisConnectedで
    // 「移動」と「本当の削除」を区別する。
    queueMicrotask(() => {
      if (this.isConnected) {
        return;
      }
      controller?.unmount();
      this.controller = null;
      resetMountMarker(this);
    });
  }
}

if (!customElements.get('acms-module-migration-admin')) {
  customElements.define('acms-module-migration-admin', ModuleMigrationAdminElement);
}
