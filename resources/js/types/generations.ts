export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: string;
    prompt: string;
    size: '1024x1024' | '1536x1024' | '1024x1536';
    quality: 'low' | 'medium' | 'high';
    status: GenerationStatus;
    estimated_min_price: string;
    estimated_max_price: string;
    actual_cost: string | null;
    image_url: string | null;
    image_pending: boolean;
    error: string | null;
    created_at: string;
    started_at: string | null;
    completed_at: string | null;
};

export type SimplePaginator<T> = {
    data: T[];
    current_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
};
