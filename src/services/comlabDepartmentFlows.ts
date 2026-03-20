import { dispatchDepartmentFlow, getFlowEventStatus, type FlowEventStatus } from './departmentIntegration';

export type LaboratoryUsageReport = {
  report_period: string;
  usage_summary: string;
  lab_reference?: string;
  attendance_summary?: string;
};

export type LaboratoryUsageReportResult = FlowEventStatus & {
  payload?: LaboratoryUsageReport;
};

export type LaboratoryAttendanceRecord = {
  coverage_period: string;
  attendance_summary: string;
  class_reference?: string;
  faculty_reference?: string;
};

export type LaboratoryAttendanceRecordResult = FlowEventStatus & {
  payload?: LaboratoryAttendanceRecord;
};

export type LaboratoryActivityReport = {
  activity_period: string;
  activity_summary: string;
  lab_reference?: string;
  program_reference?: string;
};

export type LaboratoryActivityReportResult = FlowEventStatus & {
  payload?: LaboratoryActivityReport;
};

type ComlabFlowPayload =
  | LaboratoryUsageReport
  | LaboratoryAttendanceRecord
  | LaboratoryActivityReport;

async function dispatchComlabFlow<T extends ComlabFlowPayload>(
  targetDepartment: 'pmed' | 'registrar' | 'crad',
  recordType: string,
  payload: T,
  sourceRecordId: string | undefined,
  failureMessage: string
): Promise<FlowEventStatus & { payload?: T }> {
  const result = await dispatchDepartmentFlow(
    'comlab',
    targetDepartment,
    recordType,
    payload,
    sourceRecordId
  );

  if (result.ok && result.correlation_id) {
    const status = await getFlowEventStatus(undefined, result.correlation_id);
    return {
      ...status,
      payload
    };
  }

  return {
    ok: false,
    last_error: result.message || failureMessage,
    payload
  };
}

export async function dispatchLaboratoryUsageReportToPmed(
  report: LaboratoryUsageReport,
  sourceRecordId?: string
): Promise<LaboratoryUsageReportResult> {
  return dispatchComlabFlow(
    'pmed',
    'laboratory_usage_reports',
    {
      report_period: report.report_period,
      usage_summary: report.usage_summary,
      lab_reference: report.lab_reference,
      attendance_summary: report.attendance_summary
    },
    sourceRecordId,
    'Failed to dispatch laboratory usage report to PMED.'
  );
}

export async function dispatchLaboratoryAttendanceToRegistrar(
  record: LaboratoryAttendanceRecord,
  sourceRecordId?: string
): Promise<LaboratoryAttendanceRecordResult> {
  return dispatchComlabFlow(
    'registrar',
    'laboratory_attendance_records',
    {
      coverage_period: record.coverage_period,
      attendance_summary: record.attendance_summary,
      class_reference: record.class_reference,
      faculty_reference: record.faculty_reference
    },
    sourceRecordId,
    'Failed to dispatch laboratory attendance records to Registrar.'
  );
}

export async function dispatchLaboratoryActivityReportToCrad(
  report: LaboratoryActivityReport,
  sourceRecordId?: string
): Promise<LaboratoryActivityReportResult> {
  return dispatchComlabFlow(
    'crad',
    'laboratory_activity_reports',
    {
      activity_period: report.activity_period,
      activity_summary: report.activity_summary,
      lab_reference: report.lab_reference,
      program_reference: report.program_reference
    },
    sourceRecordId,
    'Failed to dispatch laboratory activity report to CRAD Management.'
  );
}
