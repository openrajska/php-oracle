# API tipada e núcleo plugável modernizam Oracle no PHP

O **ext-oci8** é hoje a única base PHP que fala PL/SQL de verdade: REF CURSOR, cursores aninhados, implicit results, binds OUT/IN OUT e arrays associativos de escalares. Mesmo assim, **não suporta object types, records, collections de objetos, executemany, JSON nativo nem VECTOR**, e quase todo o trabalho de manutenção está nas mãos de um único engenheiro da Oracle. O **PDO_OCI** não serve como base para PL/SQL: ele rejeita `PDO::PARAM_STMT`, ignora os tipos de parâmetro e não tem statement cache. O **yajra/laravel-oci8**, por sua vez, é uma fantasia de PDO escrita em PHP puro por cima do oci8. Para caber nessa fantasia ele recorre a uma série de gambiarras: todos os binds viram VARCHAR2, a paginação usa ROWNUM por padrão, o model é descoberto com `debug_backtrace()`, a transação é só uma flag em PHP, cada chamada aceita um único REF CURSOR e os valores OUT se perdem no helper de procedure. A recomendação deste relatório é construir o ecossistema novo de cima para baixo. Primeiro vem um **contrato PHP tipado e independente de backend**, que cobre call builder com notação nomeada, cursores lazy, implicit results, collections e object types via introspecção, além de executeMany com batch errors. Esse contrato estreia sobre o ext-oci8, para entregar valor imediatamente. O núcleo nativo definitivo só é escolhido depois de um **spike na Fase 1**, que precisa confirmar se o crate Rust thin da Oracle (`oracledb` 26.0.0-beta.6) cobre REF CURSOR, object types e implicit results. Essa cobertura **ainda não foi verificada**. Se o spike passar, uma extensão Rust distribuída como binário pré-compilado via PIE é a única rota que junta desempenho nativo com zero Instant Client. Se falhar, a alternativa é uma extensão sobre ODPI-C. No Laravel, uma `Connection` nativa, e não uma subclasse falsa de PDO, roteia tudo por `run()` e mantém Telescope, Pulse e `DB::listen` funcionando. O ferramental de base é PHP ^8.4, Pest 5, PHPStan nível 10, Rector 2.8 e Testbench 11, com CI rodando sobre `gvenzl/oracle-free`.

## O ext-oci8 cobre o PL/SQL clássico, mas parou antes dos object types

### O que o C do oci8 realmente faz

