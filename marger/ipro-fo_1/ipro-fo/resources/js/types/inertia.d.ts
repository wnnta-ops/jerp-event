import type { PageProps as InertiaPageProps } from '@inertiajs/core';

declare module '@inertiajs/core' {
    interface PageProps extends InertiaPageProps {
        auth: {
            user: {
                id: number;
                name: string;
                email: string;
                roles: string[];
                permissions: string[];
                tenant_id: number;
                dark_mode: string;
                avatar_url: string | null;
            } | null;
        };
        flash: {
            success?: string;
            error?: string;
            warning?: string;
            info?: string;
            created?: boolean;
        };
        config: {
            currency: string;
            timezone: string;
            ppn_pct: number;
            mfa_roles: string[];
        };
    }
}

declare global {
    function route(name?: string, params?: any, absolute?: boolean): string;
}

// Membuat `route()` tersedia di template Vue (Ziggy global helper)
declare module 'vue' {
    interface ComponentCustomProperties {
        route: (name?: string, params?: any, absolute?: boolean) => string;
    }
}

export {};
