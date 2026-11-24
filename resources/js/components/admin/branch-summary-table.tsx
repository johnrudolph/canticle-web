import type { BranchSummary } from '@/types/admin';

/** Branches across the top (as emoji), Relic/Location counts as rows, with totals. */
export function BranchSummaryTable({ summary }: { summary: BranchSummary }) {
    const { branches, emoji, relicCounts, locationCounts } = summary;

    const relicTotal = branches.reduce((sum, b) => sum + (relicCounts[b] ?? 0), 0);
    const locationTotal = branches.reduce(
        (sum, b) => sum + (locationCounts[b] ?? 0),
        0,
    );

    return (
        <div className="overflow-x-auto rounded-md border">
            <table className="text-sm">
                <thead>
                    <tr className="border-b bg-muted/50">
                        <th className="px-3 py-2 text-left font-medium" />
                        {branches.map((branch) => (
                            <th
                                key={branch}
                                className="px-3 py-2 text-left font-medium"
                                title={branch}
                            >
                                {emoji[branch] ?? branch}
                            </th>
                        ))}
                        <th className="px-3 py-2 text-left font-medium">
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr className="border-b last:border-0">
                        <td className="px-3 py-2 font-medium">Relics</td>
                        {branches.map((branch) => (
                            <td key={branch} className="px-3 py-2">
                                {relicCounts[branch] ?? 0}
                            </td>
                        ))}
                        <td className="px-3 py-2 font-medium">
                            {relicTotal}
                        </td>
                    </tr>
                    <tr className="border-b last:border-0">
                        <td className="px-3 py-2 font-medium">Locations</td>
                        {branches.map((branch) => (
                            <td key={branch} className="px-3 py-2">
                                {locationCounts[branch] ?? 0}
                            </td>
                        ))}
                        <td className="px-3 py-2 font-medium">
                            {locationTotal}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
