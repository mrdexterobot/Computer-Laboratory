-- COMLAB Workflow Integration: Database Updates
-- Track PMED verification and approval status

-- Add PMED status and CRAD reference to requests table
ALTER TABLE comlab.requests 
ADD COLUMN IF NOT EXISTS pmed_status text DEFAULT 'Pending' CHECK (pmed_status IN ('Pending', 'Verified', 'Approved', 'Rejected')),
ADD COLUMN IF NOT EXISTS crad_ref text;

-- Ensure audit_logs is ready (already exists, but good to check)
-- The audit_logs table is already used by the system.

-- Add a comment for clarity
COMMENT ON COLUMN comlab.requests.pmed_status IS 'Status of PMED verification and approval (Pending, Verified, Approved, Rejected)';
COMMENT ON COLUMN comlab.requests.crad_ref IS 'Reference ID for requests originating from CRAD Unit';
