# Testing your application

Test your real SQL and PL/SQL against a real database: a throwaway Oracle AI
Database 26ai Free container, started fresh for each CI job and thrown away
after it. [`gvenzl/oracle-free`](https://hub.docker.com/r/gvenzl/oracle-free)
images start in seconds (the `-faststart` tags) and create an application user
from environment variables.

**Install your schema's DDL, never production data.** Create the tables,
packages and types from the same DDL or migrations you deploy, then load
small fixtures your tests own. A copy of production data in CI leaks personal
data into build logs, caches and artifacts.

## The schema script

Keep the DDL in a file where every statement, SQL or PL/SQL, ends with a line
holding only `/`, as SQL*Plus accepts it:

```sql
create table customers (
    id number generated always as identity primary key,
    name varchar2(100) not null
)
/
create or replace package billing as
    procedure close_month(month_start in date);
end billing;
/
```

A small PHP script waits for the database to accept connections, then runs
it:

```php no-run
// tests/install-schema.php
require __DIR__ . '/../vendor/autoload.php';

use Rajska\Oracle\ConnectionConfig;
use Rajska\Oracle\Exceptions\ConnectionException;
use Rajska\Oracle\Oracle;

$config = new ConnectionConfig(
    dsn: getenv('DATABASE_DSN'),
    user: getenv('DATABASE_USER'),
    password: getenv('DATABASE_PASSWORD'),
    standalone: true,
);

for ($attempt = 1; ; $attempt++) {
    try {
        $connection = Oracle::connect($config);
        break;
    } catch (ConnectionException $exception) {
        if ($attempt === 120) {
            throw $exception;
        }
        sleep(1); // the container is still starting
    }
}

$statements = preg_split('/^\/\s*$/m', file_get_contents(__DIR__ . '/../database/schema.sql'));
foreach (array_filter(array_map('trim', $statements)) as $statement) {
    $connection->run($statement);
}
$connection->close();
```

## GitLab CI

```yaml
test:
  image: registry.example.com/php:8.4-oracledb   # PHP 8.4 with `pie install openrajska/php-oracle-ext`
  services:
    - name: gvenzl/oracle-free:23.26.3-faststart
      alias: oracle
  variables:
    # Read by the service: SYS / SYSTEM password and the user it creates in FREEPDB1.
    ORACLE_PASSWORD: throwaway
    APP_USER: shop
    APP_USER_PASSWORD: throwaway
    # Read by the tests.
    DATABASE_DSN: oracle:1521/FREEPDB1
    DATABASE_USER: shop
    DATABASE_PASSWORD: throwaway
  script:
    - composer install --no-interaction
    - php tests/install-schema.php
    - vendor/bin/phpunit
```

GitLab passes the job's variables to the service container too, and reaches
it by its `alias`. It waits only for the port to open, not for the database,
which is why the script retries.

## GitHub Actions

```yaml
jobs:
  test:
    runs-on: ubuntu-24.04
    services:
      oracle:
        image: gvenzl/oracle-free:23.26.3-faststart
        env:
          ORACLE_PASSWORD: throwaway
          APP_USER: shop
          APP_USER_PASSWORD: throwaway
        ports:
          - 1521:1521
        options: >-
          --health-cmd healthcheck.sh
          --health-interval 10s
          --health-timeout 5s
          --health-retries 30
    env:
      DATABASE_DSN: localhost:1521/FREEPDB1
      DATABASE_USER: shop
      DATABASE_PASSWORD: throwaway
    steps:
      - uses: actions/checkout@v5
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          tools: composer
      - name: Install oracledb
        run: |
          curl -fsSL -o pie.phar https://github.com/php/pie/releases/latest/download/pie.phar
          sudo php pie.phar install openrajska/php-oracle-ext
          php --ri oracledb
      - run: composer install --no-interaction
      - run: php tests/install-schema.php
      - run: vendor/bin/phpunit
```

## Jobs without the extension

A static-analysis or lint job does not need the extension, nor a database.
Composer still checks Core's `ext-oracledb` requirement, so tell it the
extension is not needed there:

```bash
composer install --no-interaction --ignore-platform-req=ext-oracledb
vendor/bin/phpstan analyse
```

Core's classes load and `ConnectionConfig` builds without the extension, so
static analysis and unit tests that never connect run anywhere.

## Isolation between tests

- Each `connect()` gets its own Session, and a Release rolls back, so a test
  that wraps its work in `begin()` and ends with `rollback()` (or just
  `close()`) leaves nothing behind. DDL commits implicitly, so keep it in the
  schema script.
- A pooled Session keeps package state and `ALTER SESSION` settings between
  tests in one PHP process; use `standalone: true` for tests that change
  them. See [what a Release does not reset](sessions.md#what-a-release-does-not-reset).
