export type AdminReportSummary = {
    generations: number;
    credit_spend: string;
    provider_cost: string;
    profit: string;
};

export type AdminReconciliation = {
    pixelforge_generations: number | null;
    pixelforge_cost: string | null;
    cost_difference: string | null;
};

export type AdminUserRow = AdminReportSummary & {
    user_id: number;
    name: string | null;
    email: string | null;
};

export type AdminDayRow = AdminReportSummary &
    AdminReconciliation & {
        date: string;
        users: AdminUserRow[];
    };

export type AdminTotals = AdminReportSummary & AdminReconciliation;
