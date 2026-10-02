<?php

declare(strict_types=1);

use Anabil\Wallet\Tests\Concurrency\ConcurrencyTestCase;
use Anabil\Wallet\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

// tests/Unit needs no application or database: Pest's default PHPUnit
// TestCase is enough.

// Real multi-process tests: no wrapping transaction, forked children.
pest()->extend(ConcurrencyTestCase::class)->group('concurrency')->in('Concurrency');
