export const WANDERER_BG: Record<string, string> = {
    wanderer_a: 'bg-blue-600',
    wanderer_b: 'bg-red-600',
    wanderer_c: 'bg-emerald-600',
};

export function RoleAvatar({
    role,
    size = 'sm',
}: {
    role: 'wanderer_a' | 'wanderer_b' | 'wanderer_c' | 'golem';
    size?: 'sm' | 'lg';
}) {
    if (role === 'golem') {
        return (
            <span
                className={`flex items-center justify-center rounded-full bg-black text-white dark:bg-white dark:text-black ${
                    size === 'lg' ? 'size-10 text-2xl' : 'size-6 text-sm'
                }`}
            >
                👺
            </span>
        );
    }

    return (
        <span
            className={`flex items-center justify-center rounded-full text-white ${WANDERER_BG[role] ?? 'bg-gray-600'} ${
                size === 'lg' ? 'size-10 text-xl' : 'size-6 text-xs'
            }`}
        >
            🧑
        </span>
    );
}
