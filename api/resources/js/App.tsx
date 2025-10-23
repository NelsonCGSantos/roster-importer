import { useCallback, useEffect, useMemo, useState } from 'react';
import type { Control } from 'react-hook-form';
import { Controller, useForm } from 'react-hook-form';
import toast, { Toaster } from 'react-hot-toast';
import clsx from 'clsx';

import { api, setAuthToken } from '@/api/client';
import { applyImport, fetchImportHistory, runDryRun, uploadRoster } from '@/api/imports';
import type { ColumnMap, ImportAction, ImportJob, ImportRow, ApiResponse } from '@/types';

type View = 'import' | 'history';

type MappingFormValues = {
    full_name: string;
    email: string;
    jersey: string;
    position: string;
};

type LoginFormValues = {
    email: string;
    password: string;
};

type AuthState = {
    token: string;
    user: {
        id: number;
        name: string;
        email: string;
    };
};

const AUTH_STORAGE_KEY = 'roster-importer-auth';

interface SelectFieldProps {
    name: keyof MappingFormValues;
    label: string;
    required?: boolean;
    control: Control<MappingFormValues>;
    options: string[];
    disabled?: boolean;
}

const SelectField = ({ name, label, required = false, control, options, disabled }: SelectFieldProps) => (
    <Controller
        name={name}
        control={control}
        rules={{ required: required ? 'Please choose a column' : false }}
        render={({ field, fieldState }) => (
            <label className="flex flex-col gap-1 text-sm font-medium text-slate-200">
                <span>
                    {label}
                    {required && <span className="text-rose-400"> *</span>}
                </span>
                <select
                    {...field}
                    disabled={disabled}
                    className={clsx(
                        'rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-100 shadow-sm transition focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-500/40 disabled:cursor-not-allowed disabled:opacity-60',
                        fieldState.error && 'border-rose-400 focus:border-rose-400 focus:ring-rose-400/40'
                    )}
                >
                    <option value="">Select a column</option>
                    {options.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </select>
                {fieldState.error && <span className="text-xs text-rose-400">{fieldState.error.message}</span>}
            </label>
        )}
    />
);

interface FilePickerProps {
    selectedFile: File | null;
    onChange: (file: File | null) => void;
}

const FilePicker = ({ selectedFile, onChange }: FilePickerProps) => {
    const handleFileChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const [file] = Array.from(event.target.files ?? []);
        onChange(file ?? null);
    };

    return (
        <label className="flex h-full min-h-[220px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-700 bg-slate-900/60 px-6 py-8 text-center text-sm text-slate-300 shadow-inner transition hover:border-sky-400 hover:bg-slate-900">
            <input
                type="file"
                accept=".csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                className="hidden"
                onChange={handleFileChange}
            />
            <span className="text-base font-medium text-slate-100">Drop your roster CSV/XLSX here</span>
            <span className="text-xs text-slate-400">.csv or .xlsx • max 5k rows</span>
            {selectedFile && (
                <span className="mt-3 rounded bg-slate-800 px-3 py-1 text-xs text-sky-300">{selectedFile.name}</span>
            )}
        </label>
    );
};

interface DryRunTableProps {
    rows: ImportRow[];
}

const statusStyles: Record<ImportAction, string> = {
    create: 'text-emerald-300',
    update: 'text-sky-300',
    error: 'text-rose-300',
};

