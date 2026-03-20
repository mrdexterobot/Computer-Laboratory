import { dispatchDepartmentFlow, getFlowEventStatus, type FlowEventStatus } from './departmentIntegration';

export type LabFeeAssessment = {
  student_id: string;
  student_name: string;
  amount_due: number;
  lab_name?: string;
  session_date?: string;
  fee_type?: 'usage_fee' | 'reservation_fee' | 'penalty' | 'other';
  description?: string;
  reference_no?: string;
  due_date?: string;
};

export type LabFeeAssessmentResult = FlowEventStatus & {
  assessment?: LabFeeAssessment;
};

export async function dispatchLabFeeAssessmentToCashier(
  assessment: LabFeeAssessment,
  sourceRecordId?: string
): Promise<LabFeeAssessmentResult> {
  const payload: Record<string, unknown> = {
    student_id: assessment.student_id,
    student_name: assessment.student_name,
    amount_due: assessment.amount_due,
    lab_name: assessment.lab_name,
    session_date: assessment.session_date,
    fee_type: assessment.fee_type || 'usage_fee',
    description: assessment.description,
    reference_no: assessment.reference_no || `COMLAB-${Date.now()}`,
    due_date: assessment.due_date
  };

  const result = await dispatchDepartmentFlow(
    'comlab',
    'cashier',
    'lab_fee_assessment',
    payload,
    sourceRecordId
  );

  if (result.ok && result.correlation_id) {
    const status = await getFlowEventStatus(undefined, result.correlation_id);
    return {
      ...status,
      assessment
    };
  }

  return {
    ok: false,
    last_error: result.message || 'Failed to dispatch lab fee assessment'
  } as LabFeeAssessmentResult;
}

export async function createLabFeeBilling(
  studentId: string,
  studentName: string,
  amount: number,
  labName: string,
  sessionDate: string
): Promise<LabFeeAssessmentResult> {
  return dispatchLabFeeAssessmentToCashier({
    student_id: studentId,
    student_name: studentName,
    amount_due: amount,
    lab_name: labName,
    session_date: sessionDate,
    fee_type: 'usage_fee'
  });
}
