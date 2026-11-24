import type { BranchSummary } from '@/types/admin';

/** Per-branch Relic count side by side with Location count, with totals. */
export function BranchSummaryTable({ summary }: { summary: BranchSummary }) {
    const { branches, relicCounts, locationCounts } = summary;

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
                        <th className="px-3 py-2 text-left font-medium">
                            Branch
                        </th>
                        <th className="px-3 py-2 text-left font-medium">
                            Relics
                        </th>
                        <th className="px-3 py-2 text-left font-medium">
                            Locations
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {branches.map((branch) => (
                        <tr key={branch} className="border-b last:border-0">
                            <td className="px-3 py-2 font-medium">
                                {branch}
                            </td>
                            <td className="px-3 py-2">
                                {relicCounts[branch] ?? 0}
                            </td>
                            <td className="px-3 py-2">
                                {locationCounts[branch] ?? 0}
                            </td>
                        </tr>
                    ))}
                    <tr className="bg-muted/50">
                        <td className="px-3 py-2 font-medium">Total</td>
                        <td className="px-3 py-2 font-medium">
                            {relicTotal}
                        </td>
                        <td className="px-3 py-2 font-medium">
                            {locationTotal}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
