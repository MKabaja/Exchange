import axios from 'axios';
import { useQuery } from '@tanstack/react-query';
import { cn } from '@/shared/lib/helpers/cn';
import { apiClient } from '@/shared/api/client';

const stats = [
  { label: 'BNB Vault', value: '$225,220', change: '+12.21%', up: true },
  { label: 'BTC Vault', value: '$225,220', change: '+123.21%', up: true },
  { label: 'ETH Vault', value: '$225,220', change: '-123.21%', up: false },
];

const badges = [
  { label: 'PENDING', cls: 'bg-warning/15 text-warning' },
  { label: 'FRAUD_REVIEW', cls: 'bg-warning/15 text-warning' },
  { label: 'COMPLETED', cls: 'bg-success/15 text-success' },
  { label: 'REJECTED', cls: 'bg-danger/15 text-danger' },
];

const typeScale = [
  { cls: 'text-display-2xl', label: 'display-2xl' },
  { cls: 'text-display-xl', label: 'display-xl' },
  { cls: 'text-display-lg', label: 'display-lg' },
  { cls: 'text-display-md', label: 'display-md' },
  { cls: 'text-display-sm', label: 'display-sm' },
  { cls: 'text-body-lg', label: 'body-lg' },
  { cls: 'text-body-md', label: 'body-md' },
  { cls: 'text-body-sm', label: 'body-sm' },
  { cls: 'text-body-xs', label: 'body-xs' },
  { cls: 'text-label', label: 'label' },
];

const palette = [
  { cls: 'bg-bg-deep', label: 'bg-deep' },
  { cls: 'bg-bg-surface', label: 'bg-surface' },
  { cls: 'bg-bg-primary', label: 'bg-primary' },
  { cls: 'bg-bg-offset', label: 'bg-offset' },
  { cls: 'bg-bg-card', label: 'bg-card' },
  { cls: 'bg-bg-elevated', label: 'bg-elevated' },
  { cls: 'bg-accent', label: 'accent' },
  { cls: 'bg-accent-dark', label: 'accent-dark' },
  { cls: 'bg-accent-text', label: 'accent-text' },
  { cls: 'bg-accent-light', label: 'accent-light' },
  { cls: 'bg-success', label: 'success' },
  { cls: 'bg-warning', label: 'warning' },
  { cls: 'bg-danger', label: 'danger' },
  { cls: 'bg-info', label: 'info' },
];

function ApiStatus() {
  const { isPending, data, error } = useQuery({
    queryKey: ['api', 'wallets'],
    queryFn: () => apiClient.get('/wallets').then((res) => res.status),
    retry: false,
  });

  let label = 'Łączenie z backendem…';
  let tone = 'text-text-muted';
  if (!isPending) {
    if (data !== undefined) {
      label = `Backend osiągalny (HTTP ${data})`;
      tone = 'text-accent';
    } else if (axios.isAxiosError(error) && error.response) {
      label = `Backend osiągalny (HTTP ${error.response.status})`;
      tone = 'text-accent';
    } else {
      label = 'Brak połączenia z backendem (uruchom docker compose up -d)';
      tone = 'text-danger';
    }
  }

  return (
    <div className="border-border bg-bg-card flex flex-col gap-2 rounded-xl border p-5">
      <span className="text-body-xs text-text-muted">Proxy /api → backend (Task 1.3)</span>
      <span className={cn('text-body-md font-semibold', tone)}>{label}</span>
    </div>
  );
}

function App() {
  return (
    <main className="bg-bg-primary text-text-primary min-h-screen px-6 py-10">
      <div className="mx-auto flex max-w-5xl flex-col gap-12">
        <header className="flex flex-col gap-1">
          <h1 className="text-display-lg">Hello Ash</h1>
          <p className="text-body-md text-text-muted">
            Widok weryfikacyjny tokenów — paleta, typografia, fonty (Task 1.2).
          </p>
        </header>

        <ApiStatus />

        <section className="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
          {stats.map((s) => (
            <div
              key={s.label}
              className="border-border bg-bg-card flex flex-col gap-3 rounded-xl border p-5 shadow-md"
            >
              <span className="text-body-xs text-text-muted">{s.label}</span>
              <span className="text-display-md">{s.value}</span>
              <span
                className={cn('text-body-sm font-semibold', s.up ? 'text-accent' : 'text-danger')}
              >
                {s.up ? '▲' : '▼'} {s.change} total
              </span>
            </div>
          ))}
        </section>

        <section className="flex flex-col gap-4">
          <h2 className="text-display-sm">Przyciski i statusy</h2>
          <div className="flex flex-wrap items-center gap-3">
            <button
              type="button"
              className="bg-accent text-body-sm hover:bg-accent-dark rounded-md px-4 py-2 font-semibold text-[#062012]"
            >
              Accept
            </button>
            <button
              type="button"
              className="border-border text-body-sm text-text-primary hover:bg-bg-elevated rounded-md border px-4 py-2 font-semibold"
            >
              Reject
            </button>
            {badges.map((b) => (
              <span
                key={b.label}
                className={cn('text-body-xs rounded-full px-2.5 py-1 font-bold', b.cls)}
              >
                {b.label}
              </span>
            ))}
          </div>
        </section>

        <section className="flex flex-col gap-4">
          <h2 className="text-display-sm">Typografia</h2>
          <div className="border-border bg-bg-card flex flex-col gap-3 rounded-xl border p-6">
            {typeScale.map((t) => (
              <div
                key={t.label}
                className="flex items-baseline justify-between gap-4"
              >
                <span className={t.cls}>Kantor 12.5k EUR</span>
                <span className="text-body-xs text-text-muted">{t.label}</span>
              </div>
            ))}
          </div>
        </section>

        <section className="flex flex-col gap-4">
          <h2 className="text-display-sm">Paleta</h2>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 md:grid-cols-7">
            {palette.map((p) => (
              <div
                key={p.label}
                className="flex flex-col gap-2"
              >
                <div className={cn('border-border h-14 rounded-lg border', p.cls)} />
                <span className="text-body-xs text-text-muted">{p.label}</span>
              </div>
            ))}
          </div>
        </section>
      </div>
    </main>
  );
}

export default App;
