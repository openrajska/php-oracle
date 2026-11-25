<?php

declare(strict_types=1);

namespace Rajska\Oracle;

/**
 * An Oracle type, as a column describes it and as a bind declares it.
 *
 * The column-only cases (`Long`, `LongRaw`, `Urowid`, `Bfile`, `Xmltype`)
 * never name a bind. Nested table and VARRAY are both `Collection`. A column
 * of a type not fetched yet is still described; only fetching it fails.
 */
enum Type: string
{
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

    case Long = 'long';
    case LongRaw = 'longRaw';
    case Urowid = 'urowid';
    case Bfile = 'bfile';
    case Xmltype = 'xmltype';
}
