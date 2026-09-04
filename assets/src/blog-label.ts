/**
 * 「配下のブログを含める」表示(モジュール一覧・移行履歴一覧)で、所属ブログ名とIDを
 * 併記した表示文字列を組み立てる。blogNameが無い(取得できなかった)場合はIDのみ表示する。
 */
export function blogLabel(blogName: string | null, blogId: number): string {
  return blogName !== null ? `${blogName} (${blogId})` : `(${blogId})`;
}
