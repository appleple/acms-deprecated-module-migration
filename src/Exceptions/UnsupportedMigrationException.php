<?php

namespace Acms\Plugins\DeprecatedModuleMigration\Exceptions;

/**
 * ランクC(自動適用なし)のStrategyに対してapply()を呼び出した場合にスローする例外。
 *
 * @see \Acms\Plugins\DeprecatedModuleMigration\Strategy\UserSearchAdvisoryStrategy
 */
final class UnsupportedMigrationException extends \RuntimeException
{
}
