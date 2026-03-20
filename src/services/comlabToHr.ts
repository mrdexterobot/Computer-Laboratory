import { dispatchDepartmentFlow, getFlowEventStatus, type FlowEventStatus } from './departmentIntegration';

export type FacilityAccessReport = {
  report_period: string;
  employee_count: number;
  access_count: number;
  lab_name?: string;
  usage_summary?: string;
  anomalies?: string;
  report_date?: string;
  generated_by?: string;
};

export type FacilityAccessReportResult = FlowEventStatus & {
  report?: FacilityAccessReport;
};

export async function dispatchFacilityAccessReportToHR(
  report: FacilityAccessReport,
  sourceRecordId?: string
): Promise<FacilityAccessReportResult> {
  const payload: Record<string, unknown> = {
    report_period: report.report_period,
    employee_count: report.employee_count,
    access_count: report.access_count,
    lab_name: report.lab_name,
    usage_summary: report.usage_summary,
    anomalies: report.anomalies,
    report_date: report.report_date || new Date().toISOString(),
    generated_by: report.generated_by
  };

  const result = await dispatchDepartmentFlow(
    'comlab',
    'hr',
    'facility_access_report',
    payload,
    sourceRecordId
  );

  if (result.ok && result.correlation_id) {
    const status = await getFlowEventStatus(undefined, result.correlation_id);
    return {
      ...status,
      report
    };
  }

  return {
    ok: false,
    last_error: result.message || 'Failed to dispatch facility access report'
  } as FacilityAccessReportResult;
}

export async function sendMonthlyAccessReport(
  reportPeriod: string,
  employeeCount: number,
  accessCount: number,
  labName?: string,
  usageSummary?: string
): Promise<FacilityAccessReportResult> {
  return dispatchFacilityAccessReportToHR({
    report_period: reportPeriod,
    employee_count: employeeCount,
    access_count: accessCount,
    lab_name: labName,
    usage_summary: usageSummary
  });
}
