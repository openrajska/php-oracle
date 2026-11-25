<?php

declare(strict_types = 1);

use Rajska\Oracle\Tests\Conformance\ConformanceTestCase;
use Rajska\Oracle\Tests\TestCase;

pest()->extend(ConformanceTestCase::class)->in('Conformance');

pest()->extend(TestCase::class)->in('Api', 'Harness');
