-- Drops every fixture object (name starts with FX_) owned by the current user.
-- Plain PL/SQL instead of DROP ... IF EXISTS, so it also runs on 19c.
declare
  procedure drop_object(p_type in varchar2, p_name in varchar2) is
  begin
    execute immediate 'drop ' || p_type || ' "' || p_name || '"'
      || case p_type
           when 'TABLE' then ' cascade constraints purge'
           when 'TYPE' then ' force'
         end;
  exception
    -- Already gone (e.g. a nested table storage table dropped with its parent).
    when others then
      if sqlcode not in (-942, -4043) then
        raise;
      end if;
  end drop_object;
begin
  for o in (
    select object_type, object_name
      from user_objects
     where object_name like 'FX\_%' escape '\'
       and object_type in ('TABLE', 'VIEW', 'SEQUENCE', 'SYNONYM',
                           'PACKAGE', 'PROCEDURE', 'FUNCTION', 'TYPE')
     order by case object_type
                when 'TABLE' then 1
                when 'VIEW' then 2
                when 'SEQUENCE' then 3
                when 'SYNONYM' then 4
                when 'PACKAGE' then 5
                when 'PROCEDURE' then 6
                when 'FUNCTION' then 7
                when 'TYPE' then 8
              end,
              object_name
  ) loop
    drop_object(o.object_type, o.object_name);
  end loop;
end;
/
