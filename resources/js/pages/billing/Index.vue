<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Gift, ImageIcon, Plus, Wallet } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatAmount, formatBalance } from '@/lib/utils';
import { index as billingRoute, store as buyCreditsRoute } from '@/routes/billing';
import type { BalanceEntry, BillingFilter, SimplePaginator } from '@/types';

const props = defineProps<{
    balance: string;
    heldAmount: string;
    type: BillingFilter | null;
    entries: SimplePaginator<BalanceEntry>;
    creditPacks: string[];
}>();

const buyCreditsForm = useForm({ amount: '' });

function buyCredits(amount: string) {
    buyCreditsForm.amount = amount;
    buyCreditsForm.post(buyCreditsRoute.url(), { preserveScroll: true });
}

const filters: { value: BillingFilter | null; label: string }[] = [
    { value: null, label: 'All' },
    { value: 'credits', label: 'Top-ups' },
    { value: 'usage', label: 'Usage' },
];

const hasHeldAmount = computed(() => Number(props.heldAmount) > 0);

const emptyMessage = computed(() => {
    switch (props.type) {
        case 'credits':
            return 'No top-ups yet.';
        case 'usage':
            return 'No images charged yet.';
        default:
            return 'No billing activity yet.';
    }
});

function filterHref(value: BillingFilter | null) {
    return value === null
        ? billingRoute()
        : billingRoute({ query: { type: value } });
}

function entryIcon(entry: BalanceEntry) {
    switch (entry.type) {
        case 'signup_credit':
            return Gift;
        case 'top_up':
            return Wallet;
        default:
            return ImageIcon;
    }
}
</script>

<template>
    <Head title="Billing" />

    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Billing</h1>
            <p class="text-sm text-muted-foreground">
                Your balance, top-ups and what each image cost.
            </p>
        </div>

        <Card>
            <CardHeader>
                <CardDescription>Available balance</CardDescription>
                <CardTitle class="text-4xl tracking-tight tabular-nums">
                    {{ formatBalance(balance) }}
                </CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4">
                <p
                    v-if="hasHeldAmount"
                    class="text-sm text-muted-foreground tabular-nums"
                >
                    {{ formatBalance(heldAmount) }} is on hold for images in
                    progress. You get back whatever they don't use.
                </p>
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium">Buy credits</p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="pack in creditPacks"
                            :key="pack"
                            variant="outline"
                            :disabled="buyCreditsForm.processing"
                            @click="buyCredits(pack)"
                        >
                            <Plus />
                            ${{ pack }}
                        </Button>
                    </div>
                    <InputError :message="buyCreditsForm.errors.amount" />
                    <p class="text-xs text-muted-foreground">
                        Test purchase. No payment is taken.
                    </p>
                </div>
            </CardContent>
        </Card>

        <div class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-4">
                <h2 class="font-semibold">History</h2>
                <nav class="flex gap-1" aria-label="Filter history">
                    <Button
                        v-for="filter in filters"
                        :key="filter.label"
                        as-child
                        size="sm"
                        :variant="type === filter.value ? 'secondary' : 'ghost'"
                    >
                        <Link :href="filterHref(filter.value)" preserve-scroll>
                            {{ filter.label }}
                        </Link>
                    </Button>
                </nav>
            </div>

            <div
                v-if="entries.data.length === 0"
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                {{ emptyMessage }}
            </div>

            <ul
                v-else
                class="divide-y overflow-hidden rounded-xl border bg-background"
            >
                <li
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="flex items-center gap-4 px-4 py-3"
                >
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <component :is="entryIcon(entry)" class="size-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ entry.title }}</p>
                        <p
                            v-if="entry.details"
                            class="truncate text-sm text-muted-foreground"
                        >
                            {{ entry.details }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ new Date(entry.created_at).toLocaleString() }}
                        </p>
                    </div>
                    <p
                        class="shrink-0 text-sm font-medium tabular-nums"
                        :class="
                            entry.amount.startsWith('-')
                                ? 'text-foreground'
                                : 'text-emerald-600 dark:text-emerald-400'
                        "
                        :title="`$${entry.amount}`"
                    >
                        {{ formatAmount(entry.amount) }}
                    </p>
                </li>
            </ul>

            <div
                v-if="entries.prev_page_url || entries.next_page_url"
                class="flex justify-between"
            >
                <Button v-if="entries.prev_page_url" as-child variant="outline">
                    <Link :href="entries.prev_page_url">Newer</Link>
                </Button>
                <span v-else />
                <Button v-if="entries.next_page_url" as-child variant="outline">
                    <Link :href="entries.next_page_url">Older</Link>
                </Button>
            </div>
        </div>
    </div>
</template>
