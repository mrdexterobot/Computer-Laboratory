-- Align PMED workflow statuses with runtime API transitions.
-- Required for:
-- Awaiting Forward -> Pending -> Verified -> Approved -> COMLAB Receive (status=Completed)

DO $$
DECLARE
    pmed_constraint_name text;
BEGIN
    SELECT con.conname
    INTO pmed_constraint_name
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
    JOIN pg_attribute att ON att.attrelid = rel.oid AND att.attnum = ANY (con.conkey)
    WHERE nsp.nspname = 'comlab'
      AND rel.relname = 'requests'
      AND con.contype = 'c'
      AND att.attname = 'pmed_status'
    LIMIT 1;

    IF pmed_constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE comlab.requests DROP CONSTRAINT %I', pmed_constraint_name);
    END IF;
END $$;

ALTER TABLE comlab.requests
ADD CONSTRAINT requests_pmed_status_check
CHECK (pmed_status IN ('Awaiting Forward', 'Pending', 'Verified', 'Approved', 'Rejected'));

COMMENT ON COLUMN comlab.requests.pmed_status IS
'PMED processing state: Awaiting Forward, Pending, Verified, Approved, Rejected';
