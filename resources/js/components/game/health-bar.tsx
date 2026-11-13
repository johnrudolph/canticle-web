const MAX_HP = 15;

export function HealthBar({
    hp,
    className = '',
}: {
    hp: number;
    className?: string;
}) {
    const pct = Math.max(0, Math.min(100, (hp / MAX_HP) * 100));
    const color =
        hp <= 0
            ? 'bg-destructive'
            : hp <= 5
              ? 'bg-orange-500'
              : 'bg-emerald-500';

    return (
        <div className={`flex items-center gap-2 ${className}`}>
            <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full transition-all ${color}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            <span className="shrink-0 text-xs font-medium tabular-nums">
                {hp}/{MAX_HP}
            </span>
        </div>
    );
}