const DryRunTable = ({ rows }: DryRunTableProps) => (
    <div className="overflow-hidden rounded-lg border border-slate-800 bg-slate-900 shadow">
        <table className="min-w-full divide-y divide-slate-800 text-sm">
            <thead className="bg-slate-900/80 text-xs uppercase tracking-wide text-slate-400">
                <tr>
                    <th className="px-3 py-2 text-left">Row</th>
                    <th className="px-3 py-2 text-left">Name</th>
                    <th className="px-3 py-2 text-left">Email</th>
                    <th className="px-3 py-2 text-left">Jersey</th>
                    <th className="px-3 py-2 text-left">Position</th>
                    <th className="px-3 py-2 text-left">Result</th>
                    <th className="px-3 py-2 text-left">Notes</th>
                </tr>
            </thead>
            <tbody className="divide-y divide-slate-800 text-slate-200">
                {rows.map((row) => (
                    <tr key={row.id ?? row.row_number} className="bg-slate-900/60">
                        <td className="px-3 py-2 font-mono text-xs text-slate-400">{row.row_number}</td>
                        <td className="px-3 py-2">{row.payload?.full_name ?? '—'}</td>
                        <td className="px-3 py-2">{row.payload?.email ?? '—'}</td>
                        <td className="px-3 py-2">{row.payload?.jersey ?? '—'}</td>
                        <td className="px-3 py-2">{row.payload?.position ?? '—'}</td>
                        <td className={clsx('px-3 py-2 font-semibold uppercase', statusStyles[row.action])}>
                            {row.action === 'create' && 'Create'}
                            {row.action === 'update' && 'Update'}
                            {row.action === 'error' && 'Error'}
                        </td>
                        <td className="px-3 py-2 text-xs text-slate-400">
                            {row.action === 'error'
                                ? Object.values(row.errors ?? {})
                                      .flat()
                                      .join(' | ')
                                : '—'}
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    </div>
);

interface HistoryListProps {
    items: ImportJob[];
    onRefresh: () => void;
}

const statusLabel: Record<string, string> = {
    pending: 'Pending upload',
    ready: 'Dry-run ready',
    completed: 'Completed',
    failed: 'Failed',
};

const statusColour: Record<string, string> = {
    pending: 'bg-amber-400/20 text-amber-300',
    ready: 'bg-sky-400/20 text-sky-300',
    completed: 'bg-emerald-400/20 text-emerald-300',
    failed: 'bg-rose-400/20 text-rose-300',
};

const formatDate = (value?: string | null) => {
    if (!value) return '—';
    try {
        return new Intl.DateTimeFormat('en', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value));
    } catch (error) {
        return value;
    }
};

const HistoryList = ({ items, onRefresh }: HistoryListProps) => (
    <div className="space-y-4">
        <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-100">Recent imports</h2>
            <button
                onClick={onRefresh}
                className="rounded-md border border-slate-700 px-3 py-1 text-xs font-medium text-slate-200 transition hover:border-sky-400 hover:text-sky-300"
            >
                Refresh
            </button>
        </div>
        <div className="space-y-3">
            {items.map((item) => (
                <div key={item.id} className="rounded-lg border border-slate-800 bg-slate-900/70 p-4 shadow">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="space-y-1">
                            <h3 className="text-base font-semibold text-slate-100">Import #{item.id}</h3>
                            <p className="text-xs text-slate-400">{item.original_filename}</p>
                        </div>
                        <span
                            className={clsx(
                                'rounded-full px-3 py-1 text-xs font-semibold uppercase',
                                statusColour[item.status] ?? 'bg-slate-700/60 text-slate-200'
                            )}
                        >
                            {statusLabel[item.status] ?? item.status}
                        </span>
                    </div>
                    <dl className="mt-3 grid grid-cols-2 gap-3 text-xs text-slate-300 sm:grid-cols-4">
                        <div>
                            <dt className="text-slate-500">Created</dt>
                            <dd>{formatDate(item.created_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Processed</dt>
                            <dd>{formatDate(item.processed_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Summary</dt>
                            <dd>
                                {item.counts.created} new • {item.counts.updated} updates • {item.counts.errors} errors
                            </dd>
                        </div>
                        <div>
                            <dt className="text-slate-500">Coach</dt>
                            <dd>{item.user?.name ?? '—'}</dd>
                        </div>
                    </dl>
                    {item.error_report_available && item.error_report_url && (
                        <a
                            href={item.error_report_url}
                            className="mt-3 inline-flex items-center gap-1 text-xs font-medium text-sky-300 hover:text-sky-200"
                        >
                            Download error CSV →
                        </a>
                    )}
                </div>
            ))}
            {items.length === 0 && (
                <div className="rounded-lg border border-slate-800 bg-slate-900/70 p-6 text-center text-sm text-slate-400">
                    No imports yet. Run your first upload to see history here.
                </div>
            )}
        </div>
    </div>
);

interface LoginPanelProps {
    onLogin: (values: LoginFormValues) => Promise<void>;
    loading: boolean;
}

const LoginPanel = ({ onLogin, loading }: LoginPanelProps) => {
    const {
        register,
        handleSubmit,
        formState: { errors },
    } = useForm<LoginFormValues>({
        defaultValues: { email: 'coach@example.com', password: 'password' },
    });

    const submit = handleSubmit(async (values) => {
        await onLogin(values);
    });

    return (
        <form onSubmit={submit} className="space-y-4">
            <label className="flex flex-col gap-1 text-sm font-medium text-slate-200">
                Email
                <input
                    type="email"
                    {...register('email', { required: 'Email is required' })}
                    className={clsx(
                        'rounded-md border border-slate-800 bg-slate-900 px-3 py-2 text-sm text-slate-100 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-500/40',
                        errors.email && 'border-rose-400 focus:border-rose-400 focus:ring-rose-400/40'
                    )}
                />
                {errors.email && <span className="text-xs text-rose-400">{errors.email.message}</span>}
            </label>
            <label className="flex flex-col gap-1 text-sm font-medium text-slate-200">
                Password
                <input
                    type="password"
                    {...register('password', { required: 'Password is required' })}
                    className={clsx(
                        'rounded-md border border-slate-800 bg-slate-900 px-3 py-2 text-sm text-slate-100 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-2 focus:ring-sky-500/40',
                        errors.password && 'border-rose-400 focus:border-rose-400 focus:ring-rose-400/40'
                    )}
                />
                {errors.password && <span className="text-xs text-rose-400">{errors.password.message}</span>}
            </label>
            <button
                type="submit"
                disabled={loading}
                className="w-full rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition enabled:hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
            >
                {loading ? 'Signing in…' : 'Sign in'}
            </button>
            <p className="text-xs text-slate-500">
                Tip: use <span className="font-semibold text-slate-200">coach@example.com / password</span>
            </p>
        </form>
    );
};

const DEFAULT_MAPPING: MappingFormValues = {
    full_name: '',
    email: '',
    jersey: '',
    position: '',
};

const App = () => {
    const [auth, setAuth] = useState<AuthState | null>(() => {
        if (typeof window === 'undefined') {
            return null;
        }

        const stored = window.localStorage.getItem(AUTH_STORAGE_KEY);
        if (!stored) {
            return null;
        }

        try {
            const parsed = JSON.parse(stored) as AuthState;
            if (parsed?.token) {
                setAuthToken(parsed.token);
                return parsed;
            }
        } catch (error) {
            console.warn('Invalid auth cache', error);
        }

        return null;
    });
    const [authLoading, setAuthLoading] = useState(false);

    const [view, setView] = useState<View>('import');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [job, setJob] = useState<ImportJob | null>(null);
    const [columns, setColumns] = useState<string[]>([]);
    const [dryRunJob, setDryRunJob] = useState<ImportJob | null>(null);
    const [history, setHistory] = useState<ImportJob[]>([]);
    const [uploading, setUploading] = useState(false);
    const [dryRunning, setDryRunning] = useState(false);
    const [applying, setApplying] = useState(false);

    const {
        control,
        handleSubmit,
        reset,
        watch,
        formState: { errors },
    } = useForm<MappingFormValues>({
        defaultValues: DEFAULT_MAPPING,
    });

    useEffect(() => {
        if (job?.column_map) {
            reset({
                full_name: String(job.column_map.full_name ?? ''),
                email: String(job.column_map.email ?? ''),
                jersey: job.column_map.jersey ? String(job.column_map.jersey) : '',
                position: job.column_map.position ? String(job.column_map.position) : '',
            });
        }
    }, [job?.column_map, reset]);

    useEffect(() => {
        if (!auth) {
            setHistory([]);
        }
    }, [auth]);

    const loadHistory = useCallback(async () => {
        if (!auth) {
            return;
        }

        try {
            const results = await fetchImportHistory();
            setHistory(results);
        } catch (error) {
            reportError(error, 'Failed to load import history');
        }
    }, [auth]);

    useEffect(() => {
        if (view === 'history' && auth) {
            void loadHistory();
        }
    }, [view, auth, loadHistory]);

    const resetState = () => {
        setSelectedFile(null);
        setJob(null);
        setColumns([]);
        setDryRunJob(null);
        reset(DEFAULT_MAPPING);
    };

    const handleLogin = async (values: LoginFormValues) => {
        try {
            setAuthLoading(true);
            const { data } = await api.post<ApiResponse<{ token: string; user: AuthState['user'] }>>('/login', values);

            const payload: AuthState = {
                token: data.data.token,
                user: data.data.user,
            };

            setAuthToken(payload.token);
            setAuth(payload);
            window.localStorage.setItem(AUTH_STORAGE_KEY, JSON.stringify(payload));
            toast.success(`Welcome back, ${payload.user.name}!`);
        } catch (error) {
            reportError(error, 'Login failed');
        } finally {
            setAuthLoading(false);
        }
    };

    const handleLogout = async () => {
        try {
            await api.post('/logout');
        } catch (error) {
            if (import.meta.env.DEV) {
                console.warn('Logout error', error);
            }
        } finally {
            setAuth(null);
            setAuthToken(null);
            window.localStorage.removeItem(AUTH_STORAGE_KEY);
            resetState();
            toast.success('Signed out.');
        }
    };

    const watchValues = watch();

    const duplicateColumnsSelected = useMemo(() => {
        const selections = [
            watchValues.full_name,
            watchValues.email,
            watchValues.jersey,
            watchValues.position,
        ].filter((value) => value && value.length > 0);

        return new Set(selections).size !== selections.length;
    }, [watchValues.full_name, watchValues.email, watchValues.jersey, watchValues.position]);

    const canApply = useMemo(() => {
        if (!dryRunJob) return false;
        if (dryRunJob.counts.total === 0) return false;
        return dryRunJob.status === 'ready';
    }, [dryRunJob]);

    const handleUpload = async () => {
        if (!auth) {
            toast.error('Please sign in before uploading.');
            return;
        }

        if (!selectedFile) {
            toast.error('Choose a roster file first.');
            return;
        }

        try {
            setUploading(true);
            const result = await uploadRoster(selectedFile);
            setJob(result.job);
            setColumns(result.columns);
            setDryRunJob(null);
            reset(DEFAULT_MAPPING);

            if (result.duplicate) {
                toast.success('This file already has an import job. Re-run the dry-run to continue.');
            } else {
                toast.success('File uploaded. Map the columns to run the dry-run.');
            }
        } catch (error) {
            reportError(error, 'Upload failed');
        } finally {
            setUploading(false);
        }
    };

    const onSubmitMapping = async (values: MappingFormValues) => {
        if (!auth) {
            toast.error('Please sign in first.');
            return;
        }

        if (!job) {
            toast.error('Upload a file before running the dry-run.');
            return;
        }

        const columnMap: ColumnMap = {
            full_name: values.full_name || null,
            email: values.email || null,
            jersey: values.jersey || null,
            position: values.position || null,
        };

        try {
            setDryRunning(true);
            const latestJob = await runDryRun(job.id, columnMap);
            setDryRunJob(latestJob);
            setJob(latestJob);
            toast.success('Dry-run completed. Review the rows below.');
        } catch (error) {
            reportError(error, 'Dry-run failed');
        } finally {
            setDryRunning(false);
        }
    };

    const handleApply = async () => {
        if (!auth) {
            toast.error('Please sign in first.');
            return;
        }

        if (!job) {
            toast.error('No import job to apply.');
            return;
        }

        try {
            setApplying(true);
            const result = await applyImport(job.id);
            setDryRunJob(result);
            setJob(result);
            toast.success('Import applied successfully.');
            void loadHistory();
        } catch (error) {
            reportError(error, 'Apply import failed');
        } finally {
            setApplying(false);
        }
    };

    if (!auth) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-950 px-4 text-slate-100">
                <Toaster position="top-right" />
                <div className="w-full max-w-md space-y-6 rounded-xl border border-slate-900 bg-slate-950/80 p-6 shadow-xl shadow-slate-950/30">
                    <div className="space-y-2 text-center">
                        <h1 className="text-2xl font-semibold">Roster Importer</h1>
                        <p className="text-sm text-slate-400">
                            Sign in with the seeded coach credentials to access the importer tools.
                        </p>
                    </div>
                    <LoginPanel onLogin={handleLogin} loading={authLoading} />
                </div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100">
            <Toaster position="top-right" />
            <header className="border-b border-slate-900 bg-slate-950/80 backdrop-blur">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-5">
                    <div>
                        <h1 className="text-xl font-semibold">Roster Importer</h1>
                        <p className="text-xs text-slate-400">
                            Upload, validate, and import player rosters in minutes.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <nav className="flex gap-2 text-sm">
                            <button
                                onClick={() => setView('import')}
                                className={clsx(
                                    'rounded-md px-3 py-1 transition',
                                    view === 'import'
                                        ? 'bg-sky-500/20 text-sky-200'
                                        : 'text-slate-300 hover:text-sky-200'
                                )}
                            >
                                Import
                            </button>
                            <button
                                onClick={() => setView('history')}
                                className={clsx(
                                    'rounded-md px-3 py-1 transition',
                                    view === 'history'
                                        ? 'bg-sky-500/20 text-sky-200'
                                        : 'text-slate-300 hover:text-sky-200'
                                )}
                            >
                                History
                            </button>
                        </nav>
                        <div className="flex items-center gap-3 text-xs text-slate-400">
                            <span>Signed in as <span className="font-semibold text-slate-200">{auth.user.name}</span></span>
                            <button
                                onClick={handleLogout}
                                className="rounded-md border border-slate-700 px-3 py-1 text-xs font-medium text-slate-200 transition hover:border-rose-400 hover:text-rose-300"
                            >
                                Sign out
                            </button>
                        </div>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-8">
                {view === 'import' && (
                    <div className="space-y-8">
                        <section className="rounded-xl border border-slate-900 bg-slate-950/80 p-6 shadow-xl shadow-slate-950/30">
                            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
                                <FilePicker selectedFile={selectedFile} onChange={setSelectedFile} />
                                <div className="flex flex-col justify-between space-y-4 text-sm">
                                    <div className="space-y-3">
                                        <p className="text-slate-300">
                                            1. Choose your roster file. 2. Upload to generate an import job. 3. Map the columns, review the dry-run, then apply.
                                        </p>
                                        <div className="flex gap-2">
                                            <button
                                                onClick={handleUpload}
                                                disabled={uploading || !selectedFile}
                                                className="inline-flex items-center gap-2 rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition enabled:hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                {uploading ? 'Uploading…' : 'Upload & start import'}
                                            </button>
                                            <button
                                                onClick={resetState}
                                                className="rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-300 transition hover:border-sky-400 hover:text-sky-200"
                                            >
                                                Reset
                                            </button>
                                        </div>
                                    </div>
                                    {job && (
                                        <div className="rounded-lg border border-slate-800 bg-slate-900/70 p-4 text-xs text-slate-300">
                                            <p className="font-semibold text-slate-100">Active import job</p>
                                            <ul className="mt-2 space-y-1">
                                                <li>#{job.id} • {job.original_filename}</li>
                                                <li>Rows processed: {job.counts?.total ?? 0}</li>
                                                <li>Status: {statusLabel[job.status] ?? job.status}</li>
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </section>

                        {job && columns.length > 0 && (
                            <section className="rounded-xl border border-slate-900 bg-slate-950/80 p-6 shadow-xl shadow-slate-950/30">
                                <h2 className="text-lg font-semibold text-slate-100">Map columns</h2>
                                <p className="mt-1 text-sm text-slate-400">
                                    Match your spreadsheet headers to the player fields. Full name and email are required.
                                </p>
                                <form
                                    onSubmit={handleSubmit(onSubmitMapping)}
                                    className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2"
                                >
                                    <SelectField
                                        name="full_name"
                                        label="Player name"
                                        required
                                        control={control}
                                        options={columns}
                                        disabled={dryRunning}
                                    />
                                    <SelectField
                                        name="email"
                                        label="Email"
                                        required
                                        control={control}
                                        options={columns}
                                        disabled={dryRunning}
                                    />
                                    <SelectField
                                        name="jersey"
                                        label="Jersey number"
                                        control={control}
                                        options={columns}
                                        disabled={dryRunning}
                                    />
                                    <SelectField
                                        name="position"
                                        label="Position"
                                        control={control}
                                        options={columns}
                                        disabled={dryRunning}
                                    />
                                    <div className="col-span-full flex flex-wrap items-center gap-3 pt-2">
                                        <button
                                            type="submit"
                                            disabled={dryRunning}
                                            className="rounded-md bg-emerald-500 px-4 py-2 text-sm font-semibold text-emerald-950 transition enabled:hover:bg-emerald-400 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {dryRunning ? 'Running dry-run…' : 'Run dry-run'}
                                        </button>
                                        {duplicateColumnsSelected && (
                                            <span className="text-xs text-amber-400">
                                                Heads up: duplicate column selections detected.
                                            </span>
                                        )}
                                        {Object.keys(errors).length > 0 && (
                                            <span className="text-xs text-rose-300">Please resolve the field errors above.</span>
                                        )}
                                    </div>
                                </form>
                            </section>
                        )}

                        {dryRunJob && dryRunJob.rows && (
                            <section className="space-y-4 rounded-xl border border-slate-900 bg-slate-950/80 p-6 shadow-xl shadow-slate-950/30">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h2 className="text-lg font-semibold text-slate-100">Dry-run preview</h2>
                                        <p className="text-sm text-slate-400">Review the actions below before applying the import.</p>
                                    </div>
                                    <div className="rounded-lg border border-slate-800 bg-slate-900/60 px-4 py-2 text-xs text-slate-300">
                                        <span className="mr-3 text-emerald-300">{dryRunJob.counts.created} create</span>
                                        <span className="mr-3 text-sky-300">{dryRunJob.counts.updated} update</span>
                                        <span className="text-rose-300">{dryRunJob.counts.errors} error</span>
                                    </div>
                                </div>
                                <DryRunTable rows={dryRunJob.rows} />
                                <div className="flex flex-wrap items-center gap-3">
                                    <button
                                        onClick={handleApply}
                                        disabled={!canApply || applying}
                                        className="rounded-md bg-sky-500 px-4 py-2 text-sm font-semibold text-slate-950 transition enabled:hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {applying ? 'Applying…' : 'Apply import'}
                                    </button>
                                    <button
                                        onClick={() => dryRunJob.error_report_url && window.open(dryRunJob.error_report_url, '_blank')}
                                        disabled={!dryRunJob.error_report_available || !dryRunJob.error_report_url}
                                        className="rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-200 transition enabled:hover:border-sky-400 enabled:hover:text-sky-200 disabled:cursor-not-allowed disabled:opacity-40"
                                    >
                                        Download error CSV
                                    </button>
                                </div>
                                {dryRunJob.status === 'completed' && (
                                    <div className="rounded-lg border border-emerald-500/40 bg-emerald-500/10 p-4 text-sm text-emerald-200">
                                        Import completed! Players were created/updated according to the plan above.
                                    </div>
                                )}
                            </section>
                        )}
                    </div>
                )}

                {view === 'history' && (
                    <HistoryList items={history} onRefresh={loadHistory} />
                )}
            </main>
        </div>
    );
};

function reportError(error: unknown, fallback: string) {
    if (typeof error === 'object' && error && 'response' in error) {
        const response = (error as { response?: { data?: any } }).response;
        const message =
            response?.data?.message ||
            (response?.data?.errors
                ? Object.values(response.data.errors)
                      .flat()
                      .join('\n')
                : undefined);

        if (message) {
            toast.error(message);
            return;
        }
    }

    toast.error(fallback);
}

export default App;
