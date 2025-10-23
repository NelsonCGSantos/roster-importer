import type { ColumnMap, ImportJob, ApiResponse, UploadResult } from '@/types';
import { api } from './client';

type UploadApiResponse = ApiResponse<ImportJob> & {
    meta: {
        duplicate?: boolean;
        columns?: string[];
    };
};

export async function uploadRoster(file: File): Promise<UploadResult> {
    const formData = new FormData();
    formData.append('file', file);

    const { data } = await api.post<UploadApiResponse>('/imports', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    });

    return {
        job: data.data,
        duplicate: Boolean(data.meta?.duplicate),
        columns: Array.isArray(data.meta?.columns) ? (data.meta?.columns as string[]) : [],
    };
}

export async function runDryRun(importId: number, columnMap: ColumnMap): Promise<ImportJob> {
    const { data } = await api.post<ApiResponse<ImportJob>>(`/imports/${importId}/dry-run`, {
        column_map: columnMap,
    });

    return data.data;
}

export async function applyImport(importId: number): Promise<ImportJob> {
    const { data } = await api.post<ApiResponse<ImportJob>>(`/imports/${importId}/apply`);

    return data.data;
}

export async function fetchImportHistory(): Promise<ImportJob[]> {
    const { data } = await api.get<ApiResponse<ImportJob[]>>('/imports');

    return data.data;
}
