import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';

const apiBase = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

type PageId =
  | 'overview'
  | 'customers'
  | 'providers'
  | 'verification'
  | 'categories'
  | 'services'
  | 'bookings'
  | 'disputes'
  | 'reviews'
  | 'notifications'
  | 'reports'
  | 'settings'
  | 'waitlist'
  | 'audit';
type ApiRecord = Record<string, unknown>;
type Paginated<T> = { data: T[]; current_page?: number; last_page?: number; total?: number };
type Settings = {
  pricing_model: 'fixed' | 'provider_defined' | 'customer_quotation';
  payment_gateways: string[];
  payments_enabled: boolean;
  commission_rate: number | null;
  cancellation_fee: number | null;
  refund_policy: string | null;
};

const sections: { id: PageId; label: string; icon: string; group: string }[] = [
  { id: 'overview', label: 'Overview', icon: 'grid', group: 'Workspace' },
  { id: 'customers', label: 'Customers', icon: 'users', group: 'People' },
  { id: 'providers', label: 'Providers', icon: 'briefcase', group: 'People' },
  { id: 'verification', label: 'Verification', icon: 'shield', group: 'People' },
  { id: 'categories', label: 'Categories', icon: 'layers', group: 'Marketplace' },
  { id: 'services', label: 'Service moderation', icon: 'spark', group: 'Marketplace' },
  { id: 'bookings', label: 'Bookings', icon: 'calendar', group: 'Marketplace' },
  { id: 'disputes', label: 'Disputes', icon: 'flag', group: 'Marketplace' },
  { id: 'reviews', label: 'Reviews', icon: 'star', group: 'Marketplace' },
  { id: 'notifications', label: 'Notifications', icon: 'bell', group: 'Insights' },
  { id: 'reports', label: 'Reports', icon: 'chart', group: 'Insights' },
  { id: 'waitlist', label: 'Waitlist', icon: 'mail', group: 'Insights' },
  { id: 'audit', label: 'Audit log', icon: 'history', group: 'Insights' },
  { id: 'settings', label: 'Platform settings', icon: 'settings', group: 'System' },
];

const headings: Record<PageId, { title: string; description: string }> = {
  overview: { title: 'Good morning', description: 'A clear view of what is happening across Neybor.' },
  customers: { title: 'Customers', description: 'Search accounts, review status, and manage access.' },
  providers: { title: 'Service providers', description: 'Manage professional accounts and platform access.' },
  verification: { title: 'Provider verification', description: 'Review providers before they can receive requests.' },
  categories: { title: 'Service categories', description: 'Keep the marketplace catalogue useful and organized.' },
  services: { title: 'Service moderation', description: 'Review published services and take action with a recorded reason.' },
  bookings: { title: 'Booking monitor', description: 'Track requests and service progress from the source of truth.' },
  disputes: { title: 'Disputes', description: 'A dedicated workflow will be added when dispute handling is defined.' },
  reviews: { title: 'Reviews', description: 'Monitor customer feedback and remove content only with a reason.' },
  notifications: { title: 'Notifications', description: 'Keep up with notices sent to your administrator account.' },
  reports: { title: 'Reports', description: 'Review marketplace activity from persisted API data.' },
  settings: { title: 'Platform settings', description: 'Configure operating preferences without exposing secrets.' },
  waitlist: { title: 'Waitlist', description: 'Customer and provider interest captured by the pre-launch site.' },
  audit: { title: 'Audit log', description: 'A traceable record of administrator actions.' },
};

type ApiError = Error & { status?: number; details?: Record<string, string[]> };

async function api<T>(path: string, token?: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  if (init.body) headers.set('Content-Type', 'application/json');
  if (token) headers.set('Authorization', `Bearer ${token}`);
  const response = await fetch(`${apiBase}${path}`, { ...init, headers });
  const body: unknown = await response.json().catch(() => ({}));
  if (!response.ok) {
    const data = body as { message?: string; errors?: Record<string, string[]> };
    const error = new Error(data.message ?? `Request failed (${response.status})`) as ApiError;
    error.status = response.status;
    error.details = data.errors;
    throw error;
  }
  return body as T;
}

function Icon({ name, size = 18 }: { name: string; size?: number }) {
  const common = { fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const };
  const shapes: Record<string, React.ReactNode> = {
    grid: <><rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" /></>,
    users: <><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" /><circle cx="10" cy="7" r="4" /><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></>,
    briefcase: <><rect x="3" y="7" width="18" height="14" rx="2" /><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18M10 12v2h4v-2" /></>,
    shield: <><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z" /><path d="m9 12 2 2 4-4" /></>,
    layers: <><path d="m12 3 9 5-9 5-9-5 9-5z" /><path d="m3 12 9 5 9-5M3 16l9 5 9-5" /></>,
    spark: <><path d="m12 3 1.9 5.8L20 11l-6.1 2.2L12 19l-1.9-5.8L4 11l6.1-2.2L12 3z" /><path d="m19 14 1 2.5 2.5 1-2.5 1L19 21l-1-2.5-2.5-1 2.5-1 1-2.5z" /></>,
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" /></>,
    flag: <><path d="M4 22V5m0 0c5-4 10 4 16 0v12c-6 4-11-4-16 0" /></>,
    star: <><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3l-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3z" /></>,
    bell: <><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></>,
    chart: <><path d="M4 19V5M4 19h17M8 15v-4M13 15V7M18 15v-7" /></>,
    mail: <><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></>,
    history: <><path d="M3 12a9 9 0 1 0 3-6.7L3 8" /><path d="M3 3v5h5m4-1v5l4 2" /></>,
    settings: <><circle cx="12" cy="12" r="3" /><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a7.8 7.8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a7.8 7.8 0 0 1-1.7-1l-1.7.7-1.4-2.4 1.4-1.1a7.4 7.4 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a7.8 7.8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a7.8 7.8 0 0 1 1.7 1l1.7-.7 1.4 2.4-1.4 1.1a7.4 7.4 0 0 1 0 2z" /></>,
    search: <><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></>,
    arrow: <><path d="M5 12h14m-7-7 7 7-7 7" /></>,
    logout: <><path d="M10 17l5-5-5-5m5 5H3" /><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7" /></>,
  };
  return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" {...common}>{shapes[name] ?? shapes.grid}</svg>;
}

