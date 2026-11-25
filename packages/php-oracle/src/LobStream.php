<?php

declare(strict_types=1);

namespace Rajska\Oracle;

use Closure;

/**
 * The stream wrapper behind `Lob::stream()`: a read-only stream over the
 * LOB's chunks, holding at most one chunk at a time.
 *
 * @internal
 */
final class LobStream
{
    private const string PROTOCOL = 'rajska-lob';

    /** Set by PHP for every stream it opens through the wrapper. */
    public mixed $context = null;

    /** @var Closure(): string */
    private Closure $read;

    /** The last chunk, served from $offset on. */
    private string $buffer = '';

    private int $offset = 0;

    private bool $end = false;

    /**
     * A stream over the chunks `$read` gives, '' at the end.
     *
     * @param Closure(): string $read
     *
     * @return resource
     */
    public static function open(Closure $read)
    {
        if (!\in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }
        $context = stream_context_create([self::PROTOCOL => ['read' => $read]]);
        $stream = fopen(self::PROTOCOL . '://lob', 'rb', false, $context);
        \assert(\is_resource($stream));

        return $stream;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $options = \is_resource($this->context) ? stream_context_get_options($this->context) : [];
        $read = \is_array($options[self::PROTOCOL] ?? null) ? ($options[self::PROTOCOL]['read'] ?? null) : null;
        if (!$read instanceof Closure || !\in_array($mode, ['r', 'rb'], true)) {
            return false;
        }
        $this->read = $read;

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->offset === \strlen($this->buffer) && !$this->end) {
            $this->buffer = ($this->read)();
            $this->offset = 0;
            $this->end = $this->buffer === '';
        }
        $bytes = substr($this->buffer, $this->offset, $count);
        $this->offset += \strlen($bytes);

        return $bytes;
    }

    public function stream_eof(): bool
    {
        return $this->end;
    }

    /**
     * No stat: the size in bytes is not known before the end (CLOB and NCLOB
     * sizes count UCS-2 code units).
     */
    public function stream_stat(): false
    {
        return false;
    }
}
