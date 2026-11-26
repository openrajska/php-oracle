<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Subprogram;

use Exception;
use Rajska\Oracle\Binds\Bind;
use Rajska\Oracle\ConnectionState;
use Rajska\Oracle\Contracts\Driver\Response;
use Rajska\Oracle\Contracts\Driver\Session;
use Rajska\Oracle\Driver\Options;
use Rajska\Oracle\Exceptions\Concerns\CarriesStatement;
use Rajska\Oracle\Exceptions\OracleException;
use Rajska\Oracle\Exceptions\UnsupportedException;
use Rajska\Oracle\Exceptions\UsageException;
use Rajska\Oracle\Statement\Execution;
use Rajska\Oracle\Statement\OutValue;
use Rajska\Oracle\Subprogram\Execution as CallExecution;
use ReflectionProperty;
use SensitiveParameter;

/**
 * A Call: a stored procedure or function invoked by name. Core generates its
 * block in named notation, with synthetic bind names (`:b0` for a function's
 * return value, `:b1`, `:b2`… for the parameters), so a parameter name never
 * has to be a valid placeholder. An omitted parameter takes its PL/SQL
 * default.
 *
 * The name and every parameter name are validated before any Round trip,
 * and they are the only text of the user's that enters the block: bound
 * values travel as binds.
 *
 * @internal
 */