function getPath(value: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((current, segment) => {
    if (current && typeof current === 'object') return (current as Record<string, unknown>)[segment];
    return undefined;
  }, value);
}

function text(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

function pretty(value: unknown): string {
  return text(value).replaceAll('_', ' ');
}

function date(value: unknown): string {
  if (!value) return '—';
  const parsed = new Date(String(value));
  return Number.isNaN(parsed.getTime()) ? text(value) : parsed.toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' });
}

function Status({ value }: { value: unknown }) {
  const label = pretty(value);
  const normalized = label.toLowerCase();
  const tone = ['verified', 'completed', 'accepted', 'active', 'approved', 'read'].includes(normalized)
    ? 'green'
    : ['pending', 'offered', 'searching', 'in progress', 'arriving', 'en route'].includes(normalized)
      ? 'amber'
      : ['rejected', 'cancelled', 'suspended', 'no provider available', 'expired'].includes(normalized)
        ? 'red'
        : 'slate';
  return <span className={`status status-${tone}`}><span className="status-dot" />{label}</span>;
}

function Login({ onLogin }: { onLogin: (token: string, user: ApiRecord) => void }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const result = await api<{ data: ApiRecord; token: string }>('/auth/login', undefined, {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      });
      if (result.data.role !== 'administrator') {
        throw new Error('This account does not have administrator access.');
      }
      localStorage.setItem('neybor-admin-token', result.token);
      onLogin(result.token, result.data);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Unable to sign in.');
    } finally {
      setBusy(false);
    }
  }
  return (
    <main className="login-page">
      <section className="login-card">
        <div className="brand-mark">N</div>
        <p className="eyebrow">NEYBOR OPERATIONS</p>
        <h1>Welcome back.</h1>
        <p className="muted">Sign in with your administrator account to continue.</p>
        <form className="form-stack" onSubmit={submit}>
          <label>Email address<input autoComplete="username" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} /></label>
          <label>Password<input autoComplete="current-password" type="password" required value={password} onChange={(event) => setPassword(event.target.value)} /></label>
          {error && <p className="inline-error" role="alert">{error}</p>}
          <button className="button button-primary button-wide" disabled={busy}>{busy ? 'Signing in…' : 'Continue'} <Icon name="arrow" /></button>
        </form>
        <p className="login-foot">Administrator accounts are provisioned securely. Public registration is disabled.</p>
      </section>
    </main>
  );
}

