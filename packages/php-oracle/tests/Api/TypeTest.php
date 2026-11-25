<?php

declare(strict_types=1);

namespace Rajska\Oracle\Tests\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rajska\Oracle\Type;
use Rajska\Oracle\VectorFormat;

final class TypeTest extends TestCase
{
    #[Test]
    public function type_has_every_bind_catalog_and_column_only_case_backed_in_lower_camel_case(): void
    {
        $expected = [
            'Varchar' => 'varchar', 'Char' => 'char', 'NVarchar' => 'nVarchar', 'NChar' => 'nChar',
            'Number' => 'number', 'BinaryFloat' => 'binaryFloat', 'BinaryDouble' => 'binaryDouble',
            'PlsInteger' => 'plsInteger', 'Boolean' => 'boolean', 'Date' => 'date', 'Timestamp' => 'timestamp',
            'TimestampTz' => 'timestampTz', 'TimestampLtz' => 'timestampLtz', 'IntervalDs' => 'intervalDs',
            'IntervalYm' => 'intervalYm', 'Raw' => 'raw', 'Rowid' => 'rowid', 'Clob' => 'clob', 'NClob' => 'nClob',
            'Blob' => 'blob', 'Json' => 'json', 'Vector' => 'vector', 'Cursor' => 'cursor', 'Object' => 'object',
            'Collection' => 'collection', 'AssociativeArray' => 'associativeArray',
            'Long' => 'long', 'LongRaw' => 'longRaw', 'Urowid' => 'urowid', 'Bfile' => 'bfile', 'Xmltype' => 'xmltype',
        ];

        self::assertSame($expected, array_column(array_map(
            static fn(Type $type): array => [$type->name, $type->value],
            Type::cases(),
        ), 1, 0));
    }

    #[Test]
    public function vector_format_is_backed_in_lower_camel_case(): void
    {
        self::assertSame(
            ['Float32' => 'float32', 'Float64' => 'float64', 'Int8' => 'int8', 'Binary' => 'binary'],
            array_column(array_map(static fn(VectorFormat $f): array => [$f->name, $f->value], VectorFormat::cases()), 1, 0),
        );
    }
}
