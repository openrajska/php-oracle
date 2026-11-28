<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Functional\Binds\BindTest;

use Closure;
use DateTimeImmutable;
use Exception;
use Rajska\Oracle\Binds\In;
use Rajska\Oracle\Binds\In\Boolean;
use Rajska\Oracle\Binds\In\Number;
use Rajska\Oracle\Binds\InOut;
use Rajska\Oracle\Binds\InOut\PlsInteger;
use Rajska\Oracle\Binds\Out;
use Rajska\Oracle\Connection;
use Rajska\Oracle\Exceptions\ArrayDmlFailedException;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\ArrayDml;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Tests\Functional\TestCase;
use SensitiveParameter;
use Throwable;
use TypeError;

uses(TestCase::class);

enum Priority: int
{
    case Low = 1;
    case High = 2;
}

beforeEach(function (): void {
    schema()->run('create table bind_rows (id number(10) primary key, code varchar2(10) not null unique, qty number(10) not null)');
    schema()->run(<<<'SQL'
        create procedure bind_echo(p_value number) is
        begin
            null;
        end;
        SQL);
    schema()->run(<<<'SQL'
        create function bind_number(p_value number) return number is
        begin
            return p_value;
        end;
        SQL);

    $this->connection = $this->connect();
});

/**
 * Every way a bound value can reach a library exception: the server
 * refusing it, Core refusing it, a typed bind refusing it.
 *
 * @return iterable<string, array{Closure(Connection, string): mixed}>
 */
dataset('failures', function () {
    $insert = 'insert into bind_rows (id, code, qty) values (:id, :code, :qty)';

    yield 'run(), the server refuses it' => [static fn (Connection $c, #[SensitiveParameter] string $secret): Execution => $c->run('begin :n := to_number(:text); end;', ['n' => new Out\Number(), 'text' => $secret])];
    yield 'run(), the server refuses a typed bind' => [static fn (Connection $c, #[SensitiveParameter] string $secret): Execution => $c->run('begin :n := to_number(:text); end;', ['n' => new Out\Number(), 'text' => new In\Varchar($secret)])];
    yield 'run(), Core refuses it' => [static fn (Connection $c, #[SensitiveParameter] string $secret): Execution => $c->run('begin :n := :text; end;', ['n' => new Out\Number(), 'text' => [$secret]])];
    yield 'runMany(), the server refuses rows' => [static fn (Connection $c, #[SensitiveParameter] string $secret): ArrayDml => $c->runMany($insert, [['id' => 1, 'code' => $secret, 'qty' => 1], ['id' => 2, 'code' => $secret, 'qty' => 1]])];
    yield 'runMany(), Core refuses a row' => [static fn (Connection $c, #[SensitiveParameter] string $secret): ArrayDml => $c->runMany($insert, [['id' => 1, 'code' => 'a', 'qty' => 1], ['id' => 2, 'code' => 'b', 'qty' => $secret]])];
    yield 'procedure(), the server refuses it' => [static fn (Connection $c, #[SensitiveParameter] string $secret): Execution => $c->procedure('bind_echo', ['p_value' => $secret])];
    yield 'procedure(), Core refuses it' => [static fn (Connection $c, #[SensitiveParameter] string $secret): Execution => $c->procedure('bind_echo', ['p_value' => [$secret]])];
    yield 'function(), the server refuses it' => [static fn (Connection $c, #[SensitiveParameter] string $secret): float | int | string | null => $c->function('bind_number', ['p_value' => $secret])->number()];
    yield 'function(), Core refuses it' => [static fn (Connection $c, #[SensitiveParameter] string $secret): float | int | string | null => $c->function('bind_number', ['p_value' => [$secret]])->number()];
    yield 'In\Number, not a decimal literal' => [static fn (Connection $c, #[SensitiveParameter] string $secret): Number => new In\Number("1e5$secret")];
    yield 'InOut\Number, not a decimal literal' => [static fn (Connection $c, #[SensitiveParameter] string $secret): \Rajska\Oracle\Binds\InOut\Number => new InOut\Number("1e5$secret")];
});

/**
 * A typed bind given a value of the wrong PHP type: PHP refuses it with a
 * TypeError before the bind's own checks run.
 *
 * @return iterable<string, array{Closure(Connection, string): mixed}>
 */
dataset('typeErrors', function () {
    yield 'In\Boolean, a string' => [static fn (Connection $c, #[SensitiveParameter] string $secret): Boolean => new In\Boolean($secret)]; // @phpstan-ignore argument.type (the mistake is the point)
    yield 'InOut\PlsInteger, a string' => [static fn (Connection $c, #[SensitiveParameter] string $secret): PlsInteger => new InOut\PlsInteger($secret)]; // @phpstan-ignore argument.type (the mistake is the point)
});

describe('plain values', function (): void {
    it('binds plain values by name ignoring case', function (): void {
        $binds = ['id' => 1, 'PRICE' => 0.1, 'priority' => Priority::High, 'flag' => true, 'nothing' => null];

        $matches = $this->connection->select(
            'select count(*) from dual where :Id = 1 and :price = 0.1 and :priority = 2 and :flag and :nothing is null',
            $binds,
        )->value();

        expect($matches)->toBe(1);
    });
});

describe('bind mistakes', function (): void {
    it('refuses a date and names the typed binds', function (): void {
        $select = fn () => $this->connection->select('select :at from dual', ['at' => new DateTimeImmutable()]);

        expect($select)->toThrow(UsageException::class, "Bind 'at': a DateTimeInterface does not bind by itself; use In\\Date or In\\Timestamp.");
    });

    it('refuses a list of binds and names the named form', function (): void {
        // @phpstan-ignore argument.type (a list is the mistake under test)
        $select = fn () => $this->connection->select('select :id from dual', [1]);

        expect($select)->toThrow(UsageException::class, "key every value by its placeholder name without ':'");
    });
});

describe('secrets', function (): void {
    it('never shows a bound value in an error', function (Closure $failure): void {
        $secret = 'zq32secret';

        $failing = fn () => $failure($this->connection, $secret);

        expect($failing)->toThrow(function (Exception $exception) use ($secret): void {
            expect(everythingShown($exception))->not->toContain($secret);
        });
    })->with('failures');

    it('never shows a bound value in a type error', function (Closure $failure): void {
        $secret = 'zq32secret';

        $failing = fn () => $failure($this->connection, $secret);

        expect($failing)->toThrow(function (TypeError $typeError) use ($secret): void {
            expect(everythingShown($typeError))->not->toContain($secret);
        });
    })->with('typeErrors');
});

/**
 * What an exception shows: its message and trace with their arguments, its
 * SQL, and the message of every failed row.
 */
function everythingShown(Throwable | null $thrown): string
{
    if (! $thrown instanceof Throwable) {
        return '';
    }
    $shown = (string) $thrown;
    if ($thrown instanceof OracleException) {
        $shown .= $thrown->sql();
    }
    if ($thrown instanceof ArrayDmlFailedException) {
        foreach ($thrown->rows() as $row) {
            $shown .= $row->message;
        }
    }

    return $shown;
}
