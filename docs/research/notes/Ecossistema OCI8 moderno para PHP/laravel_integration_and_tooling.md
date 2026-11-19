# Laravel/Doctrine Integration and Modern Package Tooling for a New Oracle (OCI8-style) PHP Driver

Research date: 2026-11-19. Most findings come from reading primary source code directly: the `laravel/framework` 13.x branch, `yajra/laravel-oci8` master, `mongodb/laravel-mongodb`, `doctrine/dbal` 4.6.0 and the tool repos. They were retrieved with `gh api` or raw.githubusercontent.com on that date.

Version snapshot, all from GitHub releases on 2026-11-19 unless noted:
- Laravel v13.43.0 and v12.73.0, both released 2026-11-17. Laravel 13 requires `php ^8.3` — [releases](https://github.com/laravel/framework/releases), [composer.json 13.x](https://github.com/laravel/framework/blob/13.x/composer.json)
- PHP 8.6.0 released 19 Nov 2026 — [php.net releases JSON](https://www.php.net/releases/index.php?json&max=3)
- yajra/laravel-oci8 v13.14.1 (Laravel 13) and v12.11.1 (Laravel 12), released 2026-11-13 — [releases](https://github.com/yajra/laravel-oci8/releases)
- mongodb/laravel-mongodb 5.12.1 (2026-11-13) — [releases](https://github.com/mongodb/laravel-mongodb/releases)
- doctrine/dbal 4.6.0 (2026-11-14), 3.10.7 (2026-11-10) — [releases](https://github.com/doctrine/dbal/releases)
- Pest v5.3.7 (v5.0.0 on 2026-07-28), PHPStan 2.3.7, Larastan v3.15.0, Rector 2.8.5, rector-laravel 2.7.3, Pint v1.32.3, PHP-CS-Fixer v3.95.32, Infection 0.35.12, PHPBench 1.7.0 (2026-06-08), Orchestra Testbench v11.3.0, testcontainers-php 1.2.1, symplify/monorepo-builder 12.7.2, splitsh/lite v2.0.0 (2023-10-26), danharrin/monorepo-split-github-action v2.4.5, shivammathur/setup-php 2.40.0, singlestoredb-laravel-driver v2.0.2 — each from that repo's GitHub releases page (for example [pest](https://github.com/pestphp/pest/releases), [phpstan](https://github.com/phpstan/phpstan/releases), [rector](https://github.com/rectorphp/rector/releases), [infection](https://github.com/infection/infection/releases), [testbench](https://github.com/orchestral/testbench/releases))

---

## Q1. Laravel database internals: extension points a custom Oracle driver must implement (Laravel 12/13)

### Takeaway
A Laravel driver has six parts:
1. Connection resolver: `Connection::resolverFor('oracle', ...)`, or `DatabaseManager::extend()` / `db.connector.{driver}` container binding.
2. Connector.
3. `Connection` subclass.
4. Query grammar.
5. Query processor.
6. Schema grammar and schema builder, plus optionally `SchemaState` for `schema:dump`.

In Laravel 13 the base `Connection` is still written directly against `PDO`/`PDOStatement`. Grammars take the `Connection` in their constructor. Schema introspection (`getTables`, `getColumns`, `getIndexes`, `getForeignKeys`, `getViews`, `getTypes`, `getSchemas`) is a set of `compile*` methods on the schema grammar, with normalization in `process*` methods on the processor.

### Cited Findings

**Registration paths (3 options)**
- `DatabaseManager::makeConnection($name)` first checks `$this->extensions[$name]` (per connection name), then `$this->extensions[$config['driver']]` (per driver). Only after that does it fall back to `$this->factory->make($config, $name)`. `extend($name, callable $resolver)` stores the resolver, which receives `($config, $name)` and must return a full `Connection` — [DatabaseManager.php 13.x](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/DatabaseManager.php)
- `ConnectionFactory::createConnector(array $config)` resolves `db.connector.{driver}` from the container if it is bound. Otherwise it matches only the built-in `mysql|mariadb|pgsql|sqlite|sqlsrv` and throws `InvalidArgumentException("Unsupported driver [...]")` for anything else — [ConnectionFactory.php 13.x](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connectors/ConnectionFactory.php)
- `ConnectionFactory::createConnection($driver, $connection, $database, $prefix, $config)` first calls `Connection::getResolver($driver)`. If one exists it returns `$resolver($connection, $database, $prefix, $config)`, where `$connection` is a `PDO` or a `Closure` returning PDO (the lazy PDO resolver). `Connection::resolverFor($driver, Closure $callback)` stores the resolver in `static::$resolvers` — [ConnectionFactory.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connectors/ConnectionFactory.php), [Connection.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)
- The factory also builds read/write split PDOs (`createReadWriteConnection`, `createReadPdo`, `getReadConfig`/`getWriteConfig`, `createPdoResolverWithHosts` for host lists). In 13.x it also has a new `createDirectPdo(array $config)`. These run only when the connector path (`db.connector.oracle` binding) is used — [ConnectionFactory.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connectors/ConnectionFactory.php)
- yajra's `Oci8ServiceProvider::register()` uses `Connection::resolverFor('oracle', function ($connection, $database, $prefix, $config) {...})`. Inside the resolver it creates its own `Connector` and calls `connect($config)`, ignoring the factory-provided PDO. It then wraps the result in `new Oci8Connection(...)` and runs `setSessionVars([...])`. The default session variables are `NLS_DATE_FORMAT`/`NLS_TIMESTAMP_FORMAT` = `YYYY-MM-DD HH24:MI:SS`, `NLS_NUMERIC_CHARACTERS='.,'`, `CURRENT_SCHEMA` (from `schema`), and `EDITION` — [Oci8ServiceProvider.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8ServiceProvider.php)
- mongodb/laravel-mongodb registers via `$db->extend('mongodb', function ($config, $name) {...})` in `MongoDBServiceProvider`. The same provider also extends the session, cache, filesystem (`gridfs`) and Scout engine managers — [MongoDBServiceProvider.php](https://github.com/mongodb/laravel-mongodb/blob/master/src/MongoDBServiceProvider.php)

**`Illuminate\Database\Connection` (13.x) — methods relevant to a driver**
- Constructor: `__construct($pdo, $database = '', $tablePrefix = '', array $config = [])`. `$pdo` is typed in the docblock as `\PDO|(\Closure(): \PDO)` — [Connection.php L244–249](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)
- Hooks to override:
  - `getDefaultQueryGrammar()`: base returns `new QueryGrammar($this)`.
  - `getDefaultSchemaGrammar()`: base is empty.
  - `getDefaultPostProcessor()`: base returns `new Processor`.
  - `getSchemaBuilder()`: base returns `new SchemaBuilder($this)`.
  - `getDriverTitle()`: Postgres returns `'PostgreSQL'`; used by `db:show`.
  - `getServerVersion(): string`: base uses `PDO::ATTR_SERVER_VERSION`.
  - `isUniqueConstraintError(Exception)`: base returns `false`. Postgres checks `'23505'`; Oracle would be ORA-00001.
  - `parseUniqueConstraintViolation(Exception): array{index, columns}`: new in 13.x.
  - `executeBeginTransactionStatement()`: base calls `getPdo()->beginTransaction()`.
  - `getSchemaState(?Filesystem, ?callable)`: used by `schema:dump`.
  - `threadCount()`.
  
  Sources: [Connection.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php), [PostgresConnection.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/PostgresConnection.php)
- Query execution methods all go through `run($query, $bindings, Closure $callback)` → `runQueryCallback`. Signatures:
  - `select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])`
  - `selectResultSets(...)`: new; loops `nextRowset()`.
  - `cursor(...)`: a generator calling `$statement->fetch(...$fetchUsing)`.
  - `selectOne`, `scalar`, `insert`, `update`, `delete`, `statement`, `affectingStatement`, `unprepared`
  
  The `$fetchUsing` argument (spread into `fetchAll`/`fetch`) is new compared with Laravel 11 — [Connection.php L431–660](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)
- `bindValues($statement, $bindings)` uses `PDO::PARAM_INT` for ints, `PDO::PARAM_LOB` for resources, and `PDO::PARAM_STR` otherwise. String keys are bound as named parameters; int keys become `$key + 1`. `prepareBindings()` formats `DateTimeInterface` with `$grammar->getDateFormat()` and converts bools to int — [Connection.php L757–800](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)
- `prepared(PDOStatement $statement)` is type-hinted to `PDOStatement`. It calls `setFetchMode($this->fetchMode)` (default `PDO::FETCH_OBJ`) and fires the `StatementPrepared` event — [Connection.php L532](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)
- New in 13.x Connection:
  - `getDirectPdo()` / `setDirectPdo()` / `getDirectPdoConfig()` / `hasDirectConnection()`: a third PDO type besides read/write.
  - `getRawQueryLog()`
  - `withoutTablePrefix(Closure)`
  - `getLastCommitId()` / `mergeCommitIds()` / `hasCommitId(string, int $wait = 0)`
  - `withoutAffectingStickiness()`, `usingWritePdo(Closure)`
  - `whenTransactionRunsLongerThan()`
  
  Source: [Connection.php method list](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php). The release in which each was added was not verified.
- Transactions (`Concerns\ManagesTransactions`) call `getPdo()->commit()`, `getPdo()->exec($grammar->compileSavepoint(...))`, `getPdo()->rollBack()` and `compileSavepointRollBack`. `SqlServerConnection` overrides `executeBeginTransactionStatement` to run `exec('BEGIN TRAN')` — [ManagesTransactions.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Concerns/ManagesTransactions.php), [SqlServerConnection.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/SqlServerConnection.php)

**Query grammar (`Illuminate\Database\Query\Grammars\Grammar`, 13.x)**
- Constructor: `__construct(Connection $connection)`. Grammars are now connection-bound (`new QueryGrammar($this)`) — [Grammar.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Grammar.php)
- Public `compile*` methods:
  - `compileSelect`, `compileExists`, `compileInsert`, `compileInsertOrIgnore`
  - `compileInsertOrIgnoreReturning(Builder, array $values, array $returning, ?array $uniqueBy)` (new)
  - `compileInsertGetId(Builder, $values, $sequence)`, `compileInsertUsing`, `compileInsertOrIgnoreUsing`
  - `compileUpdate`, `compileUpsert(Builder, array $values, array $uniqueBy, array $update)`, `compileDelete`, `compileTruncate`
  - `compileJoinLateral`, `compileJsonValueCast`
  - `compileVectorDistanceExpression` + `supportsVectorDistance()` (new; relevant to Oracle 23ai/26ai `VECTOR`)
  - `compileRandom`, `compileThreadCount`, `supportsSavepoints`, `compileSavepoint`, `compileSavepointRollBack`
  - `substituteBindingsIntoRawSql`
  
  Protected hooks:
  - `compileLimit`, `compileOffset`, `compileGroupLimit`, `compileRowNumber`, `compileLock`
  - `compileJsonContains`, `compileJsonOverlaps`, `compileJsonContainsKey`, `compileJsonLength`
  - `whereDate`/`whereTime`/`whereDay`/`whereMonth`/`whereYear`, `whereLike`, `whereNullSafeEquals` (new), `whereValueBetween` (new), `whereBitwise`
  - `compileInOrderOf` (new), `compileUpdateWithJoins`, `compileDeleteWithJoins`, `wrapUnion`, `compileUnionAggregate`
  
  Source: [Query/Grammars/Grammar.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Grammars/Grammar.php)
- SQL Server grammar as a template for Oracle 12c+ syntax:
  - `compileOffset` returns `offset N rows`.
  - `compileLimit` returns `fetch next N rows only`.
  - `compileLock` returns `''`; locking is done with table hints instead.
  - `compileUpsert` generates `merge <table> using (values ...)`.
  
  Source: [SqlServerGrammar.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Grammars/SqlServerGrammar.php)
- yajra's `OracleGrammar` overrides:
  - `compileSelect`, `compileExists`, `compileGroupLimit`, `compileRowNumber`
  - `compileAnsiOffset`, `compileUnionLimitOffset` (uses OFFSET/FETCH on 12c+), `compileRowConstraint`, `compileTableExpression`
  - `compileTruncate`, `wrapJsonSelector`, `wrapTable`, `compileInsertOrIgnore`, `compileInsertGetId(…, $sequence = 'id')`, `compileInsert`
  - `compileInsertLob` / `compileUpdateLob` (LOB `RETURNING … INTO`), `compileUpdate`, `compileUpdateWithJoinsUsingRowId`, `compileUpdateFrom`
  - `compileLock`, `compileLimit`, `compileOffset`, `whereBasic`, `whereDate`, `whereTime`
  - `setMaxLength` (30-char identifiers for older versions)
  
  Source: [yajra OracleGrammar.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php)

**Processor (`Query\Processors\Processor`)**
- Methods: `processSelect`, `processInsertGetId(Builder, $sql, $values, $sequence = null)`, `processSchemas`, `processTables`, `processViews`, `processTypes`, `processColumns`, `processIndexes`, `processForeignKeys`. The base `processInsertGetId` calls `$query->getConnection()->getPdo()->lastInsertId($sequence)`, which Oracle cannot support meaningfully. It must be overridden to use `RETURNING id INTO :id` (yajra adds `Oci8Connection::insertGetId`) — [Processor.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Processors/Processor.php), [yajra Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)
- `processTables` normalizes rows to keys `name`, `schema`, `schema_qualified_name`, `size`, `comment`, `collation`, `engine`. `processViews` normalizes to `name`, `schema`, `schema_qualified_name`, `definition` — [Processor.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Processors/Processor.php)

**Schema grammar and builder**
- `Schema\Grammars\Grammar` exposes:
  - `compileCreateDatabase`, `compileDropDatabaseIfExists`, `compileSchemas()`, `compileTableExists($schema, $table)`
  - `compileTables($schema)`, `compileViews($schema)`, `compileTypes($schema)`, `compileColumns($schema, $table)`, `compileIndexes($schema, $table)`, `compileForeignKeys($schema, $table)`
  - `compileVectorIndex` / `compileDropVectorIndex` (new)
  - `compileRenameColumn`, `compileChange`, `compileFulltext`, `compileForeign`, `compileDropForeign`
  - `supportsSchemaTransactions()`
  
  Source: [Schema/Grammars/Grammar.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Schema/Grammars/Grammar.php)
- `Schema\Builder` public API (driven by those grammar and processor pairs):
  - `getSchemas`, `hasTable`, `hasView`, `getTables($schema = null)`, `getTableListing($schema = null, $schemaQualified = true)`
  - `getViews`, `getTypes`, `getColumns`, `getColumn`, `getColumnType($table, $column, $fullDefinition = false)`
  - `getIndexes`, `getIndexListing`, `hasIndex($table, $index, $type = null)`, `hasForeignKey`, `getForeignKeys`
  - `whenTableHasIndex`/`whenTableDoesntHaveIndex`, `createDatabase`, `dropDatabaseIfExists`
  
  Source: [Schema/Builder.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Schema/Builder.php)
- Schema dumps: abstract `Schema\SchemaState` with `dump(Connection $connection, $path)` and `load($path)`. Each first-party connection overrides `getSchemaState()` (for example `new PostgresSchemaState($this, $files, $processFactory)`), and it is consumed by `DumpCommand` and `MigrateCommand`. yajra ships `OracleSchemaState`, and its CI installs Instant Client `exp`/`imp` tools for this — [SchemaState.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Schema/SchemaState.php), [yajra CI](https://github.com/yajra/laravel-oci8/blob/master/.github/workflows/continuous-integration.yml)
- Laravel 13 upgrade-guide database notes: `upsert` with an empty `uniqueBy` throws `InvalidArgumentException`. Full `DELETE ... JOIN` with `ORDER BY`/`LIMIT` is now compiled for the MySQL grammar — [upgrade.md 13.x](https://github.com/laravel/docs/blob/13.x/upgrade.md)

**Reference layouts of existing drivers**
- yajra/laravel-oci8 `src/Oci8`:
  - `Oci8Connection`, `Connectors/OracleConnector`, `Query/OracleBuilder`, `Query/Grammars/OracleGrammar`, `Query/Processors/OracleProcessor`
  - `Schema/{OracleBuilder, OracleBlueprint, Grammars/OracleGrammar, OracleSchemaState, Sequence, Trigger, OracleAutoIncrementHelper, OraclePreferences}`
  - `Eloquent/OracleEloquent`, `Auth/OracleUserProvider`, `Validation/Oci8DatabasePresenceVerifier`
  - `PHPStan/{DatabaseFacadeExtension, DatabaseManagerExtension}`, `OracleReservedWords`
  
  Requires `ext-oci8 >=3.0.1`, `ext-pdo`, `illuminate/* ^13`, `yajra/laravel-pdo-via-oci8 ^3.7.1` — [repo tree](https://github.com/yajra/laravel-oci8/tree/master/src/Oci8), [composer.json](https://github.com/yajra/laravel-oci8/blob/master/composer.json)
- singlestoredb-laravel-driver: `Connect/{SingleStoreConnection, SingleStoreConnector}`, `Query/{SingleStoreQueryBuilder, SingleStoreQueryGrammar}`, `Schema/{SingleStoreSchemaBuilder, SingleStoreSchemaGrammar, Blueprint + traits}`, `SingleStoreProvider` — [repo tree](https://github.com/singlestore-labs/singlestoredb-laravel-driver)

### Inferences
- A minimal "native" Oracle package should provide `OracleConnection extends Connection`, `OracleConnector`, `Query\Grammars\OracleGrammar`, `Query\Processors\OracleProcessor`, `Schema\Grammars\OracleGrammar`, `Schema\OracleBuilder` and `Schema\OracleSchemaState`. Registration would look like this:
  ```php
  // OracleServiceProvider::register()
  $this->app->bind('db.connector.oracle', OracleConnector::class);   // lets the factory build read/write/sticky PDOs
  Connection::resolverFor('oracle', fn ($pdo, $db, $prefix, $cfg) => new OracleConnection($pdo, $db, $prefix, $cfg));
  ```
  This hybrid, unlike yajra's approach of connecting inside the resolver, keeps Laravel's read/write split, `sticky`, host arrays and lazy connection behaviour.
- Oracle-specific overrides:
  - `compileLimit`/`compileOffset`: `OFFSET n ROWS FETCH NEXT m ROWS ONLY` on 12c+.
  - `compileInsertGetId` + `processInsertGetId`: `INSERT ... RETURNING "ID" INTO :id` with an OUT bind.
  - `compileUpsert`: `MERGE INTO t USING (SELECT ... FROM dual UNION ALL ...) s ON (...) WHEN MATCHED THEN UPDATE ... WHEN NOT MATCHED THEN INSERT`.
  - `compileLock`: `FOR UPDATE` / `FOR UPDATE SKIP LOCKED` / `NOWAIT`. Oracle has no shared lock, so `lockForUpdate()` maps directly and `sharedLock()` needs a decision.
  - `compileJsonContains`: `JSON_EXISTS(col, '$?(@ == $v)' PASSING :v AS "v")` or `JSON_TEXTCONTAINS`.
  - `compileRandom`: `DBMS_RANDOM.VALUE`.
  - `compileTruncate`, `wrapValue` (upper-case and quoting policy).
  - `whereLike` with `UPPER()` for case-insensitive matching.
  - `compileVectorDistanceExpression`: `VECTOR_DISTANCE(...)` on 23ai/26ai.
  - `isUniqueConstraintError`: ORA-00001.
  - `causedByLostConnection`: ORA-03113/03114/03135/12170 and similar.
- Schema introspection should query `ALL_TABLES`/`USER_TABLES`, `ALL_TAB_COLUMNS` (+ `ALL_COL_COMMENTS`), `ALL_INDEXES`/`ALL_IND_COLUMNS`, `ALL_CONSTRAINTS`/`ALL_CONS_COLUMNS` and `ALL_VIEWS`. Results are returned through `processColumns`, `processIndexes` and similar so that `db:show`, `db:table` and `model:show` work.
- `lazy()` and `chunk()` rely on `forPage()` → limit/offset, and `lazyById()`/`chunkById()` rely on `where id > ? order by id limit` ([BuildsQueries.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Concerns/BuildsQueries.php)). They therefore work automatically once `compileLimit`/`compileOffset` are correct. `cursor()` needs a true streaming fetch with a tuned prefetch size, such as OCI `prefetch` / `oci_set_prefetch`.

### Gaps
- I did not verify which exact 12.x/13.x minor introduced `selectResultSets`, `$fetchUsing`, `directPdo`, `parseUniqueConstraintViolation`, the vector grammar methods, `whereNullSafeEquals` and `compileInOrderOf`. I only confirmed they exist in 13.x HEAD. I believe the grammar-takes-connection constructor arrived in Laravel 12.0, but this is unverified.
- Octane-specific requirements for DB drivers were not researched from primary sources. See the inferences in Q2.

---

## Q2. Does Laravel strictly require PDO? Non-PDO options and their implications (yajra, MongoDB, Telescope/Pulse, testing)

### Takeaway
Laravel does not strictly require PDO, but every default code path in `Connection`, `ManagesTransactions`, `Processor` and `QueryException` assumes a PDO-like object. Two proven strategies exist:
- **PDO shim:** a class that `extends PDO` and overrides everything. This is what yajra does via `yajra/pdo-via-oci8`.
- **Full override:** MongoDB's approach. It overrides the constructor and query paths and never sets a PDO.

For an Oracle SQL driver, a PDO-compatible facade ("shim") gives the most compatibility with the least code.

### Cited Findings
- PDO coupling points in the 13.x source:
  - `Connection::select/cursor/selectResultSets` call `getPdoForSelect()->prepare()`.
  - `statement/affectingStatement` call `getPdo()->prepare()`; `unprepared` calls `getPdo()->exec()`.
  - `executeBeginTransactionStatement` calls `getPdo()->beginTransaction()`.
  - `getServerVersion` calls `getAttribute(PDO::ATTR_SERVER_VERSION)`.
  - `prepared(PDOStatement $statement)` has a hard type-hint.
  - `ManagesTransactions` calls `getPdo()->commit()/rollBack()/exec(savepoint)`.
  - `Processor::processInsertGetId` calls `getPdo()->lastInsertId($sequence)`.
  - `QueryException` copies `errorInfo` only `if ($previous instanceof PDOException)`.
  - `ConcurrencyErrorDetector` checks `PDOException` code `40001`.
  - `MigrateCommand` checks `instanceof PDOException`.
  
  Source: grep of [src/Illuminate/Database 13.x](https://github.com/laravel/framework/tree/13.x/src/Illuminate/Database), including [QueryException.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/QueryException.php), [Processor.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Processors/Processor.php) and [ConcurrencyErrorDetector.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/ConcurrencyErrorDetector.php)
- yajra's PDO shim: `class Oci8 extends PDO`, with the docblock "This class extends PDO but overrides all of its methods", and constructor `__construct(string $dsn, ?string $username, ?string $password, ?array $options = [])`. Its `OracleConnector` passes PDO options `ATTR_CASE => CASE_LOWER`, `ERRMODE_EXCEPTION`, `ATTR_ORACLE_NULLS => NULL_NATURAL`, `ATTR_DEFAULT_FETCH_MODE => FETCH_OBJ` — [pdo-via-oci8 Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php), [OracleConnector.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Connectors/OracleConnector.php)
- yajra's PL/SQL helpers on `Oci8Connection`:
  - `executeFunction(string $functionName, array $bindings = [], int $returnType = PDO::PARAM_STR, ?int $length = null)`: binds `:result`.
  - `executeProcedure(string, array): bool`
  - `executeProcedureWithCursor($procedureName, array $bindings = [], string $cursorName = ':cursor'): array`: binds `PDO::PARAM_STMT`, wraps the result in `new Statement($cursor, ...)`, then `fetchAll(PDO::FETCH_OBJ)`, so the whole result is fetched eagerly into an array.
  - `createSqlFromProcedure()`: builds `begin proc(:a,:b,:cursor); end;`.
  - Plus `setSessionVars`, `getSequence`/`getTrigger`, `isVersionAbove*`, `useCaseInsensitiveSession`.
  
  Source: [Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)
- MongoDB's `Connection extends BaseConnection` overrides `__construct(array $config)` and never calls the parent constructor's PDO path. It creates a `MongoDB\Client`, then calls `useDefaultPostProcessor()`, `useDefaultSchemaGrammar()` and `useDefaultQueryGrammar()`, and overrides `table()`, `getSchemaBuilder()`, `getDriverName()` and the grammars — [laravel-mongodb Connection.php](https://github.com/mongodb/laravel-mongodb/blob/master/src/Connection.php)
- Query logging and listeners: `run()` → `runQueryCallback()` → `logQuery($query, $bindings, $time)`, which fires `QueryExecuted`. `listen(Closure)` is `DB::listen`, and `whenQueryingForLongerThan()` is also available. Telescope and Pulse consume `QueryExecuted`, so a driver that routes all execution through `run()` gets them for free — [Connection.php L806–937, L1170](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)
- Testing traits: `DatabaseTransactions` and `RefreshDatabase` call `$connection->setTransactionManager(...)` and `$connection->beginTransaction()`. `RefreshDatabase` also stores `$connection->getPdo()` for in-memory connections only — [DatabaseTransactions.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Foundation/Testing/DatabaseTransactions.php), [RefreshDatabase.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Foundation/Testing/RefreshDatabase.php)

### Inferences
- **Option A: PDO-compatible adapter.** `final class OciPdo extends \PDO` and `OciStatement extends \PDOStatement` wrap the new extension. This is the lowest-effort route, and Eloquent, the query builder, transactions, the testing traits, Telescope and Pulse all work unchanged. Downsides: `extends PDO` without calling `parent::__construct` is fragile with PHP 8.4+ PDO driver subclasses (`Pdo\Mysql` and similar), and Oracle-specific types (REF CURSOR, collections, LOB locators) must be smuggled through `PARAM_STMT`/`PARAM_LOB`.
- **Option B: duck-typed or native connection.** Override `select`, `cursor`, `statement`, `affectingStatement`, `unprepared`, `bindValues`, `prepared`, `executeBeginTransactionStatement`, `getServerVersion`, plus `commit`/`rollBack`/`createSavepoint`/`performRollBack` from `ManagesTransactions`, plus `processInsertGetId`. Then throw `QueryException` with a native previous exception and populate `errorInfo` manually. This is more code but enables a streaming `cursor()` from REF CURSORs and typed binds. The "PDO" slot could hold the native connection object, because the factory only needs a Closure/object. Note that `getPdo()` is still type-documented as `\PDO` (static analysis only).
- Recommended: Option B, with a thin internal `PdoLike` interface. Keep every execution inside `$this->run(...)` so `DB::listen`, query log, `pretend()`, lost-connection retry (`tryAgainIfCausedByLostConnection`), Telescope and Pulse keep working.
- `RefreshDatabase` with Oracle: `migrate:fresh` needs `Schema\Builder::dropAllTables()`, `dropAllViews()` and probably `dropAllTypes()`, implemented in the schema builder (purge recyclebin, drop sequences). Oracle DDL auto-commits, which matters for migrations in transactions, so `supportsSchemaTransactions()` must return `false`.
- Octane (inference, not source-verified): connections persist across requests in a worker. Avoid per-request static state in the driver, make session NLS settings idempotent on reconnect (re-apply in the reconnector), and be careful with `oci_pconnect`-style persistent handles combined with Octane's `DB::disconnect` hooks.

### Gaps
- No primary-source documentation exists from Laravel on officially supported "non-PDO" drivers. The conclusions come from source reading.
- Whether Telescope or Pulse inspect `$event->connection->getPdo()` directly was not checked.

---

## Q3. Idiomatic Laravel APIs for PL/SQL features (procedures, functions, REF CURSOR, pipelined functions, collections)

### Takeaway
The existing state of the art (yajra) is array-in/array-out: `executeProcedure`, `executeFunction` and `executeProcedureWithCursor`, with REF CURSORs fetched eagerly. A modern package can offer:
- a fluent, typed call builder;
- `LazyCollection` streaming of REF CURSORs;
- query-builder sources for `TABLE()`/pipelined functions;
- Eloquent models backed by table functions;
- DTO hydration.

These can be built on documented Laravel extension mechanisms: `Macroable` on `Builder`/`Connection`, custom query builders via `Connection::query()`, and `LazyCollection::make(fn () => yield ...)`.

### Cited Findings
- yajra's current API (baseline to improve on): `DB::executeProcedure($name, $bindings)`, `DB::executeFunction($name, $bindings, PDO::PARAM_LOB)`, and `DB::executeProcedureWithCursor($name, $bindings, ':cursor')`, which returns an `array` of objects — [Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)
- Laravel's `cursor()` is a Generator that yields one row at a time. `Query\Builder::cursor()` wraps it in a `LazyCollection` — [Connection.php L496](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php), [Query/Builder.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Builder.php)
- `Connection::query()` can be overridden to return a custom query builder subclass. yajra returns `Yajra\Oci8\Query\OracleBuilder`, and SingleStore ships `SingleStoreQueryBuilder` — [yajra Oci8Connection::query()](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php), [singlestore tree](https://github.com/singlestore-labs/singlestoredb-laravel-driver)
- `selectResultSets()` (13.x) shows the framework now has a concept of multiple result sets per statement. This maps naturally to Oracle implicit result sets (`DBMS_SQL.RETURN_RESULT`, 12c+) — [Connection.php L462](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)

### Inferences (design sketches)
- **Fluent procedure call builder** (facade `Oracle::` or `DB::connection('oracle')->plsql()`):
  ```php
  $result = DB::connection('oracle')->procedure('hr.pkg_emp.raise_salary')
      ->in('p_emp_id', 101)
      ->in('p_pct', 5.5, OciType::Number)
      ->out('p_new_salary', OciType::Number)
      ->inOut('p_audit', 'x', OciType::Varchar2, size: 4000)
      ->outCursor('p_rows')                 // REF CURSOR
      ->call();                              // CallResult

  $result->get('p_new_salary');              // float|int
  $result->cursor('p_rows')                  // LazyCollection<stdClass>, streamed
      ->map(fn ($r) => EmployeeData::from($r));
  ```
  Internally this builds `BEGIN hr.pkg_emp.raise_salary(p_emp_id => :p_emp_id, ...); END;` using named notation (`=>`), which is safer than positional. Execution goes through `$this->run($sql, $bindings, fn ...)` so it is logged, listenable and pretend-aware.
- **Functions:** `DB::oracle()->function('pkg.fn_tax')->returns(OciType::Number)->with(['p_amount' => 10])->call()`. `scalar()` could also accept `SELECT pkg.fn_tax(:a) FROM dual` for SQL-callable functions.
- **REF CURSOR → `LazyCollection`:**
  ```php
  public function cursorFromRefCursor(OciStatement $refCursor): LazyCollection {
      return LazyCollection::make(function () use ($refCursor) {
          try { while ($row = $refCursor->fetchObject()) { yield $row; } }
          finally { $refCursor->free(); }
      });
  }
  ```
- **Pipelined or table functions in the query builder** (macro or custom builder):
  ```php
  Builder::macro('fromTableFunction', function (string $fn, array $args = [], ?string $as = null) {
      $placeholders = implode(', ', array_fill(0, count($args), '?'));
      return $this->fromRaw("TABLE({$this->grammar->wrap($fn)}({$placeholders}))".($as ? " {$as}" : ''), $args);
  });
  DB::table(null)->fromTableFunction('pkg.pipe_sales', [2026, 'Q3'], 's')->where('s.amount', '>', 100)->lazy();
  ```
  This could also be a first-class `OracleBuilder::fromTableFunction()` with a grammar-aware `wrap`.
- **Eloquent backed by a table function or view:** use a trait `HasTableFunctionSource` that overrides `newBaseQueryBuilder()`/`newQuery()` to `fromRaw('TABLE(fn(?, ?)) alias', $params)`. Models would be read-only, with `$incrementing = false` and `save()` throwing. Parameters are set via a scope: `SalesReport::forPeriod(2026, 'Q3')->get()`. For views, a plain `$table = 'v_sales'` suffices.
- **Collections (`VARRAY`, nested tables, `TABLE OF`):** typed binds via `OciType::collection('HR.NUM_LIST', [1, 2, 3])`, mapped to an `oci_new_collection` equivalent in the new extension. Support `whereIn` via `TABLE(:coll)` (`where id in (select column_value from table(:ids))`) to avoid the 1000-item `IN` limit (ORA-01795).
- **DTO mapping:** `->as(EmployeeData::class)` on the call result or cursor. Hydrate through constructor promotion or spatie/laravel-data when installed (soft dependency).
- **Implicit results:** map `selectResultSets()` to `DBMS_SQL.RETURN_RESULT` so `DB::selectResultSets('BEGIN pkg.report; END;')` returns all implicit cursors.
- **Artisan helpers:** `oracle:describe pkg.proc` (from `ALL_ARGUMENTS`) could generate typed PHP call stubs and DTOs, enabling static analysis.

### Gaps
- No existing Laravel package offering streaming REF CURSOR `LazyCollection`s or table-function Eloquent sources was found. The sketches above are original design proposals, not observed implementations.

---

## Q4. Doctrine DBAL 4 driver interfaces (reach beyond Laravel, e.g. Symfony)

### Takeaway
DBAL 4.6 defines small, strictly typed driver interfaces: `Driver`, `Driver\Connection`, `Driver\Statement`, `Driver\Result` and `Driver\Middleware`. DBAL already contains an Oracle stack (`Driver\OCI8\*`, `Driver\PDO\OCI\Driver`, `AbstractOracleDriver`, `OraclePlatform`, `OracleSchemaManager`, the new `Platforms\Oracle\OracleMetadataProvider`). A new extension can therefore plug in by implementing the four driver interfaces and reusing `AbstractOracleDriver` plus `OraclePlatform`.

### Cited Findings
- Interfaces in [dbal 4.6.0 src/Driver.php](https://github.com/doctrine/dbal/blob/4.6.0/src/Driver.php) and [src/Driver/*](https://github.com/doctrine/dbal/tree/4.6.0/src/Driver):
  - `Driver`: `connect(array $params)`, `getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform`, `getExceptionConverter(): ExceptionConverter`.
  - `Driver\Connection extends ServerVersionProvider`: `prepare(string $sql): Statement`, `query(string $sql): Result`, `quote(string $value): string`, `exec(string $sql): int|string`, `lastInsertId(): int|string`, `beginTransaction(): void`, `commit(): void`, `rollBack(): void`, `getNativeConnection()`.
  - `Driver\Statement`: `bindValue(int|string $param, mixed $value, ParameterType $type): void`, `execute(): Result`.
  - `Driver\Result`: `fetchNumeric()`, `fetchAssociative()`, `fetchOne()`, `fetchAllNumeric()`, `fetchAllAssociative()`, `fetchFirstColumn()`, `rowCount(): int|string`, `columnCount(): int`, `free(): void`.
  - `Driver\Middleware`: `wrap(Driver $driver): Driver`.
- Existing Oracle pieces in 4.6.0:
  - `src/Driver/AbstractOracleDriver.php` and `AbstractOracleDriver/EasyConnectString.php`
  - `src/Driver/OCI8/{Driver, Connection, Statement, Result, ConvertPositionalToNamedPlaceholders, ExecutionMode, Middleware/InitializeSession, Exception/*}`
  - `src/Driver/PDO/OCI/Driver.php`, `src/Platforms/OraclePlatform.php`, `src/Platforms/Oracle/OracleMetadataProvider.php`, `src/Platforms/Keywords/OracleKeywords.php`, `src/Schema/OracleSchemaManager.php`
  
  Source: [dbal 4.6.0 tree](https://github.com/doctrine/dbal/tree/4.6.0/src)

### Inferences
- Package `vendor/oci-dbal`: `final class Driver extends AbstractOracleDriver` (inherits platform and exception converter), plus `Connection`, `Statement` and `Result` modelled on `Driver\OCI8\*`. That includes positional-to-named placeholder conversion and an execution mode for autocommit versus transactions. Users enable it with `DriverManager::getConnection(['driverClass' => \Vendor\OciDbal\Driver::class, ...])` or, in Symfony, `doctrine.dbal.driver_class`.
- Ship `Middleware\InitializeSession`-style middleware for NLS settings, mirroring DBAL's own OCI8 middleware.
- Oracle extras (REF CURSOR, OUT binds) fall outside DBAL's interface. Expose them via `getNativeConnection()` or a package-specific statement extension.
- Laravel no longer depends on `doctrine/dbal` (removed in Laravel 11 when schema introspection became native). The DBAL adapter is therefore an independent product for Symfony/Doctrine ORM, not a Laravel requirement. This is from my knowledge of the Laravel 11 changes and was not re-verified here. It is consistent with the 13.x `Schema\Builder` having its own `getColumns` and related methods.

### Gaps
- `ParameterType` cases and the DBAL 5 roadmap (if any) were not checked. The details of `OracleMetadataProvider` (new in the 4.x line) were not read.

---

## Q5. Modern package tooling: Pest, PHPStan/Larastan, Rector, Pint/PHP-CS-Fixer, Infection, PHPBench, Testbench, skeleton

### Takeaway
The November 2026 baseline:
- Pest 5 (PHP 8.4+, PHPUnit 13) with arch presets, `--type-coverage --min=100`, `--mutate --min=N`, sharding and first-party PHPStan/Rector plugins.
- PHPStan 2.3 at level 10/`max` with Larastan 3.
- Rector 2.8 + rector-laravel 2.7.
- Pint 1.32 (or PHP-CS-Fixer 3.95).
- Infection 0.35 for the non-Laravel core library.
- PHPBench 1.7.
- Orchestra Testbench 11 (Laravel 13) / 10 (Laravel 12).

Because Pest 5 requires PHP 8.4, a package that also supports PHP 8.3 must stay on Pest 4 or use PHPUnit directly.

### Cited Findings
- Pest 5 requires PHP 8.4+ and is built on PHPUnit 13. Its features:
  - `--tia` test-impact analysis (keep it out of CI except for baseline recording).
  - Time-balanced sharding: `--update-shards`, commit `tests/.pest/shards.json`, then run `--shard=1/4`.
  - First-party `pestphp/pest-plugin-phpstan` and `pestphp/pest-plugin-rector` (60 rules, `PestSetList::CODING_STYLE`), plus agent and evals plugins.
  
  Source: [Pest 5 announcement](https://pestphp.com/docs/pest5-now-available), [v5.0.0 release](https://github.com/pestphp/pest/releases/tag/v5.0.0)
- Pest mutation testing: declare `covers(Foo::class)` or `mutates(Foo::class)`, then run `./vendor/bin/pest --mutate --parallel`. Thresholds use `--min=40`; there is also `@pest-mutate-ignore` and `--everything` — [pest docs mutation-testing.md](https://github.com/pestphp/docs/blob/5.x/mutation-testing.md)
- Pest type coverage: `composer require pestphp/pest-plugin-type-coverage --dev`, then `./vendor/bin/pest --type-coverage --min=100 [--type-coverage-json=report.json]` — [type-coverage.md](https://github.com/pestphp/docs/blob/5.x/type-coverage.md)
- Pest arch testing: `arch()->preset()->php()`, `arch()->preset()->security()->ignoring('md5')`, `->toUseStrictTypes()`, `->not->toUse(['die','dd','dump'])`, `->toBeFinal()`, `->toExtend(...)`, `->toHaveSuffix(...)` — [arch-testing.md](https://github.com/pestphp/docs/blob/5.x/arch-testing.md)
- PHPStan 2.x levels go from 0 to 10. Level 10 ("new in PHPStan 2.0") reports implicit `mixed`, and `--level max` is an alias for the highest level — [rule-levels.md](https://github.com/phpstan/phpstan/blob/2.3.x/website/src/user-guide/rule-levels.md)
- yajra ships its own PHPStan extensions (`DatabaseFacadeExtension`, `DatabaseManagerExtension`) so that `DB::executeProcedure()` and similar calls type-check. Its dev dependencies are larastan ^3.1, pint ^1.21, testbench ^11, phpunit ^12.0.1 and rector ^2.0.9 — [yajra composer.json](https://github.com/yajra/laravel-oci8/blob/master/composer.json), [src/Oci8/PHPStan](https://github.com/yajra/laravel-oci8/tree/master/src/Oci8/PHPStan)
- spatie/package-skeleton-laravel currently requires `php ^8.4`, `spatie/laravel-package-tools ^1.16`, `illuminate/contracts ^11||^12||^13`, `orchestra/testbench ^11||^10||^9`, `pestphp/pest ^4.0` (+ arch and laravel plugins ^4.0), `larastan ^3.0`, `phpstan-deprecation-rules ^2.0`, `phpstan-phpunit ^2.0` and `pint ^1.14`. Its workflows are `run-tests.yml`, `phpstan.yml`, `fix-php-code-style-issues.yml`, `dependabot-auto-merge.yml` and `update-changelog.yml` — [skeleton composer.json](https://github.com/spatie/package-skeleton-laravel/blob/main/composer.json), [workflows](https://github.com/spatie/package-skeleton-laravel/tree/main/.github/workflows)
- Latest versions: Rector 2.8.5, rector-laravel (driftingly) 2.7.3, Infection 0.35.12, PHPBench 1.7.0, Pint 1.32.3, PHP-CS-Fixer 3.95.32 — GitHub releases ([rector](https://github.com/rectorphp/rector/releases), [rector-laravel](https://github.com/driftingly/rector-laravel/releases), [infection](https://github.com/infection/infection/releases), [phpbench](https://github.com/phpbench/phpbench/releases), [pint](https://github.com/laravel/pint/releases), [php-cs-fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases))

### Inferences
- Suggested dev stack per package:
  - **Core (framework-agnostic):** Pest 5 (or PHPUnit 13), PHPStan level `max` + `phpstan-strict-rules`, Infection (`--min-msi`, `--min-covered-msi`) or `pest --mutate`, PHPBench (`#[Revs]`, `#[Iterations]`, `#[ParamProviders]`) for fetch, prefetch, LOB and bulk-bind benchmarks, and `phpbench run --report=aggregate --ref=baseline` for regression checks in CI.
  - **Laravel package:** Testbench (`Orchestra\Testbench\TestCase`, `defineEnvironment()` to configure an `oracle` connection, `WithWorkbench`), Pest Laravel plugin, Larastan, rector-laravel sets (`LaravelSetList::LARAVEL_130`), and Pint with the `laravel` preset.
  - **Arch rules:** `arch()->preset()->php()`, `->preset()->strict()`, `expect('Vendor\\Oci\\Laravel')->not->toUse('dd')`, `expect('Vendor\\Oci\\Core')->not->toUse('Illuminate')` (keeps the core framework-free).
- PHP-version tension: Laravel 13 allows PHP 8.3, Pest 5 needs 8.4, and the skeleton already needs 8.4. Choose between PHP `^8.4` (with Pest 5) and `^8.3` (with Pest 4). PHP 8.6.0 was released on 2026-11-19 and should be added to the matrix as soon as setup-php and the oci8 PECL build support it.
- Ship PHPStan stubs or extensions for PL/SQL builder return types (as yajra does) and a Larastan-compatible `@method` facade docblock.

### Gaps
- I did not verify Testbench's exact Laravel/version compatibility table (inferred as Testbench 11 → L13, 10 → L12 from yajra's and the skeleton's constraints). I also did not verify whether Infection natively supports Pest 5 / PHPUnit 13, or the PHPBench 1.7 changelog.

---

## Q6. CI with real Oracle databases (GitHub Actions, gvenzl images, Testcontainers, setup-php oci8, matrices)

### Takeaway
Use `shivammathur/setup-php@v2` with `extensions: oci8` (or `pdo_oci`) and an Oracle service container. The options are `gvenzl/oracle-free` (`23.26.3*`-tagged 26ai Free; `-faststart` variants for CI; healthcheck via `healthcheck.sh`; `APP_USER`/`APP_USER_PASSWORD`; service `FREEPDB1`) or Oracle's own `container-registry.oracle.com/database/free`. yajra's real-world matrix covers PHP 8.3/8.4/8.5 × Oracle 11g/19c/21c/23ai/26ai.

### Cited Findings
- setup-php lists `oci8` and `pdo_oci` among the specially supported extensions "on all supported OS". Usage: `extensions: oci8` — [setup-php README](https://github.com/shivammathur/setup-php/blob/main/README.md)
- gvenzl/oracle-free support table: `latest[-faststart]`, `slim[-faststart]` and `full[-faststart]` are always supported. `23.26.3*` is supported, `23.26.2*` deprecated, and `23.26.1*`, `23.26.0*`, `23.9*`, `23.8*` unsupported. `-faststart` images contain a pre-expanded database and are recommended for automated tests. Env vars are `ORACLE_PASSWORD`, `APP_USER`, `APP_USER_PASSWORD` and `ORACLE_DATABASE`. The default PDB is `FREEPDB1`, the GitHub Actions example uses `--health-cmd healthcheck.sh`, and the port is reachable as `${{ job.services.oracle.ports[1521] }}`. Docker Hub tags include `23-slim-faststart`, `latest-faststart-arm64`, etc. — [oci-oracle-free README](https://github.com/gvenzl/oci-oracle-free/blob/main/README.md), [Docker Hub tags](https://hub.docker.com/r/gvenzl/oracle-free/tags)
- yajra/laravel-oci8 CI:
  - Matrix: `php: [8.3, 8.4, 8.5]`, `oracle: [11g, 19c, 21c, 23ai, 26ai]`.
  - Images: `deepdiver/docker-oracle-xe-11g:2.0`, `container-registry.oracle.com/database/adb-free:25.10.2.1` (19c), `.../database/express:21.3.0-xe`, `.../adb-free:25.9.3.2-23ai-amd64` and `.../database/free:23.26.1.0` (26ai).
  - TCP health check `bash -lc 'echo > /dev/tcp/127.0.0.1/1521'` with `--health-start-period=20m`.
  - `shivammathur/setup-php@v2` with `extensions: oci8`, `nick-invision/retry@v4` around `composer update`, and Instant Client tools (exp/imp) downloaded for schema dumps.
  - Other workflows: `static-analysis.yml`, `pint.yml`, `fresh-laravel-install-test.yml`, `semantic-release.yml`.
  
  Source: [continuous-integration.yml](https://github.com/yajra/laravel-oci8/blob/master/.github/workflows/continuous-integration.yml), [workflows dir](https://github.com/yajra/laravel-oci8/tree/master/.github/workflows)
- testcontainers-php (1.2.1, PHP 8.1+) offers `GenericContainer` with `withEnvironment`, `withHealthCheckCommand` and wait strategies (`WaitForExec`, `WaitForLog`, `WaitForHttp`, `WaitForHealthCheck`, `WaitForHostPort`). The README shows no Oracle-specific module — [testcontainers-php README](https://github.com/testcontainers/testcontainers-php/blob/main/README.md)

### Inferences
- Workflow sketch:
  ```yaml
  jobs:
    tests:
      strategy:
        fail-fast: false
        matrix:
          php: ['8.4', '8.5', '8.6']
          laravel: ['12.*', '13.*']
          oracle: ['gvenzl/oracle-free:23.26.3-slim-faststart', 'gvenzl/oracle-xe:21-slim-faststart']
          include:
            - laravel: '13.*'
              testbench: '11.*'
            - laravel: '12.*'
              testbench: '10.*'
      services:
        oracle:
          image: ${{ matrix.oracle }}
          env: { ORACLE_PASSWORD: oracle, APP_USER: laravel, APP_USER_PASSWORD: secret }
          ports: ['1521:1521']
          options: >-
            --health-cmd healthcheck.sh --health-interval 10s
            --health-timeout 5s --health-retries 30
      steps:
        - uses: actions/checkout@v6
        - uses: shivammathur/setup-php@v2
          with: { php-version: '${{ matrix.php }}', extensions: oci8, coverage: pcov }
        - run: composer require "laravel/framework:${{ matrix.laravel }}" "orchestra/testbench:${{ matrix.testbench }}" --no-update --dev
        - run: composer update --prefer-dist --no-interaction
        - run: vendor/bin/pest --parallel --ci
          env: { DB_HOST: 127.0.0.1, DB_PORT: 1521, DB_SERVICE_NAME: FREEPDB1, DB_USERNAME: laravel, DB_PASSWORD: secret }
  ```
  The `gvenzl/oracle-xe:21-slim-faststart` image name is from my prior knowledge of the sibling gvenzl repo and was not re-verified here.
- If the project ships a new C extension (not `ext-oci8`), CI must build it with `phpize` instead of `setup-php extensions:`. Instant Client can be installed from Oracle's zip (as yajra does) or via setup-php's oci8 installation, which bundles Instant Client.
- Run separate jobs for static analysis (PHPStan/Rector dry-run/Pint `--test`), mutation testing (nightly or cron, since it is slow against a real DB), and benchmarks (PHPBench on `main` with a stored baseline).
- Use Testcontainers locally (`GenericContainer('gvenzl/oracle-free:slim-faststart')` + `WaitForLog('DATABASE IS READY TO USE!')`) so `composer test` works without docker-compose. In CI, prefer `services:` to avoid Docker-in-Docker cost. The log string comes from my prior knowledge of gvenzl images and was not re-verified.
- Oracle images are amd64-heavy. gvenzl provides `-arm64` tags, which helps Apple Silicon developers.

### Gaps
- Startup times for faststart images in GitHub-hosted runners were not measured or sourced.
- I did not confirm whether setup-php's oci8 support works on PHP 8.6 on release day.

---

## Q7. Monorepo structure for an ecosystem (core driver lib, DBAL adapter, Laravel package)

### Takeaway
A monorepo with read-only split mirrors is the established pattern. Laravel itself splits `illuminate/*` with `splitsh-lite`. The maintained tooling options are `symplify/monorepo-builder` (12.7.2, July 2026) for composer.json merge and versioning, `danharrin/monorepo-split-github-action` (v2.4.5) for splitting on push or tag, and `splitsh/lite`. splitsh/lite is still used by Laravel but has had no release since v2.0.0 (2023).

### Cited Findings
- Laravel's `bin/split.sh` uses `SPLITSH_LITE="${SPLITSH_LITE:-./bin/splitsh-lite}"` and adds remotes such as `git@github.com:illuminate/auth.git` for each component — [split.sh 13.x](https://github.com/laravel/framework/blob/13.x/bin/split.sh)
- Release recency: symplify/monorepo-builder 12.7.2 (2026-07-31), splitsh/lite v2.0.0 (2023-10-26; repo last pushed 2025-11-30), danharrin/monorepo-split-github-action v2.4.5 (2026-05-12) — GitHub releases ([monorepo-builder](https://github.com/symplify/monorepo-builder/releases), [splitsh/lite](https://github.com/splitsh/lite/releases), [monorepo-split-github-action](https://github.com/danharrin/monorepo-split-github-action/releases))

### Inferences
- Proposed layout:
  ```
  /packages
    /oci-core      vendor/oci        (framework-agnostic: connection, statements, types, PL/SQL call builder, REF CURSOR iterator)
    /oci-dbal      vendor/oci-dbal   (Doctrine DBAL 4 Driver/Connection/Statement/Result, extends AbstractOracleDriver)
    /oci-laravel   vendor/laravel-oci (ServiceProvider, OracleConnection, grammars, processor, schema, SchemaState, macros, facade)
    /oci-phpstan   vendor/oci-phpstan (optional PHPStan extension)
  /benchmarks  /docs  /.github/workflows
  composer.json  (root, merged via monorepo-builder; path repositories for local dev)
  ```
- Use `monorepo-builder merge` and `validate` for consistent dependency versions, plus `release` for tagging. Split with `danharrin/monorepo-split-github-action` (per-package matrix, on tag push) into read-only repos for Packagist. Each split repo gets a "closed PRs, please contribute to monorepo" bot.
- If the C extension lives in the same ecosystem, keep it in a separate repo, because it has a different release cadence and PECL/PIE packaging. PIE is the PHP Foundation's new installer; its current status was not verified here.

### Gaps
- No authoritative comparison of monorepo tools was found. Recommendations are based on observed usage (Laravel uses splitsh) and release activity.