function Overview({ token }: { token: string }) {
  const [data, setData] = useState<ApiRecord | null>(null);
  const [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController();
    api<{ data: ApiRecord }>('/admin/overview', token, { signal: controller.signal })
      .then((response) => setData(response.data))
      .catch((reason: unknown) => {
        if (!(reason instanceof Error && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Could not load analytics.');
      });
    return () => controller.abort();
  }, [token]);
  if (error) return <ErrorPanel message={error} />;
  if (!data) return <Skeleton />;
  const metrics = [
    { label: 'Customers', value: data.customers, note: 'Customer accounts', icon: 'users', color: 'mint' },
    { label: 'Service providers', value: data.providers, note: 'Professional accounts', icon: 'briefcase', color: 'blue' },
    { label: 'Pending verification', value: data.pending_verifications, note: 'Awaiting review', icon: 'shield', color: 'gold' },
    { label: 'Active bookings', value: data.active_bookings, note: 'In progress now', icon: 'calendar', color: 'violet' },
  ];
  const activity = Array.isArray(data.booking_activity) ? data.booking_activity as ApiRecord[] : [];
  const maxActivity = Math.max(1, ...activity.map((item) => Number(item.bookings ?? 0)));
  const states = data.booking_states && typeof data.booking_states === 'object' ? Object.entries(data.booking_states as ApiRecord) : [];
  return (
    <div className="page-stack">
      <div className="metric-grid">
        {metrics.map((metric) => <article className="metric-card" key={metric.label}>
          <div className={`metric-icon ${metric.color}`}><Icon name={metric.icon} size={19} /></div>
          <p className="metric-label">{metric.label}</p>
          <div className="metric-value">{text(metric.value)}</div>
          <p className="metric-note">{metric.note}</p>
        </article>)}
      </div>
      <div className="overview-grid">
        <section className="panel activity-panel">
          <div className="panel-heading"><div><p className="eyebrow">MARKETPLACE ACTIVITY</p><h2>Booking requests</h2></div><span className="subtle-chip">Last 7 days</span></div>
          {activity.length === 0 ? <EmptyState title="No activity yet" description="Booking activity will appear here when customers submit requests." /> :
            <div className="chart" role="img" aria-label="Daily booking requests for the last seven days">
              {activity.map((item) => <div className="chart-column" key={String(item.date)}><span className="chart-count">{text(item.bookings)}</span><div className="chart-bar-wrap"><div className="chart-bar" style={{ height: `${Math.max(4, Number(item.bookings) / maxActivity * 100)}%` }} /></div><span className="chart-date">{new Date(`${String(item.date)}T12:00:00`).toLocaleDateString('en', { weekday: 'short' })}</span></div>)}
            </div>}
          <div className="chart-footer"><span>Total bookings <strong>{text(data.bookings_total)}</strong></span><span>Completed <strong>{text(data.completed_bookings)}</strong></span></div>
        </section>
        <section className="panel status-panel">
          <div className="panel-heading"><div><p className="eyebrow">BOOKING HEALTH</p><h2>Current statuses</h2></div><Icon name="chart" /></div>
          {states.length === 0 ? <EmptyState title="No bookings yet" description="Status breakdown will appear once requests arrive." /> : <div className="status-list">
            {states.map(([key, count]) => <div className="status-row" key={key}><span className="status-name"><span className={`legend-dot legend-${key}`} />{pretty(key)}</span><strong>{text(count)}</strong></div>)}
          </div>}
          <div className="waitlist-summary"><span>Pre-launch waitlist</span><strong>{text(data.waitlist_total)}</strong></div>
        </section>
      </div>
      <section className="panel">
        <div className="panel-heading"><div><p className="eyebrow">ADMINISTRATION</p><h2>Recent audit activity</h2></div></div>
        <DataTable rows={Array.isArray(data.recent_audit_logs) ? data.recent_audit_logs as ApiRecord[] : []} columns={[
          { label: 'Action', key: 'action' }, { label: 'Administrator', key: 'actor_user_id' }, { label: 'Target', key: 'target_role' }, { label: 'When', key: 'created_at', format: date },
        ]} empty="No administrator actions have been recorded yet." />
      </section>
    </div>
  );
}

type Column = { label: string; key: string; format?: (value: unknown, row: ApiRecord) => React.ReactNode };
function DataTable({ rows, columns, empty }: { rows: ApiRecord[]; columns: Column[]; empty: string }) {
  if (rows.length === 0) return <EmptyState title="Nothing to show" description={empty} />;
  return <div className="table-scroll"><table><thead><tr>{columns.map((column) => <th key={column.key}>{column.label}</th>)}</tr></thead><tbody>
    {rows.map((row, index) => <tr key={String(row.id ?? `${row.action ?? 'row'}-${index}`)}>{columns.map((column) => {
      const value = getPath(row, column.key);
      const cell = column.format
        ? column.format(value, row)
        : column.key.includes('status') || column.key === 'role'
          ? <Status value={value} />
          : text(value);
      return <td key={column.key}>{cell}</td>;
    })}</tr>)}
  </tbody></table></div>;
}

function EmptyState({ title, description }: { title: string; description: string }) {
  return <div className="empty-state"><div className="empty-icon"><Icon name="layers" size={20} /></div><h3>{title}</h3><p>{description}</p></div>;
}

function ErrorPanel({ message }: { message: string }) {
  return <div className="error-panel" role="alert"><strong>We couldn’t load this view.</strong><p>{message}</p></div>;
}

function Skeleton() {
  return <div className="page-stack" aria-label="Loading"><div className="metric-grid">{[1, 2, 3, 4].map((item) => <div className="panel skeleton-card" key={item}><span /><span /><span /></div>)}</div><div className="panel skeleton-table"><span /><span /><span /><span /></div></div>;
}

const listConfig: Partial<Record<PageId, { path: string; columns: Column[]; search?: string; filter?: { key: string; options: string[] } }>> = {
  customers: { path: '/admin/users?role=customer', search: 'name, email or phone', filter: { key: 'suspended', options: ['all', 'false', 'true'] }, columns: [{ label: 'Customer', key: 'name' }, { label: 'Email', key: 'email' }, { label: 'Joined', key: 'created_at', format: date }, { label: 'Access', key: 'suspended_at', format: (value) => value ? <Status value="suspended" /> : <Status value="active" /> }] },
  providers: { path: '/admin/providers', search: 'name, business or email', filter: { key: 'status', options: ['all', 'pending', 'verified', 'rejected'] }, columns: [{ label: 'Provider', key: 'name' }, { label: 'Business', key: 'provider_profile.business_name' }, { label: 'Email', key: 'email' }, { label: 'Verification', key: 'provider_verification_status' }, { label: 'Services', key: 'services_count' }, { label: 'Access', key: 'suspended_at', format: (value) => value ? <Status value="suspended" /> : <Status value="active" /> }] },
  verification: { path: '/admin/providers', search: 'provider or business', filter: { key: 'status', options: ['all', 'pending', 'verified', 'rejected'] }, columns: [{ label: 'Provider', key: 'name' }, { label: 'Business', key: 'provider_profile.business_name' }, { label: 'Email', key: 'email' }, { label: 'Email verified', key: 'email_verified_at', format: (value) => value ? <Status value="verified" /> : <Status value="pending" /> }, { label: 'Identity submitted', key: 'provider_profile.identity_submitted_at', format: date }, { label: 'Services', key: 'services_count' }] },
  categories: { path: '/admin/categories', search: 'category name', columns: [{ label: 'Category', key: 'name' }, { label: 'Slug', key: 'slug' }, { label: 'Services', key: 'services_count' }, { label: 'Status', key: 'is_active', format: (value) => <Status value={value ? 'active' : 'inactive'} /> }] },
  services: { path: '/admin/services', search: 'service, category or provider', filter: { key: 'active', options: ['all', 'true', 'false'] }, columns: [{ label: 'Service', key: 'name' }, { label: 'Category', key: 'category.name' }, { label: 'Provider', key: 'provider.name' }, { label: 'Price', key: 'base_price', format: (value, row) => value === null ? 'Provider quote' : `₦${Number(value).toLocaleString()} ${text(row.price_unit)}` }, { label: 'Status', key: 'is_active', format: (value) => <Status value={value ? 'active' : 'inactive'} /> }] },
  bookings: { path: '/admin/bookings', search: 'customer, provider, service or address', filter: { key: 'status', options: ['all', 'pending_matching', 'offered', 'matched', 'accepted', 'en_route', 'arrived', 'in_progress', 'completed', 'cancelled', 'no_provider_available'] }, columns: [{ label: 'Request', key: 'id', format: (value) => `#${text(value)}` }, { label: 'Service', key: 'service.name' }, { label: 'Customer', key: 'customer.name' }, { label: 'Provider', key: 'provider.name' }, { label: 'Status', key: 'status' }, { label: 'Created', key: 'created_at', format: date }] },
  reviews: { path: '/admin/reviews', search: 'review or name', filter: { key: 'rating', options: ['all', '5', '4', '3', '2', '1'] }, columns: [{ label: 'Rating', key: 'rating', format: (value) => `${text(value)} / 5` }, { label: 'Review', key: 'comment' }, { label: 'Customer', key: 'customer.name' }, { label: 'Provider', key: 'provider.name' }, { label: 'Service', key: 'booking.service.name' }, { label: 'Submitted', key: 'created_at', format: date }] },
  notifications: { path: '/admin/notifications', columns: [{ label: 'Notification', key: 'data.title' }, { label: 'Message', key: 'data.body' }, { label: 'Read', key: 'read_at', format: (value) => value ? <Status value="read" /> : <Status value="unread" /> }, { label: 'Received', key: 'created_at', format: date }] },
  waitlist: { path: '/admin/waitlist', search: 'name, email or business', filter: { key: 'role', options: ['all', 'customer', 'provider'] }, columns: [{ label: 'Name', key: 'name' }, { label: 'Email', key: 'email' }, { label: 'Interest', key: 'role' }, { label: 'Business', key: 'business_name' }, { label: 'Service interest', key: 'service_interest' }, { label: 'Joined', key: 'created_at', format: date }] },
  audit: { path: '/admin/audit-logs', search: 'action or administrator', columns: [{ label: 'Action', key: 'action' }, { label: 'Actor', key: 'actor_user_id' }, { label: 'Target', key: 'target_role' }, { label: 'Details', key: 'context' }, { label: 'Date', key: 'created_at', format: date }] },
};

function ListPage({ page, token }: { page: PageId; token: string }) {
  const config = listConfig[page];
  const [rows, setRows] = useState<ApiRecord[]>([]);
  const [current, setCurrent] = useState(1);
  const [last, setLast] = useState(1);
  const [total, setTotal] = useState(0);
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState(page === 'verification' ? 'pending' : 'all');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refresh, setRefresh] = useState(0);
  const [dialog, setDialog] = useState<{ row: ApiRecord; action: string } | null>(null);
  const [notice, setNotice] = useState('');
  const [newCategory, setNewCategory] = useState(false);
  const [editingCategory, setEditingCategory] = useState<ApiRecord | null>(null);
  const [categoryName, setCategoryName] = useState('');
  const [categoryDescription, setCategoryDescription] = useState('');
  const [categoryReason, setCategoryReason] = useState('');
  const [busy, setBusy] = useState(false);
  const query = useMemo(() => {
    const params = new URLSearchParams({ page: String(current) });
    if (search.trim()) params.set('search', search.trim());
    if (config?.filter && filter !== 'all') params.set(config.filter.key, filter);
    return params.toString();
  }, [config?.filter, current, filter, search]);

  useEffect(() => {
    if (!config) return;
    const abort = new AbortController();
    const timer = window.setTimeout(() => {
      setLoading(true);
      setError('');
      api<Paginated<ApiRecord> | { data: ApiRecord[] }>(`${config.path}${config.path.includes('?') ? '&' : '?'}${query}`, token, { signal: abort.signal })
        .then((response) => {
          const result = response as Paginated<ApiRecord>;
          const values = Array.isArray(result.data) ? result.data : [];
          setRows(values);
          setCurrent(result.current_page ?? 1);
          setLast(result.last_page ?? 1);
          setTotal(result.total ?? values.length);
        })
        .catch((reason: unknown) => {
          if (!(reason instanceof Error && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Unable to load records.');
        })
        .finally(() => setLoading(false));
    }, search ? 250 : 0);
    return () => { abort.abort(); window.clearTimeout(timer); };
  }, [config, query, refresh, search, token]);

  async function performAction(reason: string) {
    if (!dialog) return;
    const row = dialog.row;
    let path = '';
    let method = 'POST';
    let body: Record<string, unknown> = { reason };
    if (dialog.action === 'suspend') path = `/admin/users/${row.id}/suspend`;
    else if (dialog.action === 'reactivate') path = `/admin/users/${row.id}/unsuspend`;
    else if (dialog.action === 'approve' || dialog.action === 'reject') {
      path = `/admin/providers/${row.id}/verification`;
      body = { decision: dialog.action === 'approve' ? 'verified' : 'rejected', reason };
    } else if (dialog.action === 'activate' || dialog.action === 'deactivate') {
      path = `/admin/services/${row.id}/moderation`;
      method = 'PATCH';
      body = { is_active: dialog.action === 'activate', reason };
    } else if (dialog.action === 'remove review') {
      path = `/admin/reviews/${row.id}`;
      method = 'DELETE';
    } else if (dialog.action === 'mark read') {
      path = `/admin/notifications/${row.id}/read`;
      body = {};
    } else if (dialog.action === 'activate category' || dialog.action === 'deactivate category') {
      path = `/admin/categories/${row.id}`;
      method = 'PATCH';
      body = { is_active: dialog.action === 'activate category', reason };
    } else if (dialog.action === 'remove category') {
      path = `/admin/categories/${row.id}`;
      method = 'DELETE';
    }
    setBusy(true);
    try {
      await api(path, token, { method, body: JSON.stringify(body) });
      setNotice(`Completed: ${dialog.action}.`);
      setDialog(null);
      setRefresh((value) => value + 1);
    } catch (reasonError) {
      setError(reasonError instanceof Error ? reasonError.message : 'The action failed.');
    } finally {
      setBusy(false);
    }
  }

  async function createCategory(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    try {
      await api('/admin/categories', token, { method: 'POST', body: JSON.stringify({ name: categoryName, description: categoryDescription }) });
      setNewCategory(false);
      setCategoryName('');
      setCategoryDescription('');
      setNotice('Category created.');
      setRefresh((value) => value + 1);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not create category.');
    } finally {
      setBusy(false);
    }
  }

  async function saveCategory(event: FormEvent) {
    event.preventDefault();
    if (!editingCategory) return;
    setBusy(true);
    try {
      await api(`/admin/categories/${editingCategory.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ name: categoryName, description: categoryDescription, reason: categoryReason }),
      });
      setEditingCategory(null);
      setCategoryReason('');
      setNotice('Category updated.');
      setRefresh((value) => value + 1);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not update category.');
    } finally {
      setBusy(false);
    }
  }

  function openAdminAction(action: { row: ApiRecord; action: string }) {
    if (action.action === 'edit category') {
      setEditingCategory(action.row);
      setCategoryName(String(action.row.name ?? ''));
      setCategoryDescription(String(action.row.description ?? ''));
      setCategoryReason('');
      return;
    }
    setDialog(action);
  }

  if (!config) return <EmptyState title="Page unavailable" description="This section is not configured." />;
  const columns = [...config.columns];
  if (['customers', 'providers'].includes(page)) columns.push({ label: 'Action', key: 'admin-action' });
  if (page === 'verification') columns.push({ label: 'Decision', key: 'admin-action' });
  if (page === 'services' || page === 'categories' || page === 'reviews' || page === 'notifications') columns.push({ label: 'Action', key: 'admin-action' });
  const displayRows = rows.map((row) => {
    const mapped = { ...row };
    return mapped;
  });
  const tableRows = displayRows.map((row) => {
    const wrapped = { ...row };
    return wrapped;
  });
  return <div className="panel">
    <div className="list-toolbar">
      <div className="list-count"><strong>{total.toLocaleString()}</strong><span>records</span></div>
      <div className="toolbar-controls">
        {config.search && <label className="search-field"><Icon name="search" size={16} /><input aria-label="Search records" placeholder={`Search ${config.search}`} value={search} onChange={(event) => { setCurrent(1); setSearch(event.target.value); }} /></label>}
        {config.filter && <select aria-label={`Filter ${config.filter.key}`} value={filter} onChange={(event) => { setCurrent(1); setFilter(event.target.value); }}>{config.filter.options.map((option) => <option value={option} key={option}>{option === 'all' ? 'All' : pretty(option)}</option>)}</select>}
        {page === 'categories' && <button className="button button-primary" onClick={() => setNewCategory(true)}>Add category <span aria-hidden>＋</span></button>}
        <button aria-label="Refresh records" className="icon-button" onClick={() => setRefresh((value) => value + 1)}><Icon name="history" /></button>
      </div>
    </div>
    {notice && <div className="notice" role="status">{notice}<button onClick={() => setNotice('')} aria-label="Dismiss">×</button></div>}
    {error && <ErrorPanel message={error} />}
    {loading ? <Skeleton /> : <DataTable rows={tableRows} columns={columns.map((column) => {
      if (column.key !== 'admin-action') return column;
      return { ...column, format: (_value, row) => <ActionButtons page={page} row={row} open={openAdminAction} /> };
    })} empty={emptyFor(page)} />}
    {last > 1 && <div className="pagination"><span>Page {current} of {last}</span><div><button className="button button-quiet" disabled={current <= 1} onClick={() => setCurrent((value) => Math.max(1, value - 1))}>Previous</button><button className="button button-quiet" disabled={current >= last} onClick={() => setCurrent((value) => Math.min(last, value + 1))}>Next</button></div></div>}
    {dialog && <ReasonDialog action={dialog.action} busy={busy} onClose={() => setDialog(null)} onSubmit={performAction} />}
    {newCategory && <div className="dialog-backdrop" role="presentation"><form className="dialog-card" onSubmit={createCategory} aria-labelledby="category-title"><button type="button" className="dialog-close" onClick={() => setNewCategory(false)} aria-label="Close">×</button><p className="eyebrow">MARKETPLACE</p><h2 id="category-title">Add a category</h2><label>Category name<input required minLength={2} maxLength={120} value={categoryName} onChange={(event) => setCategoryName(event.target.value)} /></label><label>Description <span className="optional">(optional)</span><textarea maxLength={1000} value={categoryDescription} onChange={(event) => setCategoryDescription(event.target.value)} /></label><div className="dialog-actions"><button type="button" className="button button-quiet" onClick={() => setNewCategory(false)}>Cancel</button><button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Create category'}</button></div></form></div>}
    {editingCategory && <div className="dialog-backdrop" role="presentation"><form className="dialog-card" onSubmit={saveCategory} aria-labelledby="edit-category-title"><button type="button" className="dialog-close" onClick={() => setEditingCategory(null)} aria-label="Close">×</button><p className="eyebrow">MARKETPLACE</p><h2 id="edit-category-title">Edit category</h2><label>Category name<input required minLength={2} maxLength={120} value={categoryName} onChange={(event) => setCategoryName(event.target.value)} /></label><label>Description<textarea maxLength={1000} value={categoryDescription} onChange={(event) => setCategoryDescription(event.target.value)} /></label><label>Reason for change<textarea required minLength={3} maxLength={1000} value={categoryReason} onChange={(event) => setCategoryReason(event.target.value)} /></label><div className="dialog-actions"><button type="button" className="button button-quiet" onClick={() => setEditingCategory(null)}>Cancel</button><button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save changes'}</button></div></form></div>}
  </div>;
}

function ActionButtons({ page, row, open }: { page: PageId; row: ApiRecord; open: (dialog: { row: ApiRecord; action: string }) => void }) {
  if (page === 'customers' || page === 'providers') {
    const suspended = Boolean(row.suspended_at);
    return <button className={`text-action ${suspended ? 'text-green' : 'text-red'}`} onClick={() => open({ row, action: suspended ? 'reactivate' : 'suspend' })}>{suspended ? 'Reactivate' : 'Suspend'}</button>;
  }
  if (page === 'verification') return <div className="action-group"><button className="text-action text-green" onClick={() => open({ row, action: 'approve' })}>Approve</button><button className="text-action text-red" onClick={() => open({ row, action: 'reject' })}>Reject</button></div>;
  if (page === 'services') return <button className="text-action" onClick={() => open({ row, action: row.is_active ? 'deactivate' : 'activate' })}>{row.is_active ? 'Deactivate' : 'Restore'}</button>;
  if (page === 'categories') return <div className="action-group"><button className="text-action" onClick={() => open({ row, action: row.is_active ? 'deactivate category' : 'activate category' })}>{row.is_active ? 'Deactivate' : 'Activate'}</button><button className="text-action" onClick={() => open({ row, action: 'edit category' })}>Edit</button>{Number(row.services_count) === 0 && <button className="text-action text-red" onClick={() => open({ row, action: 'remove category' })}>Delete</button>}</div>;
  if (page === 'reviews') return <button className="text-action text-red" onClick={() => open({ row, action: 'remove review' })}>Remove</button>;
  if (page === 'notifications' && !row.read_at) return <button className="text-action" onClick={() => open({ row, action: 'mark read' })}>Mark read</button>;
  return <span className="muted">—</span>;
}

function emptyFor(page: PageId): string {
  return ({
    customers: 'No customers match these filters.',
    providers: 'No providers match these filters.',
    verification: 'There are no providers awaiting verification.',
    categories: 'Create a category to organize local services.',
    services: 'No services match these filters.',
    bookings: 'No booking records match these filters.',
    reviews: 'No reviews match these filters.',
    notifications: 'No administrator notifications yet.',
    waitlist: 'No registrations match this search.',
    audit: 'No actions have been recorded yet.',
  } as Partial<Record<PageId, string>>)[page] ?? 'No records are available.';
}

function ReasonDialog({ action, busy, onClose, onSubmit }: { action: string; busy: boolean; onClose: () => void; onSubmit: (reason: string) => void }) {
  const [reason, setReason] = useState('');
  const noReasonRequired = action === 'mark read';
  return <div className="dialog-backdrop" role="presentation"><form className="dialog-card" onSubmit={(event) => { event.preventDefault(); onSubmit(reason); }} aria-labelledby="reason-title">
    <button type="button" className="dialog-close" onClick={onClose} aria-label="Close">×</button>
    <p className="eyebrow">ADMIN ACTION</p><h2 id="reason-title">{action[0].toUpperCase() + action.slice(1)}?</h2>
    {noReasonRequired ? <p className="muted">Mark this notification as read?</p> : <><p className="muted">This action will be recorded in the immutable audit log. Add the reason for this decision.</p><label>Reason<textarea autoFocus required minLength={3} maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Write a concise reason…" /></label></>}
    <div className="dialog-actions"><button type="button" className="button button-quiet" onClick={onClose}>Cancel</button><button className={`button ${action.includes('suspend') || action === 'reject' || action === 'remove review' || action === 'deactivate' ? 'button-danger' : 'button-primary'}`} disabled={busy}>{busy ? 'Working…' : 'Confirm action'}</button></div>
  </form></div>;
}

function Reports({ token }: { token: string }) {
  const [data, setData] = useState<ApiRecord | null>(null);
  const [error, setError] = useState('');
  useEffect(() => {
    const abort = new AbortController();
    api<{ data: ApiRecord }>('/admin/reports', token, { signal: abort.signal }).then((result) => setData(result.data))
      .catch((reason: unknown) => { if (!(reason instanceof Error && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Unable to load reports.'); });
    return () => abort.abort();
  }, [token]);
  if (error) return <ErrorPanel message={error} />;
  if (!data) return <Skeleton />;
  const monthly = Array.isArray(data.bookings_by_month) ? data.bookings_by_month as ApiRecord[] : [];
  const verification = Array.isArray(data.provider_verification) ? data.provider_verification as ApiRecord[] : [];
  const interest = Array.isArray(data.waitlist_by_role) ? data.waitlist_by_role as ApiRecord[] : [];
  return <div className="page-stack">
    <div className="report-grid">
      <section className="panel"><div className="panel-heading"><div><p className="eyebrow">DEMAND</p><h2>Monthly bookings</h2></div></div><DataTable rows={monthly} columns={[{ label: 'Month', key: 'month' }, { label: 'Bookings', key: 'bookings' }]} empty="No booking data has been recorded." /></section>
      <section className="panel"><div className="panel-heading"><div><p className="eyebrow">SUPPLY</p><h2>Provider verification</h2></div></div><DataTable rows={verification} columns={[{ label: 'Status', key: 'status' }, { label: 'Providers', key: 'total' }]} empty="No providers have registered." /></section>
      <section className="panel"><div className="panel-heading"><div><p className="eyebrow">PRE-LAUNCH</p><h2>Waitlist interest</h2></div></div><DataTable rows={interest} columns={[{ label: 'Audience', key: 'role' }, { label: 'Registrations', key: 'total' }]} empty="No waitlist registrations yet." /></section>
      <section className="panel payment-report"><div className="panel-heading"><div><p className="eyebrow">PAYMENTS</p><h2>Not active in MVP</h2></div><Status value="not implemented" /></div><p className="muted">No payment transactions or revenue totals are reported. This dashboard will never show a successful payment until a gateway integration is implemented and verified.</p></section>
    </div>
  </div>;
}

function SettingsPage({ token }: { token: string }) {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [error, setError] = useState('');
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    const abort = new AbortController();
    api<{ data: Settings }>('/admin/settings', token, { signal: abort.signal }).then((result) => setSettings(result.data))
      .catch((reason: unknown) => { if (!(reason instanceof Error && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Unable to load settings.'); });
    return () => abort.abort();
  }, [token]);
  if (error) return <ErrorPanel message={error} />;
  if (!settings) return <Skeleton />;
  const toggleGateway = (gateway: string) => setSettings((current) => current ? ({
    ...current,
    payment_gateways: current.payment_gateways.includes(gateway) ? current.payment_gateways.filter((value) => value !== gateway) : [...current.payment_gateways, gateway],
  }) : current);
  async function save(event: FormEvent) {
    event.preventDefault();
    if (!settings) return;
    setBusy(true); setError(''); setSaved(false);
    try {
      const result = await api<{ data: Settings }>('/admin/settings', token, { method: 'PATCH', body: JSON.stringify(settings) });
      setSettings(result.data); setSaved(true);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Could not save settings.');
    } finally { setBusy(false); }
  }
  return <form className="page-stack" onSubmit={save}>
    {saved && <div className="notice" role="status">Platform preferences saved. Payments remain turned off. <button onClick={() => setSaved(false)} type="button" aria-label="Dismiss">×</button></div>}
    {error && <ErrorPanel message={error} />}
    <section className="panel settings-panel"><div className="panel-heading"><div><p className="eyebrow">PRICING</p><h2>Marketplace pricing model</h2></div></div><p className="muted">Choose the pricing approach intended for marketplace configuration. Provider-defined pricing remains the only currently active service-listing behavior.</p><div className="choice-grid">
      {[['provider_defined', 'Provider-defined', 'Professionals set a service price or indicate that a quote is needed.'], ['fixed', 'Fixed price', 'A set amount is shown before a customer submits a request.'], ['customer_quotation', 'Customer quotation', 'Customers request a quote and providers respond with an amount.']].map(([value, title, description]) => <label className={`choice-card ${settings.pricing_model === value ? 'choice-selected' : ''}`} key={value}><input type="radio" name="pricing" value={value} checked={settings.pricing_model === value} onChange={() => setSettings({ ...settings, pricing_model: value as Settings['pricing_model'] })} /><span><strong>{title}</strong><small>{description}</small></span></label>)}
    </div></section>
    <section className="panel settings-panel"><div className="panel-heading"><div><p className="eyebrow">PAYMENT READINESS</p><h2>Gateway preference</h2></div><Status value="payments disabled" /></div><div className="callout">Payment processing is not implemented. Selecting one or both preferred gateways records configuration intent only; it does not activate checkout, charge customers, calculate commissions, issue refunds, or create transactions.</div><div className="gateway-grid">{[['paystack', 'Paystack', 'Nigerian cards, bank transfers and USSD options.'], ['flutterwave', 'Flutterwave', 'Nigerian and broader African payment methods.']].map(([key, name, description]) => <label className={`gateway-card ${settings.payment_gateways.includes(key) ? 'gateway-selected' : ''}`} key={key}><input type="checkbox" checked={settings.payment_gateways.includes(key)} onChange={() => toggleGateway(key)} /><span><strong>{name}</strong><small>{description}</small></span></label>)}</div><p className="muted small">Gateway selection can be none, either provider, or both. The active state remains off until integration, security review, and transaction tests are complete.</p></section>
    <section className="panel settings-panel"><div className="panel-heading"><div><p className="eyebrow">BUSINESS RULES</p><h2>Unconfigured policies</h2></div></div><div className="settings-fields"><label>Commission rate (%)<input type="number" min="0" max="100" step="0.01" placeholder="Not set" value={settings.commission_rate ?? ''} onChange={(event) => setSettings({ ...settings, commission_rate: event.target.value === '' ? null : Number(event.target.value) })} /></label><label>Cancellation fee (₦)<input type="number" min="0" step="0.01" placeholder="Not set" value={settings.cancellation_fee ?? ''} onChange={(event) => setSettings({ ...settings, cancellation_fee: event.target.value === '' ? null : Number(event.target.value) })} /></label></div><label>Refund policy<textarea maxLength={3000} placeholder="No policy configured. Add terms only after review and approval." value={settings.refund_policy ?? ''} onChange={(event) => setSettings({ ...settings, refund_policy: event.target.value || null })} /></label><p className="muted small">Values are left blank by default; Neybor does not assume rates, cancellation fees, or refund terms.</p></section>
    <div className="settings-footer"><span className="muted small">Changes are recorded in the administrator audit log.</span><button className="button button-primary" disabled={busy}>{busy ? 'Saving…' : 'Save preferences'}</button></div>
  </form>;
}

function App() {
  const [token, setToken] = useState(() => localStorage.getItem('neybor-admin-token') ?? '');
  const [user, setUser] = useState<ApiRecord | null>(null);
  const [checking, setChecking] = useState(Boolean(localStorage.getItem('neybor-admin-token')));
  const [page, setPage] = useState<PageId>('overview');
  const [mobileMenu, setMobileMenu] = useState(false);
  const [authError, setAuthError] = useState('');
  useEffect(() => {
    if (!token) { setChecking(false); return; }
    const abort = new AbortController();
    api<{ data: ApiRecord }>('/auth/me', token, { signal: abort.signal })
      .then(({ data }) => {
        if (data.role !== 'administrator') throw new Error('This account does not have administrator access.');
        setUser(data); setAuthError('');
      })
      .catch((reason: unknown) => {
        if (reason instanceof Error && reason.name === 'AbortError') return;
        localStorage.removeItem('neybor-admin-token');
        setToken('');
        setUser(null);
        if (reason instanceof Error && reason.message.includes('administrator')) setAuthError(reason.message);
      })
      .finally(() => setChecking(false));
    return () => abort.abort();
  }, [token]);
  const login = useCallback((nextToken: string, identity: ApiRecord) => {
    setToken(nextToken); setUser(identity); setAuthError('');
  }, []);
  function signOut() {
    void api('/auth/logout', token, { method: 'POST' }).catch(() => undefined);
    localStorage.removeItem('neybor-admin-token');
    setToken(''); setUser(null);
  }
  if (checking) return <main className="login-page"><div className="checking-state"><span className="spinner" />Verifying administrator access…</div></main>;
  if (!token || !user) return <>{authError && <p className="auth-alert" role="alert">{authError}</p>}<Login onLogin={login} /></>;
  const current = headings[page];
  const grouped = [...new Set(sections.map((item) => item.group))];
  return <div className="app-shell">
    <aside className={`sidebar ${mobileMenu ? 'sidebar-open' : ''}`}>
      <a className="brand" href="#overview" onClick={() => { setPage('overview'); setMobileMenu(false); }}><span className="brand-mark">N</span><span><strong>neybor</strong><small>CONTROL ROOM</small></span></a>
      <nav className="side-nav" aria-label="Admin navigation">{grouped.map((group) => <div className="nav-group" key={group}><p className="nav-group-title">{group}</p>{sections.filter((item) => item.group === group).map((item) => <button key={item.id} className={`nav-link ${page === item.id ? 'nav-active' : ''}`} onClick={() => { setPage(item.id); setMobileMenu(false); }}><Icon name={item.icon} size={17} /><span>{item.label}</span>{item.id === 'disputes' && <span className="soon-dot" aria-label="Not active">·</span>}</button>)}</div>)}</nav>
      <div className="sidebar-bottom"><div className="location-note"><span className="location-dot" /><span><strong>Benin City</strong><small>Edo State, Nigeria</small></span></div><button className="nav-link signout" onClick={signOut}><Icon name="logout" size={17} /><span>Sign out</span></button></div>
    </aside>
    {mobileMenu && <button className="mobile-backdrop" aria-label="Close navigation" onClick={() => setMobileMenu(false)} />}
    <main className="main-area">
      <header className="topbar"><button className="mobile-menu-button" onClick={() => setMobileMenu(true)} aria-label="Open navigation">☰</button><div className="crumb">NEYBOR <span>/</span> OPERATIONS</div><div className="topbar-right"><span className="api-indicator"><i /> Live API</span><span className="avatar">{String(user.name ?? 'A').slice(0, 1).toUpperCase()}</span><div className="user-meta"><strong>{text(user.name)}</strong><small>Administrator</small></div></div></header>
      <div className="content-area"><div className="page-heading"><div><p className="eyebrow">{page === 'overview' ? 'TUESDAY, 10 NOVEMBER' : sections.find((item) => item.id === page)?.group.toUpperCase()}</p><h1>{current.title}</h1><p>{current.description}</p></div>{page === 'overview' && <div className="date-chip"><Icon name="calendar" size={16} /> Live overview</div>}</div>
        {page === 'overview' ? <Overview token={token} /> : page === 'reports' ? <Reports token={token} /> : page === 'settings' ? <SettingsPage token={token} /> : page === 'disputes' ? <DisputeEmpty /> : <ListPage key={page} page={page} token={token} />}
      </div>
    </main>
  </div>;
}

function DisputeEmpty() {
  return <section className="panel disputes-empty"><div className="empty-icon large"><Icon name="flag" size={24} /></div><p className="eyebrow">NOT IN MVP</p><h2>Dispute handling is not active yet</h2><p>There is no dispute workflow or dispute data model in the current release. This section stays separate until support policies, resolution steps, and access rules are agreed.</p><span className="subtle-chip">No records or actions available</span></section>;
}

export default App;
