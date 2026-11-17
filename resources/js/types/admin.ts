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

export type WebhookOutcome =
    | 'processed'
    | 'duplicate'
    | 'ignored'
    | 'invalid_signature'
    | 'invalid_payload'
    | 'unknown_generation';

export type WebhookDeliveryRow = {
    id: number;
    outcome: WebhookOutcome;
    reason: string | null;
    event_id: string | null;
    type: string | null;
    transaction_id: string | null;
    ip: string | null;
    payload: Record<string, unknown> | null;
    created_at: string;
};
