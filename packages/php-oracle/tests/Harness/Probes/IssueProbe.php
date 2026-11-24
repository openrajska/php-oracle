<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Harness\Probes;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Not part of any suite (no `Test` suffix): SuiteRulesTest runs one method at
 * a time in a separate PHPUnit process to prove that PHP issues fail the run.
 * Each method raises a real engine issue, not trigger_error().
 */
final class IssueProbe extends TestCase
{
    #[Test]
    public function emits_nothing(): void
    {
        self::assertTrue(true);
    }

    #[Test]
    public function emits_a_warning(): void
    {
        $values = [];
        self::assertNull($values['missing']); // E_WARNING: Undefined array key
    }

    #[Test]
    public function emits_a_notice(): void
    {
        self::assertSame('b', array_pop(explode(',', 'a,b'))); // E_NOTICE: only variables by reference
    }

    #[Test]
    public function emits_a_deprecation(): void
    {
        $object = new class {};
        $object->dynamic = true; // E_DEPRECATED: creation of a dynamic property
        self::assertTrue($object->dynamic);
    }
}
