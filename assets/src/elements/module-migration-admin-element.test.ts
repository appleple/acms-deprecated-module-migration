import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mountModuleMigrationAdminFromAttributes } from '../mount-module-migration-admin';
import { ModuleMigrationAdminElement } from './module-migration-admin-element';
import './module-migration-admin-element';

vi.mock('../mount-module-migration-admin');

const mockedMount = vi.mocked(mountModuleMigrationAdminFromAttributes);

beforeEach(() => {
  vi.clearAllMocks();
  document.body.innerHTML = '';
});

describe('acms-module-migration-admin', () => {
  it('customElements.define()で登録され、生成した要素はModuleMigrationAdminElementのインスタンスになる', () => {
    expect(customElements.get('acms-module-migration-admin')).toBe(ModuleMigrationAdminElement);
    const element = document.createElement('acms-module-migration-admin');
    expect(element).toBeInstanceOf(ModuleMigrationAdminElement);
  });

  it('customElements.define()はACMS.Ready()のコールバック内で呼ばれる(本体のCSRFトークン設定等の初期化完了を待つため)', async () => {
    const readySpy = vi.fn();
    const originalReady = window.ACMS.Ready;
    window.ACMS.Ready = readySpy;

    vi.resetModules();
    await import('./module-migration-admin-element');

    expect(readySpy).toHaveBeenCalledTimes(1);

    window.ACMS.Ready = originalReady;
  });

  it('接続時にmountModuleMigrationAdminFromAttributes()を呼びcontrollerを保持する', () => {
    const controller = { unmount: vi.fn() };
    mockedMount.mockReturnValue(controller);
    const element = document.createElement('acms-module-migration-admin');
    element.setAttribute('blog-id', '1');

    document.body.appendChild(element);

    expect(mockedMount).toHaveBeenCalledTimes(1);
    expect(mockedMount).toHaveBeenCalledWith(element);
  });

  it('既にcontrollerを保持している状態でconnectedCallbackが再度呼ばれても何もしない', () => {
    const controller = { unmount: vi.fn() };
    mockedMount.mockReturnValue(controller);
    const element = document.createElement('acms-module-migration-admin') as ModuleMigrationAdminElement;

    document.body.appendChild(element);
    expect(mockedMount).toHaveBeenCalledTimes(1);

    // DOM API を直接呼び、物理的な再接続無しに connectedCallback だけを起動する。
    element.connectedCallback();

    expect(mockedMount).toHaveBeenCalledTimes(1);
  });

  it('mountが例外を投げても握りつぶし、管理画面全体を止めない', () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    mockedMount.mockImplementation(() => {
      throw new Error('mount failed');
    });
    const element = document.createElement('acms-module-migration-admin');

    expect(() => document.body.appendChild(element)).not.toThrow();
    expect(consoleError).toHaveBeenCalled();

    consoleError.mockRestore();
  });

  it('本当に削除された場合、マイクロタスクでunmount()を呼びマウントマーカーを消す', async () => {
    const controller = { unmount: vi.fn() };
    mockedMount.mockReturnValue(controller);
    const element = document.createElement('acms-module-migration-admin');
    (element as unknown as { _reactRoot?: unknown })._reactRoot = {};
    document.body.appendChild(element);

    element.remove();
    await Promise.resolve();

    expect(controller.unmount).toHaveBeenCalledTimes(1);
    expect((element as unknown as { _reactRoot?: unknown })._reactRoot).toBeUndefined();
  });

  it('同期的な再接続(DOM移動)の場合はunmount()を呼ばない', async () => {
    const controller = { unmount: vi.fn() };
    mockedMount.mockReturnValue(controller);
    const element = document.createElement('acms-module-migration-admin');
    document.body.appendChild(element);

    const otherParent = document.createElement('div');
    document.body.appendChild(otherParent);
    // disconnectedCallback → connectedCallback が同期的に発火する「移動」を再現する。
    otherParent.appendChild(element);
    await Promise.resolve();

    expect(controller.unmount).not.toHaveBeenCalled();
  });
});