final readonly class Call
{
    private const string UNQUOTED = '/\G[A-Za-z][A-Za-z0-9_$#]*/';

    /** The longest identifier, in bytes, quotes excluded. */
    private const int MAX_BYTES = 128;

    private const string IDENTIFIER = 'an identifier (a letter, then letters, digits, _, $ or #) or a double-quoted name, of at most 128 bytes';

    /** How many validated Call shapes (name and parameter names) are kept. */
    private const int KNOWN = 256;

    /**
     * @param array<string, mixed> $binds by synthetic name
     * @param array<string, string> $parameters the parameter name as the user wrote it, by synthetic name
     */
    private function __construct(
        public string $name,
        private string $arguments,
        #[SensitiveParameter]
        private array $binds,
        private array $parameters,
    ) {
    }

    /**
     * @param array<mixed> $binds keyed by parameter name
     *
     * @throws UsageException if the name or a parameter name is not valid
     */
    public static function of(string $name, #[SensitiveParameter] array $binds): self
    {
        /**
         * Calls already validated, oldest first, by name and parameter
         * names: the parameter names, the argument list and the synthetic
         * bind names. A Call's text depends on nothing else, so one
         * validation serves every later Call of the same shape.
         *
         * @var array<string, array{list<string>, string, list<string>}> $known
         */
        static $known = [];

        $keys = array_keys($binds);
        $id = $name . "\0" . implode("\0", $keys);
        $shape = $known[$id] ?? null;
        if ($shape !== null && $shape[0] === $keys) {
            [$parameters, $arguments, $synthetic] = $shape;

            return new self($name, $arguments, array_combine($synthetic, $binds), array_combine($synthetic, $parameters));
        }

        $parts = self::parts($name, 'Call name', 'each dot-separated part must be ' . self::IDENTIFIER, $name);
        if ($parts > 3) {
            throw new UsageException("Call name '$name' has $parts parts; a name has at most 3: schema.package.name.", 0, null, $name);
        }

        $arguments = [];
        $synthetic = [];
        $parameters = [];
        $seen = [];
        foreach ($binds as $key => $value) {
            if (\is_int($key)) {
                throw new UsageException("Call parameters are passed by name, but key $key is an integer (is the array a list?); key every value by its parameter name.", 0, null, $name);
            }
            $rule = 'a parameter name must be ' . self::IDENTIFIER;
            if (self::parts($key, 'Parameter name', $rule, $name) !== 1) {
                throw new UsageException("Parameter name '$key' is not valid: $rule.", 0, null, $name);
            }
            $canonical = self::canonical($key);
            if (isset($seen[$canonical])) {
                throw new UsageException("Parameters '{$seen[$canonical]}' and '$key' name the same parameter; keep one of them.", 0, null, $name);
            }
            $seen[$canonical] = $key;

            $bind = 'b' . (\count($synthetic) + 1);
            $arguments[] = "$key => :$bind";
            $synthetic[$bind] = $value;
            $parameters[$bind] = $key;
        }

        $arguments = $arguments === [] ? '' : '(' . implode(', ', $arguments) . ')';
        if (\count($known) >= self::KNOWN) {
            unset($known[array_key_first($known)]);
        }
        $known[$id] = [array_values($parameters), $arguments, array_keys($synthetic)];

        return new self($name, $arguments, $synthetic, $parameters);
    }

    public function procedure(Session $driver, Options $options, ConnectionState $connection): Execution
    {
        $sql = "BEGIN {$this->name}{$this->arguments}; END;";

        return new Execution(
            new CallExecution($this->execute($driver, $sql, $this->binds, $options), $this->parameters),
            $sql,
            $connection,
            array_values($this->parameters),
            $this->name,
        );
    }

    /**
     * Runs the Call as a function whose return value binds as `$return`. A
     * REF CURSOR return value becomes a Cursor of the Connection, and a LOB
     * a Lob.
     */
    public function function(Session $driver, Options $options, Bind $return, ConnectionState $connection): OutValue
    {
        $sql = "BEGIN :b0 := {$this->name}{$this->arguments}; END;";
        $values = $this->execute($driver, $sql, ['b0' => $return] + $this->binds, $options)->outValues();

        return new OutValue("{$this->name} return value", $connection->wrapHandles($values['b0'] ?? []), $sql, $this->name);
    }

    /**
     * @param array<string, mixed> $binds
     */
    private function execute(Session $driver, string $sql, #[SensitiveParameter] array $binds, Options $options): Response
    {
        try {
            return $driver->execute($sql, $binds, $options);
        } catch (OracleException $oracleException) {
            throw $this->named($oracleException);
        }
    }

    /**
     * The exception with `call()` set and, in a library-written message,
     * each synthetic bind replaced by the parameter it stands for. The
     * server's messages stay verbatim.
     */
    private function named(OracleException $e): OracleException
    {
        $class = $e::class;
        while (!\in_array(CarriesStatement::class, class_uses($class), true)) {
            $class = get_parent_class($class);
            if ($class === false) {
                return $e;
            }
        }
        new ReflectionProperty($class, 'call')->setValue($e, $this->name);

        if ($e instanceof UsageException || $e instanceof UnsupportedException) {
            $message = preg_replace_callback(
                "/(?:OUT bind|Bind|bind) 'b(\\d+)'/",
                fn (array $m): string => match (true) {
                    $m[1] === '0' => "The return value of {$this->name}",
                    isset($this->parameters["b$m[1]"]) => str_replace("'b$m[1]'", "'{$this->parameters["b$m[1]"]}'", $m[0]),
                    default => $m[0],
                },
                $e->getMessage(),
            );
            new ReflectionProperty(Exception::class, 'message')->setValue($e, $message);
        }

        return $e;
    }

    /**
     * Counts the dot-separated parts of a name, each an unquoted identifier
     * or a double-quoted name.
     *
     * @throws UsageException if the name is not one
     */
    private static function parts(string $name, string $what, string $rule, string $call): int
    {
        $invalid = static function (string $why) use ($name, $what, $call): UsageException {
            $link = str_contains($name, '@') ? ' Database links (@) are not supported.' : '';

            return new UsageException("$what '$name' is not valid: $why.$link", 0, null, $call);
        };

        $parts = 0;
        $at = 0;
        $length = \strlen($name);
        while (true) {
            if ($at < $length && $name[$at] === '"') {
                $close = strpos($name, '"', $at + 1);
                if ($close === false) {
                    throw $invalid('it has an unterminated quote');
                }
                $identifier = substr($name, $at + 1, $close - $at - 1);
                if ($identifier === '' || str_contains($identifier, "\0")) {
                    throw $invalid($rule);
                }
                $at = $close + 1;
            } elseif (preg_match(self::UNQUOTED, $name, $match, 0, $at) === 1) {
                $identifier = $match[0];
                $at += \strlen($identifier);
            } else {
                throw $invalid($rule);
            }
            if (\strlen($identifier) > self::MAX_BYTES) {
                throw $invalid($rule);
            }
            ++$parts;
            if ($at === $length) {
                return $parts;
            }
            if ($name[$at] !== '.') {
                throw $invalid($rule);
            }
            ++$at;
        }
    }

    /**
     * The name Oracle resolves an identifier to: unquoted, uppercased;
     * quoted, as written between the quotes.
     */
    private static function canonical(string $identifier): string
    {
        return $identifier[0] === '"' ? substr($identifier, 1, -1) : strtoupper($identifier);
    }
}
