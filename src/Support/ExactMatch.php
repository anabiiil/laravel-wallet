<?php

declare(strict_types=1);

namespace Anabil\Wallet\Support;

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Makes a string column compare byte for byte. MySQL's default collations
 * ignore case and trailing spaces, so without this "ABC" and "abc" would be
 * the same idempotency key (or wallet name) on MySQL but different ones on
 * PostgreSQL and SQLite. Other drivers already compare exactly.
 */
final class ExactMatch
{
    public static function apply(ColumnDefinition $column, ?string $connection = null): ColumnDefinition
    {
        $db = DB::connection($connection);

        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $charset = $db->getConfig('charset');

            $column->collation((is_string($charset) && $charset !== '' ? $charset : 'utf8mb4').'_bin');
        }

        return $column;
    }
}
