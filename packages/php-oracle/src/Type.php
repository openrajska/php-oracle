<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * An Oracle type: of a typed Bind, or of a Column. Backed by the case name in
 * lowerCamelCase. Nested table and VARRAY are both `Collection`.
 */
enum Type: string
{
    // The Bind catalog: each has a class in Rajska\Oracle\Bind\ (Object is
    // Struct, AssociativeArray is Indexed).
    case Varchar = 'varchar';
    case Char = 'char';
    case NVarchar = 'nVarchar';
    case NChar = 'nChar';
    case Number = 'number';
    case BinaryFloat = 'binaryFloat';
    case BinaryDouble = 'binaryDouble';
    case PlsInteger = 'plsInteger';
    case Boolean = 'boolean';
    case Date = 'date';
    case Timestamp = 'timestamp';
    case TimestampTz = 'timestampTz';
    case TimestampLtz = 'timestampLtz';
    case IntervalDs = 'intervalDs';
    case IntervalYm = 'intervalYm';
    case Raw = 'raw';
    case Rowid = 'rowid';
    case Clob = 'clob';
    case NClob = 'nClob';
    case Blob = 'blob';
    case Json = 'json';
    case Vector = 'vector';
    case Cursor = 'cursor';
    case Object = 'object';
    case Collection = 'collection';
    case AssociativeArray = 'associativeArray';

    // Column only: described normally; fetching a value fails.
    case Long = 'long';
    case LongRaw = 'longRaw';
    case Urowid = 'urowid';
    case Bfile = 'bfile';
    case Xmltype = 'xmltype';
}
