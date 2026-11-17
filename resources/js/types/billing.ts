export type BalanceEntryType = 'signup_credit' | 'top_up' | 'usage';

export type BalanceEntry = {
    id: number;
    type: BalanceEntryType;
    title: string;
    details: string | null;
    amount: string;
    generation_id: string | null;
    created_at: string;
};

export type BillingFilter = 'credits' | 'usage';
