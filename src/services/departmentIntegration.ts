import { fetchApiData, invalidateApiCache } from '@/services/apiClient';

export type DispatchResult = {
  ok: boolean;
  event_id?: string;
  correlation_id?: string;
  route_key?: string;
  source_department_key?: string;
  target_department_key?: string;
  event_code?: string;
  status?: string;
  dispatch_endpoint?: string;
  message?: string;
};

export type FlowEventStatus = {
  ok: boolean;
  event_id?: string;
  correlation_id?: string;
  route_key?: string;
  flow_name?: string;
  source_department_key?: string;
  target_department_key?: string;
  event_code?: string;
  status?: string;
  request_payload?: Record<string, unknown>;
  response_payload?: Record<string, unknown>;
  last_error?: string;
  dispatched_at?: string;
  acknowledged_at?: string;
  created_at?: string;
  updated_at?: string;
};

export type IntegrationDepartment = {
  department_key: string;
  department_name: string;
  system_code: string;
  module_directory: string;
  purpose: string;
  default_action_label: string;
  dispatch_rpc_name: string;
  status_rpc_name: string;
  ack_rpc_name: string;
  dispatch_endpoint: string;
  pending_count: number;
  in_progress_count: number;
  failed_count: number;
  completed_count: number;
  route_count: number;
  latest_status: string | null;
  latest_event_code: string | null;
  latest_correlation_id: string | null;
  latest_created_at: string | null;
  routes: Array<{
    route_key: string;
    flow_name: string;
    event_code: string;
    endpoint_path: string;
    priority: number;
    is_required: boolean;
  }>;
};

export type IntegrationRegistry = IntegrationDepartment[];

function trimTrailingSlashes(value: string): string {
  return value.replace(/\/+$/, '');
}

function resolveBackendApiBase(): string {
  const configured =
    import.meta.env.VITE_BACKEND_API_BASE_URL?.trim() ||
    import.meta.env.VITE_API_BASE_URL?.trim() ||
    '';

  return configured ? trimTrailingSlashes(configured) : '';
}

function buildBackendUrl(path: string): string {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const base = resolveBackendApiBase();

  if (!base) {
    return normalizedPath;
  }

  if (base.endsWith('/api') && normalizedPath.startsWith('/api/')) {
    return `${base}${normalizedPath.slice(4)}`;
  }

  return `${base}${normalizedPath}`;
}

async function parseRpcResponse<T>(response: Response, fallbackMessage: string): Promise<T> {
  const text = await response.text();
  const trimmed = text.trim();

  if (!trimmed) {
    if (!response.ok) {
      throw new Error(fallbackMessage);
    }

    return {} as T;
  }

  try {
    const payload = JSON.parse(trimmed) as Record<string, unknown>;
    if (!response.ok) {
      throw new Error(
        String(payload.message || payload.error_description || payload.error || fallbackMessage)
      );
    }

    return payload as T;
  } catch (error) {
    if (!response.ok) {
      throw error instanceof Error ? error : new Error(fallbackMessage);
    }

    return trimmed as T;
  }
}

async function callFallbackEndpoint<T>(
  path: string,
  options: {
    method?: string;
    body?: Record<string, unknown>;
  } = {}
): Promise<T> {
  return await fetchApiData<T>(buildBackendUrl(path), {
    method: options.method,
    body: options.body
  });
}

function nowIso(): string {
  return new Date().toISOString();
}

function makeFlowName(sourceDepartment: string, targetDepartment: string, eventCode: string): string {
  return `${sourceDepartment.toUpperCase()} to ${targetDepartment.toUpperCase()} ${eventCode}`;
}

function makeRouteKey(sourceDepartment: string, targetDepartment: string, eventCode: string): string {
  return `${sourceDepartment}_${targetDepartment}_${eventCode}`.toLowerCase();
}

function normalizeDocumentToStatus(document: Record<string, any>): FlowEventStatus {
  const recordType = (document.record_type || {}) as Record<string, any>;
  const sender = (document.sender_department || {}) as Record<string, any>;
  const receiver = (document.receiver_department || {}) as Record<string, any>;
  const sourceDepartment = String(sender.key || sender.code || '').toLowerCase();
  const targetDepartment = String(receiver.key || receiver.code || '').toLowerCase();
  const eventCode = String(recordType.code || '');
  const payload =
    document.payload && typeof document.payload === 'object' && !Array.isArray(document.payload)
      ? (document.payload as Record<string, unknown>)
      : {};

  return {
    ok: true,
    event_id: String(document.document_id || ''),
    correlation_id: String(document.source_reference || document.subject_ref || document.document_id || ''),
    route_key: makeRouteKey(sourceDepartment, targetDepartment, eventCode),
    flow_name: makeFlowName(sourceDepartment, targetDepartment, eventCode),
    source_department_key: sourceDepartment,
    target_department_key: targetDepartment,
    event_code: eventCode,
    status: String(document.status || ''),
    request_payload: payload,
    response_payload: payload.response && typeof payload.response === 'object'
      ? (payload.response as Record<string, unknown>)
      : undefined,
    last_error: typeof payload.error === 'string' ? payload.error : undefined,
    dispatched_at: document.sent_at || document.created_at,
    acknowledged_at: document.acknowledged_at || undefined,
    created_at: document.created_at || undefined,
    updated_at: document.updated_at || undefined
  };
}

type MapEndpointResponse = {
  connected_departments?: Array<{
    department?: {
      code?: string;
      key?: string;
      name?: string;
    };
    incoming?: Array<{ code?: string; name?: string }>;
    outgoing?: Array<{ code?: string; name?: string }>;
  }>;
};

