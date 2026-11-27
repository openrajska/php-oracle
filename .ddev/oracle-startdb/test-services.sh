#!/usr/bin/env bash
# A cada start do container, o que as suítes precisam do banco; o CI faz o
# mesmo em .github/workflows/ci.yml.
# - PROCESSES 400: a imagem permite 200, uns 90 dela mesma, e a suíte do Core
#   segura uns 60 Sessions no fim (um por Pool); uma rajada de logons em cima
#   fazia o listener recusar novos (ORA-12516). O novo limite reinicia a
#   instância, então só roda quando o limite ainda é menor.
# - O DRCP e os listeners TCPS de fixtures/tls, dos testes de DRCP e TLS.
set -euo pipefail

processes=$(sqlplus -s -l / as sysdba <<'SQL'
set heading off feedback off
select value from v$parameter where name = 'processes';
SQL
)
if [ "$(echo $processes)" -lt 400 ]; then
    sqlplus -s -l / as sysdba <<'SQL'
whenever sqlerror exit failure
alter system set processes = 400 scope = spfile;
shutdown immediate
startup
alter system register;
SQL
fi

sqlplus -s -l / as sysdba <<'SQL'
whenever sqlerror exit failure
exec dbms_connection_pool.start_pool()
SQL
bash /fixtures/tls/listeners.sh
