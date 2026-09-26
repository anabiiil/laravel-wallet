<?php

declare(strict_types=1);

namespace Anabil\Wallet\Exceptions;

use RuntimeException;

/**
 * Base class for every exception thrown by the package, so applications can
 * catch them all with a single type.
 */
class WalletException extends RuntimeException {}
