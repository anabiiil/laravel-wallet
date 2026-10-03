<?php

declare(strict_types=1);

namespace Anabil\Wallet\Audit;

/**
 * How serious an audit Issue is. Only Error affects Report::passed(); a
 * Warning is informational (e.g. a balance below its floor, which is
 * expected after a credit line is reduced).
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
