export type ImportAction = 'create' | 'update' | 'error';

export interface ImportRowPayload {
    full_name?: string | null;
    email?: string | null;
    jersey?: string | null;
    position?: string | null;
    [key: string]: string | null | undefined;
}

export interface ImportRowErrorBag {
    [field: string]: string[];
}

export interface ImportRow {
    id: number;
    row_number: number;
    action: ImportAction;
    payload: ImportRowPayload;
    errors: ImportRowErrorBag | [];
}

export interface ImportCounts {
    total: number;
    created: number;
    updated: number;
    errors: number;
}

export interface ImportUserSummary {
    id: number;
    name: string;
    email: string;
}

export interface ImportJob {
    id: number;
    status: string;
    original_filename: string;
    file_hash: string;
    counts: ImportCounts;
    column_map: Record<string, string | number | null> | null;
    processed_at?: string | null;
    created_at?: string | null;
    error_report_available: boolean;
    error_report_url?: string | null;
    user?: ImportUserSummary;
    rows?: ImportRow[];
}

export interface UploadResult {
    job: ImportJob;
    duplicate: boolean;
    columns: string[];
}

export type ColumnMap = {
    full_name: string | number | null;
    email: string | number | null;
    jersey?: string | number | null;
    position?: string | number | null;
};

export interface ApiResponse<T> {
    data: T;
    meta?: Record<string, unknown>;
}
