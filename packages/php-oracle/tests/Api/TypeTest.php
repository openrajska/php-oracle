<?php

declare(strict_types = 1);

namespace Rajska\Oracle\Tests\Api\TypeTest;

use Rajska\Oracle\Type;
use Rajska\Oracle\VectorFormat;

test('type has every bind catalog and column only case backed in lower camel case', function (): void {
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

    expect(array_column(array_map(
        static fn(Type $type): array => [$type->name, $type->value],
        Type::cases(),
    ), 1, 0))->toBe($expected);
});
test('vector format is backed in lower camel case', function (): void {
    expect(array_column(array_map(static fn(VectorFormat $f): array => [$f->name, $f->value], VectorFormat::cases()), 1, 0))->toBe(['Float32' => 'float32', 'Float64' => 'float64', 'Int8' => 'int8', 'Binary' => 'binary']);
});
