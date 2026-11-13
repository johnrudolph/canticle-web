export type User = {
    id: number;
    name: string;
    email: string;
    is_guest: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};