A API do ext-oci8 continua procedural e baseada em `resource`. Connections e statements são resources, e só `OCILob` e `OCICollection` são classes ([oci8.stub.php](https://github.com/php/pecl-database-oci8/blob/main/oci8.stub.php)). O RFC de unbundling chamou a migração de resource para objeto de "a major effort" que "should be done by the corporate sponsor of the Oracle extensions" ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)). Ninguém fez essa migração. Por baixo dessa superfície antiga, porém, o oci8 cobre bem o PL/SQL "clássico":

- **REF CURSOR.** Usa `SQLT_RSET` com um statement criado por `oci_new_cursor`, e o validador de bind rejeita qualquer outra coisa. Uma coluna `CURSOR(...)` no SELECT vira um statement aninhado (`outcol->is_cursor = 1`), que precisa ser executado e lido separadamente ([oci8_statement.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)).
- **Implicit results** (`DBMS_SQL.RETURN_RESULT`). São implementados com `OCIStmtGetNextResult` e só compilam com cliente ≥ 12 ([oci8_statement.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)).
- **Arrays associativos.** `oci_bind_array_by_name` faz o bind de *PL/SQL index-by table* (não é array DML). Aceita apenas NUMBER/INT, FLOAT, tipos de caractere e DATE. **BOOLEAN, LOB, RAW, TIMESTAMP e elementos objeto caem no `default:` e são rejeitados** ([oci8_statement.c L1608-1653](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)).
- **Nested tables e VARRAYs.** `oci_new_collection` aceita só `OCI_TYPECODE_TABLE` e `OCI_TYPECODE_VARRAY`, e os elementos precisam ser DATE, VARCHAR2 ou numéricos. Qualquer outro tipo gera "Unknown or unsupported type of element" ([oci8_collection.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_collection.c)).
- **Object types.** O oci8 nunca chama `OCIObjectGetAttr`, `OCIObjectSetAttr` nem `OCIObjectPin`, ou seja, **não existe acesso a atributos de object types (ADTs)** ([fontes do oci8](https://github.com/php/pecl-database-oci8)). Sem essas chamadas, nada do que se apoia em objetos funciona: procedures com parâmetros `%ROWTYPE` ou RECORD, `TABLE OF emp_obj` e funções que retornam objetos.

A camada de desempenho e alta disponibilidade é madura. O oci8 tem statement cache via `OCIStmtPrepare2`, prefetch de linhas e de LOBs (este com cliente ≥ 12.2) e um session pool OCI interno por trás de `oci_connect` (`use_spool = 1`). Também tem DRCP (connection class e purity), FAN, TAF, call timeout (cliente ≥ 18) e atributos de end-to-end tracing ([oci8.c](https://github.com/php/pecl-database-oci8/blob/main/oci8.c), [oci8_interface.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_interface.c)).

As lacunas estruturais ficam em outro lugar. **Não há DML array binding**: o `OCIStmtExecute` roda com `iters = 1`. Não há cursores roláveis, nem AQ, CQN, SODA ou JSON/VECTOR nativos ([oci8_statement.c](https://github.com/php/pecl-database-oci8/blob/main/oci8_statement.c)). Uma coluna SQL BOOLEAN do 23ai/26ai nem aparece no switch de define e cai no fallback genérico `SQLT_CHR`. O caminho é o mesmo para JSON, e a documentação da Oracle avisa que, com `SQLT_CHR`, "the JSON text size is restricted to 32K only" ([Oracle OCI JSON](https://docs.oracle.com/en/database/oracle/oracle-database/26/lnoci/oci-support-json.html)). Por fim, NUMBER volta como string. O pedido de um modo int opcional está aberto, e a resposta do mantenedor foi "PR welcomed" ([pecl-database-oci8 #5](https://github.com/php/pecl-database-oci8/issues/5)).

### PDO_OCI: bom para SQL simples, inútil para PL/SQL

O PDO_OCI é um driver bem mais fraco que o oci8:

- No hook de alocação de parâmetro, `case PDO_PARAM_STMT: return 0;`. Isso significa **nenhum REF CURSOR** ([oci_statement.c](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_statement.c)).
- Só STR e LOB são tipos de bind reais. `PARAM_INT` é ignorado, e uma mantenedora explicou que "the current pdo_oci is designed to ignore all PARAM_TYPE specifications" ([pdo_oci #26](https://github.com/php/pecl-database-pdo_oci/issues/26)). BOOL vai como os caracteres `'1'`/`'0'`.
- O prepare usa `OCIStmtPrepare` v1, ou seja, **sem statement cache**. A conexão usa `OCIServerAttach`/`OCISessionBegin`, **sem pool, sem DRCP e sem `OCIStmtGetNextResult`**. `lastInsertId` também não é suportado ([oci_driver.c](https://github.com/php/pecl-database-pdo_oci/blob/main/oci_driver.c)).
- Não há uma subclasse `Pdo\Oci` no modelo de drivers do PHP 8.4, ao contrário de `Pdo\Mysql` e `Pdo\Pgsql`, e portanto não há onde pendurar métodos específicos do Oracle ([pdo_oci.c](https://github.com/php/pecl-database-pdo_oci/blob/main/pdo_oci.c)).
- Há bugs abertos sérios, entre eles "pdo_oci pointing all records to the last resource blob when using fetchAll" ([pdo_oci #27](https://github.com/php/pecl-database-pdo_oci/issues/27)).

| Capacidade | ext-oci8 3.4.1 | PDO_OCI 1.2.0 | ODPI-C 26.0 |
|---|---|---|---|
| REF CURSOR OUT / cursor aninhado | Sim / Sim | **Não** / Não | Sim / Sim |
| Implicit results | Sim (12c+) | Não | Sim |
| Array associativo PL/SQL | Só escalares | Não | Sim |
| Nested table / VARRAY | Só elementos escalares | Não | Sim, inclusive objetos |
| Object types / RECORD | **Não** | Não | Sim (`dpiObject`) |
| PL/SQL BOOLEAN | Sim (12c+) | String '1'/'0' | Sim |
| SQL BOOLEAN, JSON (OSON), VECTOR | Fallback para string | Fallback | Sim |
| DML array bind (executemany) | **Não** | Não | Sim |
| Statement cache / pool / DRCP | Sim | **Não** | Sim |
| AQ, CQN, SODA | Não | Não | Sim |

Fontes: código de [pecl-database-oci8](https://github.com/php/pecl-database-oci8), [pecl-database-pdo_oci](https://github.com/php/pecl-database-pdo_oci) e as [release notes do ODPI-C](https://github.com/oracle/odpi/blob/main/doc/src/releasenotes.rst).

### Distribuição e manutenção: unbundled, PIE e bus factor 1

Desde o PHP 8.4, os dois drivers vivem fora do php-src. A votação do RFC terminou em **26 a 1**, e os motivos citados foram a dependência de uma biblioteca proprietária, os muitos bugs abertos e o fato de que "most of the recent changes were all to skip tests" ([RFC](https://wiki.php.net/rfc/unbundle_imap_pspell_oci8)). O oci8 3.4.1 saiu em 2025-12-23, foi testado do PHP 8.2 ao 8.5, e a correção para o 8.6 já está na main ([PECL oci8](https://pecl.php.net/package/oci8), [commits](https://github.com/php/pecl-database-oci8/commits/main)). Ele é publicado como `oci8/oci8` para `pie install` ([README](https://github.com/php/pecl-database-oci8/blob/main/README.md)).

No `package.xml`, **Sharad Chandran R (Oracle) é o único lead ativo**, e nenhuma feature nova de tipo Oracle entrou desde a 3.4.0 ([package.xml](https://github.com/php/pecl-database-oci8/blob/main/package.xml)). O pedido para reescrever o oci8 sobre ODPI-C foi fechado sem ação ([pecl-database-oci8 #48](https://github.com/php/pecl-database-oci8/issues/48)). Enquanto isso, o ODPI-C tem releases frequentes: a 6.0.0 (2026-05-04) abandonou clientes anteriores ao 19c, e a 26.0.0 (2026-09-10) adotou versionamento por ano ([ODPI-C release notes](https://github.com/oracle/odpi/blob/main/doc/src/releasenotes.rst)). A conclusão prática é que **um ecossistema novo não deve esperar que a Oracle adicione JSON, VECTOR ou ADTs ao oci8**. O investimento da Oracle em drivers está em python-oracledb, node-oracledb e ODPI-C.

## O yajra veste o OCI8 com uma fantasia de PDO e paga em fidelidade

### Uma PDO que nunca chama `parent::__construct()`

`Yajra\Pdo\Oci8 extends \PDO` sobrescreve todos os métodos e **nunca chama o construtor pai**. O docblock admite que o propósito é fazer "instanceof checks and type-hinting of existing code" funcionarem ([pdo-via-oci8 Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)). Todo conceito do PDO é reimplementado em PHP sobre `oci_*`, e cada reimplementação vem com sua gambiarra:

- **Placeholders.** Como o OCI8 só aceita binds nomeados, `prepare()` reescreve `?` para `:p0, :p1…` com uma regex PCRE. Essa regex tem uma longa história de bugs, inclusive SQL corrompido dentro de literais `q'…'` ([LO #933](https://github.com/yajra/laravel-oci8/issues/933), [PVO #37](https://github.com/yajra/pdo-via-oci8/issues/37)).
- **Transações.** `beginTransaction()` só liga uma flag em PHP, e fora dessa flag **cada statement faz auto-commit**. Bind de LOB força um commit implícito depois da escrita ([Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)). Há relatos como "DB rollBack not working properly" ([LO #501](https://github.com/yajra/laravel-oci8/issues/501)).
- **Sessões compartilhadas.** `oci_connect` reutiliza a conexão para credenciais idênticas, de modo que duas conexões Laravel "separadas" podem dividir a mesma sessão e a mesma transação ([PVO #137](https://github.com/yajra/pdo-via-oci8/issues/137)).
- **`lastInsertId`.** O `prepare()` captura o nome da tabela com regex, e depois o método supõe uma sequence `{table}_id_seq` e faz duas idas extras ao banco, com o nome interpolado no SQL ([Oci8.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8.php)).
- **Erros.** `errorInfo()` sempre retorna SQLSTATE `HY000`, e a exceção **inclui os valores bindados na mensagem**, o que pode vazar PII para os logs ([Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)).
- **Métodos ausentes.** `bindColumn`, `nextRowset` e `debugDumpParams` lançam exceção, e os dois últimos com a mensagem copiada "setFetchMode has not been implemented".

Herdar de `PDO` também cobra um preço a cada versão do PHP. A mudança de assinatura de `PDO::query` no PHP 8 e as deprecações do 8.1 quebraram a subclasse várias vezes ([PVO #82](https://github.com/yajra/pdo-via-oci8/issues/82), [PVO #86](https://github.com/yajra/pdo-via-oci8/issues/86), [PVO #109](https://github.com/yajra/pdo-via-oci8/issues/109)).

### Dialeto Laravel: decisões que viram dívida

O `laravel-oci8` tem cerca de 8,1 mil linhas sobre `Illuminate\Database`, e boa parte delas são decisões que viraram dívida:

- **Versão configurada em vez de detectada.** `serverVersion()` retorna `getConfig('server_version') ?? '11g'` ([Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)). Por isso, **quem não configura `server_version` fica na paginação ROWNUM**. Um usuário mediu 23 s para `paginate(25)` sobre 500 mil linhas, contra 0,8 s com `ROW_NUMBER()` ([LO #870](https://github.com/yajra/laravel-oci8/issues/870)).
- **Todo valor vai como string.** `bindValues()` faz bind de tudo como `PDO::PARAM_STR`, exceto strings acima de 3999 bytes, que viram CLOB. Inteiros, booleanos e datas chegam ao Oracle como VARCHAR2 e dependem da conversão implícita via NLS, que é ajustada com `ALTER SESSION` a cada conexão ([Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)).
- **Model descoberto pela pilha de chamadas.** `compileInsertGetId` e `incrementBySequence` usam `debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT)` para achar o model Eloquent chamador ([OracleGrammar.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/Grammars/OracleGrammar.php)).
- **Auto-increment com triggers.** É emulado com o par SEQUENCE + TRIGGER, e as identity columns só entram com `generatedAs` ([OracleAutoIncrementHelper.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Schema/OracleAutoIncrementHelper.php)).
- **Case folding.** Os identificadores são convertidos para maiúsculas e entre aspas, e as chaves voltam em minúsculas via `CASE_LOWER`. Isso quebra tabelas com nomes minúsculos e aliases de queries raw ([LO #743](https://github.com/yajra/laravel-oci8/issues/743), [LO #1000](https://github.com/yajra/laravel-oci8/issues/1000)).
- **ORA-01795.** O limite de 1000 itens no `IN` é contornado fatiando a lista em `orWhereIn`s com um bind por valor ([OracleBuilder.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Query/OracleBuilder.php)).
- **Batch insert sem array bind.** Vira `select ?,? from dual union all …`.
- **LOBs sempre carregados por inteiro.** Um relato mediu **5 s para 200 linhas** com CLOB ([LO #700](https://github.com/yajra/laravel-oci8/issues/700)).

### PL/SQL é a área mais fraca

Todo o suporte a PL/SQL se resume a três helpers que montam strings: `executeProcedure`, `executeFunction` e `executeProcedureWithCursor`. A notação é posicional (`begin proc(:a,:b,:cursor); end;`), e o nome da procedure é interpolado com `sprintf`. O helper de cursor aceita **um único REF CURSOR**, sempre como último argumento, e o materializa inteiro com `fetchAll(FETCH_OBJ)` ([Oci8Connection.php](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)). Quem precisa de mais cursores ouve do mantenedor que deve executar à mão ([LO #452](https://github.com/yajra/laravel-oci8/issues/452)).

Há também uma contradição entre código e documentação. `executeProcedure` recebe `$bindings` **por valor** e retorna `bool`, então os valores OUT nunca chegam ao chamador. A documentação oficial, porém, mostra `$bindings['p_total']` preenchido depois da chamada ([docs yajrabox](https://yajrabox.com/docs/laravel-oci8/master/stored-procedure) versus [código](https://github.com/yajra/laravel-oci8/blob/master/src/Oci8/Oci8Connection.php)). Isso vem da leitura do código e não foi executado.

O restante do PL/SQL também está ausente ou incompleto:

- **Implicit results** não são suportados ([Statement.php](https://github.com/yajra/pdo-via-oci8/blob/master/src/Pdo/Oci8/Statement.php)).
- **Collections** só funcionam passando `SQLT_NTY` como "tipo PDO", e o resultado é um `OCICollection` cru ([LO #412](https://github.com/yajra/laravel-oci8/issues/412)).
- **Arrays OUT** maiores que a entrada não voltam, porque o max table length é `count($variable)`.
- **Pipelined e `TABLE()`** só existem como SQL raw.

| Gambiarra no yajra | Substituto moderno |
|---|---|
| Subclasse de `PDO` sem `parent::__construct` | Connection nativa no Laravel; contrato próprio no core |
| Todo bind como VARCHAR2 + NLS via `ALTER SESSION` | Binds tipados (NUMBER, DATE, TIMESTAMP, BOOLEAN) |
| `server_version` default `'11g'` | Detecção de versão no primeiro uso |
| ROWNUM wrapping | `OFFSET … FETCH NEXT` (12c+) |
| SEQUENCE + TRIGGER, `debug_backtrace()` | Identity columns + `RETURNING … INTO` com OUT bind |
| Chunking de `IN` em 1000 | Bind de collection em `TABLE(:ids)` |
| `fetchAll` eager de cursor e LOB | Cursor lazy com fetch em lote; LOB como stream |
| Transação como flag PHP | Modo de execução explícito no driver |

O projeto está ativo: v13.14.1 saiu em 2026-11-13 e o pacote tem cerca de 130 mil downloads por mês ([Packagist](https://packagist.org/packages/yajra/laravel-oci8/stats.json)). Ainda assim, é sustentado por um mantenedor e um contribuidor muito ativo, e o esforço vai para acompanhar as gramáticas do Laravel, não para revisar a arquitetura. Essa é a lacuna que um ecossistema novo pode ocupar.

## Dez canais de saída do PL/SQL pedem um modelo de bind tipado

### O catálogo que o cliente precisa cobrir

Um cliente Oracle moderno precisa cobrir cerca de dez canais pelos quais dados saem do banco:

1. result sets;
2. REF CURSOR como OUT ou retorno de função;
3. colunas `CURSOR()` aninhadas;
4. implicit results;
5. escalares OUT e IN OUT;
6. `RETURNING INTO`, que sempre devolve arrays;
7. collections, records e object types;
8. `DBMS_OUTPUT`;
9. batch errors e contagem por linha;
10. os tipos novos do 26ai: SQL BOOLEAN, VECTOR e JSON.

Fontes: [docs de bind do python-oracledb](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html) e [docs de PL/SQL do node-oracledb](https://node-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html).

Do lado do cliente, **pipelined functions (PIPE ROW), polymorphic table functions, `TABLE()` sobre collections e tabelas temporárias são apenas queries**. O que o cliente precisa oferecer é um bind de collection excelente para alimentar `TABLE(:bind)`.

Há regras do Oracle que moldam a API:

- Só arrays associativos `INDEX BY PLS_INTEGER` podem ser bindados.
- `TABLE()` sobre um array associativo exige que o tipo esteja declarado na **especificação de um package**, ou o erro é ORA-22905.
- Funções chamadas a partir de SQL podem *receber* tipos exclusivos do PL/SQL, mas não *retorná-los*.

Fontes: [oracle-base](https://oracle-base.com/articles/12c/plsql-to-sql-interface-enhancements-for-plsql-only-data-types-12cr1.php) e [Oracle 26ai Associative Arrays](https://docs.oracle.com/en/database/oracle/oracle-database/26/lnpls/associative-arrays.html). No 26ai, o limite da lista `IN` subiu de 1000 para **65535** ([python-oracledb bind](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)). As *private temporary tables* (18c+) ficam em memória, usam o prefixo `ORA$PTT_` e são descartadas no fim da transação ou da sessão ([Oracle coretec](https://blogs.oracle.com/coretec/18c-private-temporary-tables)). Implicit results podem ter **formatos diferentes a cada execução**, então cada um precisa ser descrito separadamente ([oracle-base](https://oracle-base.com/articles/12c/implicit-statement-results-12cr1.php)).

Os drivers maduros já ensinaram o que não fazer:

- No node-oracledb, o tamanho padrão de string OUT é **200 bytes**, e um tamanho insuficiente gera ORA-06502 ([node-oracledb bind](https://node-oracledb.readthedocs.io/en/latest/user_guide/bind.html)).
- No python-oracledb, cada `gettype()` custa uma ida ao banco e precisa de cache ([python-oracledb bind](https://python-oracledb.readthedocs.io/en/latest/user_guide/bind.html)).
- No modo thick, o cursor pai precisa ficar aberto até os implicit results serem lidos, ou o erro é DPI-1039 ([python-oracledb PL/SQL](https://python-oracledb.readthedocs.io/en/latest/user_guide/plsql_execution.html)).
- Os modos thin preferem **falhar alto**: `RETURNING` inválido gera DPY-2048 ou NJS-149, onde o thick falha em silêncio.

O python-oracledb é a referência mais rica. Seu modelo tem duas camadas: valores PHP simples para IN e um `Var` tipado para OUT, arrays e NULLs. Tem ainda `callproc`/`callfunc`, introspecção de object types em tempo de execução com `gettype()` (sem geração de código) e `executemany` com `batcherrors`, `arraydmlrowcounts` e `batch_size`.

### Esboço da API do core (framework-agnóstico)

Os nomes de pacote e namespace abaixo são ilustrativos. A ideia central é que **valores simples bastam para IN**, e todo o resto (OUT, IN OUT, cursores, arrays e NULLs tipados) passa por especificações de bind tipadas, que geram blocos anônimos em **notação nomeada** (`p => :p`).

```php
<?php declare(strict_types=1);

use OpenRajska\Oracle\{Connection, ConnectionOptions, Type, Bind, NumberMode};

$db = Connection::connect(
    dsn: 'tcps://db.exemplo.com.br:1522/FREEPDB1',
    user: 'hr',
    password: getenv('ORA_PASSWORD'),
    options: new ConnectionOptions(
        prefetchRows: 500,          // OCI prefetch / tamanho do lote no thin
        statementCacheSize: 50,
        numberMode: NumberMode::Native, // int|float quando seguro; string para precisão > 15 dígitos
        module: 'billing-api',      // end-to-end tracing (V$SESSION.MODULE)
    ),
);

// 1) Procedure com IN, OUT, IN OUT e DOIS REF CURSORs — impossível no yajra hoje
$r = $db->procedure('hr.pkg_emp.raise_salary')
    ->in('p_emp_id', 101)
    ->in('p_pct', 5.5, Type::Number)
    ->out('p_new_salary', Type::Number)
    ->inOut('p_audit', 'inicial', Type::Varchar2, size: 4000) // tamanho explícito evita ORA-06502
    ->outCursor('p_history')
    ->outCursor('p_peers')
    ->call();
// gera: BEGIN hr.pkg_emp.raise_salary(p_emp_id => :p_emp_id, ..., p_peers => :p_peers); END;

$novoSalario = $r->get('p_new_salary');
foreach ($r->cursor('p_history') as $row) { /* lazy, busca em lotes de prefetchRows */ }
$pares = $r->cursor('p_peers')->all();

// 2) Function: escalar, BOOLEAN PL/SQL e SYS_REFCURSOR como retorno
$imposto = $db->function('pkg_fin.fn_tax')->returns(Type::Number)->call(p_amount: 1500);
$ativo   = $db->function('pkg_emp.is_active')->returns(Type::Boolean)->call(p_id: 7); // bool nativo
$rc      = $db->function('pkg_emp.f_get_cursor')->returns(Type::Cursor)->call();  // Cursor (IteratorAggregate)

// 3) Implicit results (DBMS_SQL.RETURN_RESULT): cada um descrito separadamente
$stmt = $db->execute('BEGIN pkg_rel.fechamento_mensal(:ano); END;', ['ano' => 2026]);
foreach ($stmt->implicitResults() as $i => $rs) {
    $colunas = $rs->columns();             // metadados por result set (formatos variam)
    foreach ($rs as $row) { /* ... */ }
}

// 4) Pipelined function (PIPE ROW) e TABLE() — só uma query, mas com streaming
$vendas = $db->query(
    'SELECT * FROM TABLE(pkg_vendas.pipe_sales(:ano, :tri)) s WHERE s.amount > :min',
    ['ano' => 2026, 'tri' => 'Q3', 'min' => 100],
); // Cursor lazy

// 5) Collection SQL em TABLE(:ids) — substitui o chunking de IN em 1000
$ids = $db->type('SYS.ODCINUMBERLIST')->new(range(1, 50_000)); // metadata em cache por conexão
$emps = $db->query(
    'SELECT * FROM employees WHERE employee_id IN (SELECT column_value FROM TABLE(:ids))',
    ['ids' => $ids],
)->all();

// 6) Array associativo (INDEX BY PLS_INTEGER): fast path contíguo
$nomes = Bind::array(Type::Varchar2, maxElements: 1000, elementSize: 100);
$db->procedure('pkg_emp.list_names')->in('p_dept', 50)->out('p_names', $nomes)->call();
// e caminho geral via $db->type('PKG.T_STR_ASSOC') para arrays esparsos

// 7) DBMS_OUTPUT preso à mesma sessão
$db->dbmsOutput()->enable();
$db->procedure('pkg_debug.run')->call();
$linhas = $db->dbmsOutput()->read(); // get_lines em blocos, não get_line por linha

// 8) Array DML com batch errors e contagem por linha (o oci8 não tem executemany)
$res = $db->executeMany(
    'INSERT INTO pedidos (id, cliente, total) VALUES (:id, :cliente, :total)',
    $linhas,
    batchErrors: true, rowCounts: true, batchSize: 10_000,
);
foreach ($res->errors() as $offset => $erro) { /* OracleException com ->oraCode */ }
```

### Object types, records e collections de objetos: introspecção e TypeMap

O padrão a copiar junta duas ideias. A primeira é o `gettype()` do python-oracledb, que faz introspecção lazy com cache por conexão. A segunda é o `SQLData`/typeMap do JDBC, que mapeia classes do usuário para tipos do Oracle. Em PHP 8.4, atributos e classes `readonly` permitem hidratar DTOs sem geração de código:

```php
use OpenRajska\Oracle\Attributes\{OracleType, Attr};

#[OracleType('HR.ADDRESS_T')]
final readonly class Address
{
    public function __construct(
        #[Attr('STREET')] public string $street,
        #[Attr('CITY')]   public string $city,
        #[Attr('ZIP')]    public ?string $zip = null,
    ) {}
}

$db->typeMap()->register(Address::class);

// Função que retorna um object type → DTO tipado
$end = $db->function('hr.pkg_addr.get_address')->returns(Address::class)->call(p_id: 7);

// TABLE OF HR.ADDRESS_T como IN → list<Address> é serializado
$db->procedure('hr.pkg_addr.bulk_upsert')->in('p_addrs', $db->type('HR.ADDRESS_TAB')->new($listaDeAddress))->call();

// RECORD / %ROWTYPE de package (PL/SQL-only type, bind permitido desde 12.1)
$rec = $db->type('HR.PKG_EMP.EMP_REC')->new(['EMPNO' => 1, 'ACTIVE' => true]); // preencha todos os atributos
$db->procedure('hr.pkg_emp.save')->inOut('p_rec', $rec)->call();
```

O ext-oci8 não oferece acesso a atributos de objeto, então o backend oci8 precisa de uma ponte. A rota mais barata usa JSON no servidor. No 26ai, o construtor `JSON` aceita tipos agregados do PL/SQL (records e collections), e `JSON_VALUE … RETURNING` converte JSON de volta para eles ([PL/SQL 26ai changes](https://docs.oracle.com/en/database/oracle/oracle-database/23/lnpls/release-changes.html)). O backend pode gerar um bloco anônimo que serializa o objeto como `CLOB` (para fugir do limite de 32K do `SQLT_CHR`) e hidrata o DTO em PHP. Em bancos anteriores ao 26ai, a ponte precisa de wrappers PL/SQL gerados por um comando `oracle:describe` que lê `ALL_ARGUMENTS`. Os dois caminhos são mais lentos que um bind nativo de objeto, e esse é exatamente o argumento para um núcleo que fale objetos nativamente (seção seguinte).

### Contrato de backend: lotes, capacidades e falha explícita

Para que o mesmo PHP rode sobre ext-oci8, Rust thin ou ODPI-C, o core define um contrato interno pequeno. Duas decisões de desempenho ficam embutidas nele. A primeira é que **o fetch é por lote** (`fetchBatch`), e nunca por célula, para amortizar o custo de cruzar a fronteira PHP↔nativo. A segunda é que **cada backend declara suas capacidades**, para que a API falhe com uma mensagem acionável em vez de falhar em silêncio:

```php
interface Driver
{
    public function connect(ConnectParams $params): DriverConnection;
    public function capabilities(): Capabilities; // objects, implicitResults, arrayDml, nativeJson, vector, async...
}

interface DriverConnection
{
    public function prepare(string $sql): DriverStatement;
    public function describeType(string $qualifiedName): TypeDescriptor; // cache por conexão no core
    public function serverVersion(): Version;                             // detectada, nunca configurada
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function ping(): bool;
}

interface DriverStatement
{
    public function bind(string $name, BindSpec $spec): void;
    public function execute(ExecMode $mode, int $iterations = 1): ExecResult;
    /** @return list<array<string, mixed>> já convertidos em zvals no código nativo */
    public function fetchBatch(int $maxRows): array;
    /** @return list<ColumnMeta> */
    public function columns(): array;
    public function nextImplicitResult(): ?DriverStatement;
    public function outValue(string $name): mixed;
}

// No backend oci8:
// $caps->objects === false → $db->type('HR.ADDRESS_T') lança UnsupportedFeature
// com sugestão: "use JsonObjectBridge (26ai) ou troque para o backend nativo".
```

O modelo de erro precisa de quatro coisas:

- uma `OracleException` com `oraCode`, offset e SQL;
- **nenhum valor bindado na mensagem**, ao contrário do yajra;
- mapeamento de ORA-00001 para violação de unicidade;
- mapeamento de ORA-03113, 03114, 03135 e 12170 para conexão perdida.

## O núcleo certo depende de um spike: Rust thin contra ODPI-C

### Cinco rotas, nenhuma vence em tudo

**Rota 1: ext-oci8 com uma camada userland.** É a mais rápida de entregar e tem desempenho nativo. Herda, porém, a falta de objetos, executemany e JSON/VECTOR. Exige compilar contra o SDK do Instant Client e não resolve o "plug and play" ([PECL oci8](https://pecl.php.net/package/oci8)).

**Rota 2: FFI sobre ODPI-C.** O ODPI-C cobre quase tudo: UDTs, AQ, SODA, DRCP, pools, JSON e VECTOR. Ele carrega o `libclntsh` dinamicamente e tem licença dupla UPL/Apache 2.0 ([ODPI-C](https://oracle.github.io/odpi/)). Há três problemas. O `ffi.enable` vem como `preload` por padrão, o que libera o FFI só na CLI e em arquivos de preload ([manual FFI](https://www.php.net/manual/en/ffi.configuration.php)). O custo por chamada é alto: num relato anedótico, uma função trivial via FFI ficou **mais de 300× mais lenta** que a equivalente nativa ([rblind.com](https://rblind.com/post/9115605)). E o Instant Client continua obrigatório. **Estimativa:** 100 mil linhas × 10 colunas dão cerca de 1 milhão de travessias, o que custaria entre 0,3 e 1 s de overhead puro. Por isso o FFI serve como veículo de protótipo, não como núcleo de produção.

**Rota 3a: extensão C sobre ODPI-C.** Tem o desempenho mais alto e a cobertura mais ampla. Como o ODPI-C faz `dlopen` do cliente em tempo de execução, a extensão compila sem os headers do SDK, o que simplifica os binários pré-compilados. O Instant Client continua necessário na hora de rodar. O ODPI-C atende python-oracledb (thick), node-oracledb (thick), godror e rust-oracle, mas **o PHP não aparece na lista** ([ODPI-C](https://oracle.github.io/odpi/)).

**Rota 3b: extensão Rust (ext-php-rs) sobre o crate thin da Oracle.** É a novidade decisiva de 2026. O crate `oracledb` **26.0.0-beta.6 (2026-11-12)** se descreve como "a pure Rust driver for Oracle Database without any Oracle Client libraries required, maintained by Oracle". Os owners são os mesmos do python-oracledb. A lista de recursos inclui SQL/PL/SQL, statement cache, result cache, VECTOR, JSON, LOBs, array DML, pooling, DRCP e criptografia de rede. A cobertura da documentação, porém, é de **45%**, e **a página não menciona REF CURSOR, object types, collections nem implicit results** ([docs.rs oracledb](https://docs.rs/crate/oracledb/latest)). O ext-php-rs está na linha 0.15.x, ainda antes da 1.0 ([CHANGELOG](https://docs.rs/crate/ext-php-rs/0.15.13/source/CHANGELOG.md)).

**Rota 3c: a mesma casca Rust sobre rust-oracle (ODPI-C, thick).** Oferece um "modo thick" no mesmo binário, espelhando a dualidade thin/thick do python-oracledb.

**Rota 4: thin em PHP puro.** É o máximo de plug-and-play (só Composer) e é assíncrono por natureza, via Fibers. Há bastante prior art: python-oracledb thin, node-oracledb thin em JS puro, go-ora (com UDTs, REF CURSOR e até NNE) e ports para Rust e Dart ([go-ora](https://github.com/sijms/go-ora), [dart-oracledb](https://github.com/abibiano/dart-oracledb)). **Não existe nenhum driver thin em PHP**. O esforço estimado é de 12 a 24+ engenheiros-mês, e a velocidade de decodificação em PHP userland nunca foi medida.

**Rota 5: sidecar (Go/Rust) ou ORDS.** Tira o Oracle de dentro do PHP, ao custo de um serviço extra para operar e de um modelo de programação diferente.

### Matriz de decisão

As notas 1-5 abaixo são julgamentos a partir das evidências. Os pesos refletem os objetivos do pedido e são uma escolha deste relatório: cobertura PL/SQL 25%, desempenho 20%, plug-and-play 20%, esforço 15%, risco 10%, recursos modernos 5% e assincronia 5%.

| Opção | PL/SQL (25%) | Desempenho (20%) | Plug-and-play (20%) | Esforço/tempo (15%) | Risco (10%) | Modernos (5%) | Async (5%) | **Total** |
|---|---|---|---|---|---|---|---|---|
| 1. ext-oci8 + userland | 3 | 5 | 2 | 5 (1–3 meses) | 3 | 2 | 1 | **3,35** |
| 2a. FFI → ODPI-C | 5 | 2,5 | 2,5 | 4 (2–4 meses) | 3 | 5 | 1 | **3,45** |
| 3a. Extensão C → ODPI-C | 5 | 5 | 3 | 2 (6–12 meses) | 4 | 5 | 2 | **3,90** |
| 3b. Rust → crate `oracledb` thin | **3,5 (não verificado)** | 4,5 | **5** | 3 (3–6 meses) | 3 | 3,5 | 3 | **3,85** |
| 3c. Rust → rust-oracle (ODPI-C) | 5 | 5 | 3 | 3 | 3 | 4 | 2 | **3,90** |
| 4. Thin em PHP puro | 3 | 2,5 | 5 | 1 (12–24+ meses) | 2 | 3 | 5 | **3,00** |
| 5a. Sidecar Go/Rust | 4,5 | 4 | 3 | 3 | 3 | 3 | 4 | **3,63** |
| 5b. ORDS (HTTP) | 2 | 2 | 4 | 4 | 3 | 3 | 4 | **2,95** |

O dado mais importante da matriz é a **sensibilidade da rota 3b**. As três rotas nativas empatam tecnicamente, entre 3,85 e 3,90. Se o spike confirmar REF CURSOR, objetos e implicit results no crate thin, a nota PL/SQL da 3b sobe para 5 e o total vai a **≈4,23**, a melhor opção com folga. Se o spike falhar (nota 2), a 3b cai para **≈3,48** e a extensão sobre ODPI-C vence. A arquitetura, portanto, não deve ser decidida no papel, e sim por um teste com critérios objetivos.

A Rota 3c tem ainda uma vantagem estratégica. Ela reaproveita a mesma casca ext-php-rs da 3b, de modo que **uma única extensão Rust pode ter um motor thin padrão e um motor thick opcional**, exatamente o modelo do python-oracledb, cujos dois modos suportam REF CURSOR, objetos, implicit results e array DML ([Appendix A](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_a.html)).

### Exigir extensão é o trade-off certo, desde que ela venha pré-compilada

"Plug and play" tem degraus concretos:

| Degrau | O que o usuário faz | Rotas |
|---|---|---|
| A | Só `composer require` | Thin em PHP puro, ORDS |
| B | `pie install` de um binário pré-compilado, sem Instant Client | Rust thin |
| C | `pie install` + Instant Client no runtime | Extensão sobre ODPI-C, rust-oracle |
| D | Compilar + Instant Client SDK + runtime | ext-oci8 hoje |

Fora das rotas thin, todas exigem o Instant Client. Ele pode ser redistribuído sob a licença OTN ([Instant Client FAQ](https://www.oracle.com/database/technologies/faq-instant-client.html)), mas é proprietário. Por isso, **baixar o Instant Client automaticamente via plugin Composer é juridicamente cinzento**. O canal seguro são as imagens oficiais.

O degrau A (PHP puro) seria o ideal, mas custa de 12 a 24+ meses e tem desempenho de fetch em massa não comprovado. O degrau B entrega quase a mesma experiência. O PECL está oficialmente deprecated em favor do PIE ([PECL oci8](https://pecl.php.net/package/oci8)), o PIE está na 1.5.5 com a 1.6 em RC e já distribui a si mesmo como executável static-php-cli ([php/pie releases](https://github.com/php/pie/releases)), e existe uma GitHub Action oficial, a `pie-ext-binary-builder`, que anexa `.so` a releases ([pie-ext-binary-builder](https://gh.r-mj.com/php/pie-ext-binary-builder)). O ext-php-rs já tem extensões em produção no Packagist em 2026 ([bsn4/grpc](https://packagist.org/packages/bsn4/grpc), [flow-php-ext](https://packagist.org/packages/flow-php/flow-php-ext)).

Daí a recomendação. **Exigir uma extensão é aceitável se o projeto pagar o custo da matriz de binários**: PHP 8.4–8.6 × NTS/ZTS × linux-glibc/musl x64/arm64 × macOS × Windows. Além disso, o pacote Composer deve **cair para o backend ext-oci8 quando a extensão nova não estiver presente**, para nunca deixar o usuário sem saída.

O thin também é o que destrava assincronia, como mostra o python-oracledb, que ganhou asyncio no 2.0 graças ao modo thin ([i-programmer](https://i-programmer.info/news/216-python/16901-python-driver-for-oracle-database-goes-asynchronous.html)). O preço conhecido do thin no python é a ausência de Native Network Encryption, com TLS como alternativa, e de recursos ligados ao `sqlnet.ora` ([Appendix B](https://python-oracledb.readthedocs.io/en/latest/user_guide/appendix_b.html)).

### Onde o desempenho é ganho ou perdido

Os ganhos de desempenho aparecem em pontos concretos:

- **Paginação.** Trocar o ROWNUM por `OFFSET/FETCH` com versão detectada resolve um caso de 23 s → 0,8 s ([LO #870](https://github.com/yajra/laravel-oci8/issues/870)).
- **LOBs.** Tratar LOB como stream elimina o eager load que custou 5 s para 200 linhas ([LO #700](https://github.com/yajra/laravel-oci8/issues/700)).
- **Array DML.** Substitui o `UNION ALL FROM dual` e o `FORALL` manual.
- **Collection em `TABLE()`.** Substitui milhares de binds escalares que matam o compartilhamento de cursor.
- **Statement cache.** Ausente no PDO_OCI, ele também é pré-requisito do client result cache. Isto é uma inferência a partir do requisito documentado pela Oracle, não um teste.

Do lado nativo, a regra é converter cada lote de linhas em arrays PHP dentro do código nativo (`fetchBatch`) e nunca expor callbacks por célula. Nenhuma medição de overhead do ext-php-rs contra C puro foi encontrada, então esse número entra como métrica do spike.

## No Laravel, uma conexão nativa substitui o PDO falso sem perder Telescope

### Os pontos de acoplamento ao PDO no Laravel 13

O Laravel não exige PDO, mas presume PDO em todo lugar:

- `select`, `cursor` e `selectResultSets` chamam `getPdoForSelect()->prepare()`;
- `ManagesTransactions` chama `getPdo()->commit()`;
- `Processor::processInsertGetId` usa `lastInsertId()`;
- `prepared()` tem type-hint para `PDOStatement`;
- `QueryException` só copia `errorInfo` quando a exceção anterior é uma `PDOException`.

Fontes: [Connection.php 13.x](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php), [Processor.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Processors/Processor.php), [QueryException.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/QueryException.php).

Há precedente de driver sem PDO. O laravel-mongodb sobrescreve o construtor e nunca seta PDO ([MongoDB Connection.php](https://github.com/mongodb/laravel-mongodb/blob/master/src/Connection.php)). O detalhe que garante as integrações é que **tudo o que passa por `run()` dispara `QueryExecuted`**, e esse é o evento que Telescope, Pulse e `DB::listen` consomem ([Connection.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)).

A recomendação é uma `OracleConnection` nativa, com uma interface interna `PdoLike`. A registração deve ser híbrida: `db.connector.oracle` mais `resolverFor`, porque só o caminho do connector preserva read/write split, `sticky`, listas de hosts e conexão lazy ([ConnectionFactory.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connectors/ConnectionFactory.php)). O yajra perde tudo isso porque abre a conexão dentro do resolver.

```php
final class OracleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.oracle', OracleConnector::class);
        Connection::resolverFor('oracle', static fn ($conn, string $db, string $prefix, array $cfg)
            => new OracleConnection($conn, $db, $prefix, $cfg));
    }
}

final class OracleConnection extends Connection
{
    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): \Generator
    {
        $stmt = $this->run($query, $bindings, function (string $q, array $b) use ($useReadPdo) {
            if ($this->pretending()) { return null; }
            return $this->native($useReadPdo)->query($q, $this->typedBindings($b)); // Cursor lazy
        });
        if ($stmt === null) { return; }
        foreach ($stmt as $row) { yield (object) $row; }
    }

    public function procedure(string $name): ProcedureCall   // passa por run(): logado, listenable, pretend-aware
    {
        return new ProcedureCall($this, $name);
    }

    public function supportsSchemaTransactions(): bool { return false; } // DDL Oracle faz auto-commit

    protected function getDefaultQueryGrammar(): OracleQueryGrammar { return new OracleQueryGrammar($this); }
    protected function getDefaultPostProcessor(): OracleProcessor   { return new OracleProcessor; }
    // + statement/affectingStatement/unprepared/bindValues/getServerVersion,
    //   commit/rollBack/savepoints de ManagesTransactions, isUniqueConstraintError (ORA-00001)
}
```

As gramáticas devem usar `OFFSET n ROWS FETCH NEXT m ROWS ONLY`, inspirando-se no `SqlServerGrammar` ([SqlServerGrammar.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Grammars/SqlServerGrammar.php)). O resto da lista:

- `MERGE` para `upsert`;
- `FOR UPDATE [SKIP LOCKED|NOWAIT]`;
- `JSON_EXISTS` para `whereJsonContains`;
- `DBMS_RANDOM.VALUE`;
- `compileVectorDistanceExpression` → `VECTOR_DISTANCE`, aproveitando o hook novo do Laravel 13 ([Grammar.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Query/Grammars/Grammar.php));
- `INSERT … RETURNING "ID" INTO :id` com OUT bind, no lugar de `lastInsertId`;
- uma política de case de identificadores **configurável** (preservar, ou fazer fold para maiúsculas), sem o fold forçado que gera a família de bugs de case.

`lazy()` e `chunk()` passam a funcionar sozinhos quando `compileLimit` e `compileOffset` estão corretos. `cursor()` precisa de streaming real com prefetch ([BuildsQueries.php](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Concerns/BuildsQueries.php)).

### PL/SQL idiomático no Laravel

```php
// Procedure com OUT e REF CURSOR → LazyCollection (yajra materializa tudo com fetchAll)
$r = DB::connection('oracle')->procedure('hr.pkg_emp.raise_salary')
    ->in('p_emp_id', 101)
    ->out('p_new_salary', Type::Number)
    ->outCursor('p_rows')
    ->call();

$r->get('p_new_salary');
$r->lazy('p_rows')                                   // LazyCollection<stdClass>, streaming
    ->map(fn (object $row) => EmployeeData::from($row))
    ->each(fn (EmployeeData $e) => $exporter->write($e));

// Implicit results ↔ selectResultSets() do Laravel 13
[$cabecalho, $itens] = DB::connection('oracle')
    ->selectResultSets('BEGIN pkg_rel.pedido(?); END;', [4711]);

// Pipelined function como fonte do query builder
DB::connection('oracle')->query()
    ->fromTableFunction('pkg_vendas.pipe_sales', [2026, 'Q3'], as: 's')
    ->where('s.amount', '>', 100)
    ->orderBy('s.amount', 'desc')
    ->lazy();

// whereIn sem ORA-01795 e sem 50 mil binds: um único bind de collection
User::query()->whereInCollection('id', $ids, type: 'SYS.ODCINUMBERLIST')->get();
// → WHERE "ID" IN (SELECT column_value FROM TABLE(:ids))

// Model Eloquent somente leitura sobre table function
final class SalesReport extends Model
{
    use HasTableFunctionSource;                       // save() lança ReadOnlyModel
    protected string $tableFunction = 'pkg_vendas.pipe_sales';
    public $incrementing = false;
}
SalesReport::fromFunction(2026, 'Q3')->where('region', 'Sul')->paginate(25);

// Object types → DTOs registrados no TypeMap via config/oracle.php
// 'type_map' => [App\Oracle\Address::class, App\Oracle\OrderLine::class],
```

O `selectResultSets()` do Laravel 13 já traz o conceito de múltiplos result sets por statement, e ele se encaixa diretamente em `DBMS_SQL.RETURN_RESULT` ([Connection.php L462](https://github.com/laravel/framework/blob/13.x/src/Illuminate/Database/Connection.php)). Um comando `php artisan oracle:describe pkg.proc` pode ler `ALL_ARGUMENTS` e gerar stubs tipados e DTOs, o que dá à análise estática a mesma precisão que o PL/SQL tem no banco. Um adapter Doctrine DBAL 4 (`Driver extends AbstractOracleDriver`, reaproveitando `OraclePlatform`) leva o core ao Symfony sem custo para o Laravel, que não depende mais de DBAL ([dbal 4.6.0](https://github.com/doctrine/dbal/tree/4.6.0/src)). No Octane, o reconnector precisa reaplicar de forma idempotente o estado de sessão (module, client identifier e NLS residual). Isso é inferência, não verificado em fonte primária.

### Ferramental: PHP ^8.4, Pest 5, PHPStan nível 10, Rector 2.8

A decisão de versão vem primeiro. O Laravel 13 aceita PHP 8.3, mas o **Pest 5 exige PHP 8.4** e roda sobre PHPUnit 13 ([anúncio Pest 5](https://pestphp.com/docs/pest5-now-available)), e o skeleton da Spatie já exige 8.4 ([skeleton](https://github.com/spatie/package-skeleton-laravel/blob/main/composer.json)). Um projeto novo deve fixar **PHP ^8.4** e entrar no PHP 8.6, lançado em 19/11/2026 ([php.net](https://www.php.net/releases/index.php?json&max=3)), assim que o setup-php suportar.

A stack de referência inclui:

- Pest 5.3.7, com arch presets, `--type-coverage --min=100`, `--mutate` e sharding;
- PHPStan 2.3.7 no **nível 10**, que reporta `mixed` implícito ([rule-levels](https://github.com/phpstan/phpstan/blob/2.3.x/website/src/user-guide/rule-levels.md));
- Larastan 3.15, Rector 2.8.5 com rector-laravel 2.7.3, Pint 1.32.3, Infection 0.35.12, PHPBench 1.7.0 e Testbench 11.3.0 ([releases](https://github.com/pestphp/pest/releases), [phpstan](https://github.com/phpstan/phpstan/releases), [rector](https://github.com/rectorphp/rector/releases), [testbench](https://github.com/orchestral/testbench/releases)).

```php
// tests/ArchTest.php — mantém o core livre de framework
arch()->preset()->php();
arch()->preset()->security();
arch('core não conhece o Laravel')
    ->expect('OpenRajska\Oracle\Core')->not->toUse('Illuminate');
arch('tipos estritos em todo lugar')
    ->expect('OpenRajska\Oracle')->toUseStrictTypes();
arch('value objects imutáveis')
    ->expect('OpenRajska\Oracle\Core\Types')->toBeReadonly()->toBeFinal();
```

```php
// rector.php
return RectorConfig::configure()
    ->withPaths([__DIR__.'/packages'])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    ->withSets([LaravelSetList::LARAVEL_130]); // só no pacote Laravel
```

```yaml
# .github/workflows/tests.yml (trecho)
strategy:
  matrix:
    php: ['8.4', '8.5', '8.6']
    laravel: ['12.*', '13.*']
    oracle: ['gvenzl/oracle-free:23.26.3-slim-faststart']
services:
  oracle:
    image: ${{ matrix.oracle }}
    env: { ORACLE_PASSWORD: oracle, APP_USER: laravel, APP_USER_PASSWORD: secret }
    ports: ['1521:1521']
    options: --health-cmd healthcheck.sh --health-interval 10s --health-retries 30
steps:
  - uses: shivammathur/setup-php@v2
    with: { php-version: '${{ matrix.php }}', extensions: oci8, coverage: pcov }
  - run: vendor/bin/pest --parallel --ci
```

O `gvenzl/oracle-free` com tag `23.26.3*` é a linha suportada, e as variantes `-faststart` são as recomendadas para CI ([oci-oracle-free](https://github.com/gvenzl/oci-oracle-free/blob/main/README.md)). O setup-php instala `oci8` em todos os sistemas suportados ([setup-php](https://github.com/shivammathur/setup-php/blob/main/README.md)). Para desenvolvimento local, o testcontainers-php 1.2.1 sobe o banco com `GenericContainer` ([testcontainers-php](https://github.com/testcontainers/testcontainers-php/blob/main/README.md)). Mutation testing e benchmarks PHPBench com baseline devem rodar em jobs noturnos.

O monorepo segue o padrão do próprio Laravel, que faz split com `splitsh-lite` ([split.sh](https://github.com/laravel/framework/blob/13.x/bin/split.sh)). O merge de dependências fica com `symplify/monorepo-builder` 12.7.2, e o split em mirrors somente leitura com `danharrin/monorepo-split-github-action` v2.4.5 ([monorepo-builder](https://github.com/symplify/monorepo-builder/releases), [split action](https://github.com/danharrin/monorepo-split-github-action/releases)). O layout proposto é `packages/oracle-core`, `oracle-dbal`, `oracle-laravel` e `oracle-phpstan`. A extensão nativa (Rust ou C) fica em um repositório separado, porque tem cadência e empacotamento PIE próprios.

## Um roadmap em quatro fases com portões de decisão mensuráveis

| Fase | Janela (estimativa) | Entregas | Portão de saída |
|---|---|---|---|
| **0 — API primeiro** | 0–2 meses | Contrato `Driver`; `oracle-core` sobre **backend ext-oci8** (call builder nomeado, N cursores lazy, implicit results, arrays associativos, collections escalares, binds tipados, versão detectada); `oracle-laravel` com Connection nativa, OFFSET/FETCH, `RETURNING INTO`, `whereInCollection`, `fromTableFunction`; harness PHPBench | Suíte Pest verde em 26ai e 19c; paridade funcional com o yajra nos testes de integração; benchmarks de referência gravados |
| **1 — Spikes paralelos** | 1–4 meses | (a) **Spike do crate Rust `oracledb` thin via ext-php-rs**; (b) protótipo FFI→ODPI-C para validar o design de objetos/AQ/VECTOR e medir o custo por chamada; (c) micro-benchmark de decode NUMBER/VARCHAR2 em PHP puro, com JIT ligado e desligado | Checklist do spike (abaixo) e números de throughput |
| **2 — Núcleo padrão** | 4–12 meses | Se (a) passar: **extensão Rust thin como backend padrão**, binários pré-compilados via PIE (PHP 8.4–8.6 × NTS/ZTS × glibc/musl × x64/arm64 × macOS × Windows) e receitas static-php-cli/FrankenPHP. Se falhar: extensão sobre ODPI-C (C ou rust-oracle na mesma casca Rust). Nos dois casos: TypeMap nativo de objetos, executeMany, JSON/VECTOR/BOOLEAN nativos; o backend oci8 continua como fallback | Throughput ≥ backend oci8 no fetch de 100 mil linhas; objetos e records funcionando sem a ponte JSON |
| **3 — Opcional/comunidade** | 12+ meses | Backend thin em PHP puro (port de node/python-oracledb thin, validado contra as suítes deles) para Fibers/AMPHP e hosts sem extensões; modo sidecar reaproveitando o núcleo Rust; adapter DBAL 4 | Demanda comprovada; decode em PHP dentro de um fator aceitável do nativo |

Como o crate tem apenas 45% de cobertura de documentação e uma lista de recursos que omite PL/SQL avançado ([docs.rs oracledb](https://docs.rs/crate/oracledb/latest)), **o spike da Fase 1 precisa provar com testes reais contra o 26ai e o 19c**:

- REF CURSOR como OUT e como retorno de função;
- cursores `CURSOR()` aninhados;
- implicit results com formatos diferentes;
- object types, collections de objetos e records `%ROWTYPE`;
- arrays associativos esparsos;
- PL/SQL BOOLEAN e SQL BOOLEAN;
- LOB em streaming;
- TLS/wallet;
- o throughput da conversão para zval.

A conta de dono do projeto também entra no portão. O crate está em beta, o ext-php-rs ainda é pre-1.0, e as duas APIs vão mudar. Por isso o core PHP nunca pode vazar tipos do backend. As estimativas de esforço desta tabela são julgamentos, e o único jeito de calibrá-las é justamente a Fase 1.

## Conclusão

O problema do Oracle no PHP nunca foi a falta de um driver. O problema é que a abstração escolhida, o PDO, não consegue representar o PL/SQL, e toda a dívida do yajra vem de tentar enfiar REF CURSOR, collections e LOBs em `PDO::PARAM_*`. A mudança de perspectiva que este relatório propõe é tratar o **contrato PHP tipado como o produto** e o núcleo nativo como um detalhe trocável. Com isso, o ecossistema pode entregar valor em semanas sobre o oci8 envelhecido e migrar depois para o que vencer o spike, sem que nenhum usuário reescreva código. A segunda mudança é reconhecer que o investimento da própria Oracle, primeiro em thin drivers (Python, Node e agora Rust) e depois no ODPI-C, sem nada no oci8, indica para onde o PHP deve ir. **Quem ancorar o ecossistema no crate Rust thin, se ele passar no spike, herda de graça o trabalho de protocolo do time que mantém o python-oracledb.** Ancorar no ODPI-C é a aposta conservadora, que herda a cobertura completa de PL/SQL ao preço de manter o Instant Client.

Para quem decide hoje, sobra uma implicação prática. Os ganhos mais visíveis para usuários Laravel (paginação de 23 s para 0,8 s, `IN` sem limite de 1000, OUT e múltiplos cursores, LOB em streaming) **não dependem do núcleo novo**: dá para colhê-los já na Fase 0, sobre o próprio ext-oci8, só com um design correto. Já objetos, records, executemany e JSON/VECTOR nativos são o que justifica escrever código nativo. Essa ordem, com o ganho de design primeiro e o investimento em código nativo depois de medido, reduz o risco do projeto inteiro.
