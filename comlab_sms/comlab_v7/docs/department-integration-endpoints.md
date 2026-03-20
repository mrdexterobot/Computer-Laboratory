# COMLAB Department Integration Endpoints

COMLAB now exposes a small department-facing integration API on top of the shared Supabase `comlab` schema.

## Base endpoints

- `GET /api/integrations/departments/map.php`
- `GET /api/integrations/departments/records.php`
- `POST /api/integrations/departments/records.php`
- `GET /api/integrations/departments/report.php?department=PMED`
- `POST /api/integrations/departments/report.php`

## Access rules

- `GET map.php` is public read-only so connected departments can inspect the flow registry.
- `GET records.php`, `POST records.php`, `GET report.php`, and `POST report.php` require either:
  - a COMLAB administrator session, or
  - `X-Integration-Token: <DEPARTMENT_INTEGRATION_SHARED_TOKEN>`

## Route map

`GET /api/integrations/departments/map.php`

Returns:

- COMLAB department profile
- endpoint URLs
- inbound and outbound route definitions
- connected department directory

Optional query params:

- `department=PMED`

## Records API

`GET /api/integrations/departments/records.php`

Optional query params:

- `direction=all|incoming|outgoing`
- `department=PMED`
- `record_type=laboratory_usage_reports`
- `status=sent`
- `subject_ref=RPT-20260320`
- `document_id=<uuid>`
- `source_reference=comlab-report-20260320`
- `limit=50`

`POST /api/integrations/departments/records.php`

Supported `action` values:

- `dispatch_record`
- `receive_record`
- `acknowledge_record`
- `archive_record`
- `update_status`

### Dispatch example

```json
{
  "action": "dispatch_record",
  "sender_department_code": "COMLAB",
  "receiver_department_code": "PMED",
  "record_type_code": "laboratory_usage_reports",
  "subject_type": "system",
  "subject_ref": "RPT-20260320-001",
  "title": "COMLAB laboratory usage snapshot",
  "source_system": "COMLAB",
  "source_reference": "usage-20260320",
  "payload": {
    "period": "2026-03-20",
    "notes": "Generated from COMLAB dashboard metrics."
  }
}
```

### Acknowledge example

```json
{
  "action": "acknowledge_record",
  "document_id": "00000000-0000-0000-0000-000000000000",
  "payload": {
    "acknowledged_by": "PMED Integration",
    "remarks": "Received and logged."
  }
}
```

## Report API

`GET /api/integrations/departments/report.php?department=PMED`

Returns a logical integration package with:

- COMLAB to department route summary
- current laboratory usage snapshot
- recent document traffic
- dispatch readiness

`POST /api/integrations/departments/report.php`

Supported action:

- `dispatch_report`

### Dispatch report example

```json
{
  "action": "dispatch_report",
  "target_key": "PMED",
  "report_type": "laboratory_usage_reports",
  "title": "COMLAB daily usage report",
  "payload": {
    "coverage_period": "2026-03-20",
    "requested_by": "PMED Dashboard"
  }
}
```

## Current COMLAB integration flows

Primary operating scope in the COMLAB Integration Hub:

Inbound to COMLAB:

- `student_account_information` from Registrar
- `class_schedule_feed` from Registrar
- `subject_lab_assignments` from Registrar

Outbound from COMLAB:

- `laboratory_attendance_records` to Registrar
- `laboratory_usage_reports` to PMED
- `equipment_log_reports` to PMED
- `laboratory_activity_reports` to CRAD Management

Operational data stored by COMLAB:

- `lab_usage_logs`
- `schedule_attendance`
- `device_maintenance_logs`

Additional background integrations remain available in the shared schema, including the existing `faculty_schedule_assignments` feed from HR that powers the HR-based scheduling sync screen.

## Supabase setup order

1. Configure [`.env.example`](C:/xampp/htdocs/bpm%20commision/Computer-Laboratory/comlab_sms/.env.example) values in `comlab_sms/.env`.
2. Run [schema.sql](C:/xampp/htdocs/bpm%20commision/Computer-Laboratory/comlab_sms/comlab_v7/supabase/schema.sql) in Supabase SQL editor.
3. Run [seed.sql](C:/xampp/htdocs/bpm%20commision/Computer-Laboratory/comlab_sms/comlab_v7/supabase/seed.sql).

Default seeded logins:

- `admin` / `Admin@123`
- `msantos` / `Faculty@123`
- `jreyes` / `Faculty@123`
- `acruz` / `Faculty@123`