type RecordsEndpointResponse = {
  records?: Array<Record<string, any>>;
};

export async function dispatchDepartmentFlow(
  sourceDepartment: string,
  targetDepartment: string,
  eventCode: string,
  payload: Record<string, unknown> = {},
  sourceRecordId?: string
): Promise<DispatchResult> {
  const correlationId = sourceRecordId || `${eventCode}-${Date.now()}`;
  const title = `${eventCode.replace(/_/g, ' ')} dispatch`;

  const requestPayload = {
    action: 'dispatch_record',
    sender_department_code: sourceDepartment.toUpperCase(),
    receiver_department_code: targetDepartment.toUpperCase(),
    record_type_code: eventCode,
    subject_type: 'system',
    subject_ref: correlationId,
    title,
    source_system: sourceDepartment.toUpperCase(),
    source_reference: correlationId,
    payload
  };

  try {
    const response = await callFallbackEndpoint<{ document?: Record<string, any> }>(
      '/api/integrations/departments/records.php',
      {
        method: 'POST',
        body: requestPayload
      }
    );

    const document = response.document || {};
    return {
      ok: true,
      event_id: String(document.document_id || ''),
      correlation_id: String(document.source_reference || document.subject_ref || correlationId),
      route_key: makeRouteKey(sourceDepartment, targetDepartment, eventCode),
      source_department_key: sourceDepartment,
      target_department_key: targetDepartment,
      event_code: eventCode,
      status: String(document.status || 'sent'),
      dispatch_endpoint: buildBackendUrl('/api/integrations/departments/records.php')
    };
  } catch (error) {
    return {
      ok: false,
      message: error instanceof Error ? error.message : 'Failed to dispatch department flow.'
    };
  }
}

export async function getFlowEventStatus(eventId?: string, correlationId?: string): Promise<FlowEventStatus> {
  try {
    const params = new URLSearchParams();
    params.set('limit', '1');
    if (eventId) {
      params.set('document_id', eventId);
    }
    if (correlationId) {
      params.set('source_reference', correlationId);
    }

    const response = await fetchApiData<RecordsEndpointResponse>(
      `${buildBackendUrl('/api/integrations/departments/records.php')}?${params.toString()}`
    );
    const document = response.records?.[0];
    if (!document) {
      return {
        ok: false,
        last_error: 'Department flow event not found.'
      };
    }

    return normalizeDocumentToStatus(document);
  } catch (error) {
    return {
      ok: false,
      last_error: error instanceof Error ? error.message : 'Failed to fetch department flow status.'
    };
  }
}

export async function acknowledgeFlowEvent(
  eventId: string,
  status: string = 'acknowledged',
  response: Record<string, unknown> = {},
  error?: string
): Promise<{ ok: boolean; message?: string }> {
  const normalizedStatus = status === 'completed' ? 'acknowledged' : status;
  const mergedPayload = {
    response,
    error: error || null,
    acknowledged_at: nowIso()
  };

  try {
    await callFallbackEndpoint<{ document?: Record<string, any> }>('/api/integrations/departments/records.php', {
      method: 'POST',
      body: {
        action: normalizedStatus === 'acknowledged' ? 'acknowledge_record' : 'update_status',
        document_id: eventId,
        status: normalizedStatus,
        payload: mergedPayload
      }
    });

    return {
      ok: true
    };
  } catch (caughtError) {
    return {
      ok: false,
      message: caughtError instanceof Error ? caughtError.message : 'Failed to acknowledge department flow.'
    };
  }
}

export async function getIntegrationRegistry(sourceDepartment?: string): Promise<IntegrationRegistry> {
  try {
    const params = new URLSearchParams();
    if (sourceDepartment) {
      params.set('department', sourceDepartment.toUpperCase());
    }

    const response = await fetchApiData<MapEndpointResponse>(
      `${buildBackendUrl('/api/integrations/departments/map.php')}?${params.toString()}`
    );

    return (response.connected_departments || []).map((item) => {
      const departmentKey = String(item.department?.key || '').toLowerCase();
      const outgoing = item.outgoing || [];
      const incoming = item.incoming || [];
      const routes = [...outgoing, ...incoming].map((route, index) => ({
        route_key: makeRouteKey(sourceDepartment || 'comlab', departmentKey, String(route.code || '')),
        flow_name: makeFlowName(sourceDepartment || 'comlab', departmentKey, String(route.code || '')),
        event_code: String(route.code || ''),
        endpoint_path: '/api/integrations/departments/records.php',
        priority: index + 1,
        is_required: true
      }));

      return {
        department_key: departmentKey,
        department_name: String(item.department?.name || ''),
        system_code: String(item.department?.code || ''),
        module_directory: '/api/integrations/departments',
        purpose: `Department integration route for ${String(item.department?.name || '')}`,
        default_action_label: outgoing.length > 0 ? 'Dispatch' : 'Receive',
        dispatch_rpc_name: 'records.php',
        status_rpc_name: 'records.php',
        ack_rpc_name: 'records.php',
        dispatch_endpoint: buildBackendUrl('/api/integrations/departments/records.php'),
        pending_count: 0,
        in_progress_count: 0,
        failed_count: 0,
        completed_count: 0,
        route_count: routes.length,
        latest_status: null,
        latest_event_code: routes[0]?.event_code || null,
        latest_correlation_id: null,
        latest_created_at: null,
        routes
      };
    });
  } catch {
    return [];
  }
}

export function invalidateIntegrationCache(): void {
  invalidateApiCache('/api/integrations');
  invalidateApiCache(/\/api\/integrations\/departments\/(map|records)\.php/);
}
