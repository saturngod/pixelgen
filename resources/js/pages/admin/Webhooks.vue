<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ChevronDown, ChevronRight } from "@lucide/vue";
import { ref } from "vue";
import InputError from "@/components/InputError.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { index as adminWebhooksRoute } from "@/routes/admin/webhooks";
import type {
    SimplePaginator,
    WebhookDeliveryRow,
    WebhookOutcome,
} from "@/types";

const props = defineProps<{
    startDate: string | null;
    endDate: string | null;
    outcome: WebhookOutcome | null;
    outcomes: WebhookOutcome[];
    outcomeCounts: Record<WebhookOutcome, number>;
    lastReceivedAt: string | null;
    deliveries: SimplePaginator<WebhookDeliveryRow>;
}>();

const filterForm = useForm({
    start_date: props.startDate ?? "",
    end_date: props.endDate ?? "",
    outcome: props.outcome ?? "",
});

function showFilter() {
    filterForm
        .transform((data) =>
            Object.fromEntries(
                Object.entries(data).filter(([, value]) => value !== ""),
            ),
        )
        .get(adminWebhooksRoute.url(), { preserveScroll: true });
}

function clearFilter() {
    filterForm.start_date = "";
    filterForm.end_date = "";
    filterForm.outcome = "";
    showFilter();
}

function selectOutcome(outcome: WebhookOutcome | null) {
    filterForm.outcome = outcome ?? "";
    showFilter();
}

const openRows = ref<Set<number>>(new Set());

function toggleRow(id: number) {
    const next = new Set(openRows.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    openRows.value = next;
}

const outcomeLabels: Record<WebhookOutcome, string> = {
    processed: "Processed",
    ignored: "Ignored",
    invalid_signature: "Invalid signature",
    invalid_payload: "Invalid payload",
    unknown_generation: "Unknown generation",
};

function outcomeVariant(
    outcome: WebhookOutcome,
): "default" | "secondary" | "destructive" | "outline" {
    switch (outcome) {
        case "processed":
            return "default";
        case "ignored":
            return "secondary";
        case "unknown_generation":
            return "outline";
        default:
            return "destructive";
    }
}

function formatTime(value: string): string {
    return new Date(value).toLocaleString();
}
</script>

<template>
    <Head title="Webhook requests" />

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Webhook requests
                </h1>
                <p class="text-sm text-muted-foreground">
                    Every request to the Pixelforge webhook endpoint and what we
                    did with it. Dates are UTC.
                    <template v-if="lastReceivedAt">
                        Last request {{ formatTime(lastReceivedAt) }}.
                    </template>
                    <template v-else>No requests received yet.</template>
                </p>
            </div>

            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="showFilter"
            >
                <div class="grid gap-1">
                    <Label for="start_date">From</Label>
                    <Input
                        id="start_date"
                        v-model="filterForm.start_date"
                        type="date"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="end_date">To</Label>
                    <Input
                        id="end_date"
                        v-model="filterForm.end_date"
                        type="date"
                        class="w-40"
                    />
                </div>
                <Button type="submit" :disabled="filterForm.processing">
                    Show
                </Button>
                <Button
                    v-if="startDate || endDate || outcome"
                    type="button"
                    variant="ghost"
                    :disabled="filterForm.processing"
                    @click="clearFilter"
                >
                    Clear
                </Button>
            </form>
        </div>

        <div
            v-if="
                filterForm.errors.start_date ||
                filterForm.errors.end_date ||
                filterForm.errors.outcome
            "
            class="-mt-4 flex flex-col items-end"
        >
            <InputError :message="filterForm.errors.start_date" />
            <InputError :message="filterForm.errors.end_date" />
            <InputError :message="filterForm.errors.outcome" />
        </div>

        <nav class="flex flex-wrap gap-1" aria-label="Filter by outcome">
            <Button
                size="sm"
                :variant="outcome === null ? 'secondary' : 'ghost'"
                @click="selectOutcome(null)"
            >
                All
                <span class="text-muted-foreground tabular-nums">{{
                    Object.values(outcomeCounts).reduce((a, b) => a + b, 0)
                }}</span>
            </Button>
            <Button
                v-for="name in outcomes"
                :key="name"
                size="sm"
                :variant="outcome === name ? 'secondary' : 'ghost'"
                @click="selectOutcome(name)"
            >
                {{ outcomeLabels[name] }}
                <span class="text-muted-foreground tabular-nums">{{
                    outcomeCounts[name]
                }}</span>
            </Button>
        </nav>

        <div
            v-if="deliveries.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            No webhook requests match this filter.
        </div>

        <div v-else class="overflow-x-auto rounded-xl border bg-background">
            <table class="w-full text-sm">
                <thead
                    class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2 font-medium">Received</th>
                        <th class="px-4 py-2 font-medium">Outcome</th>
                        <th class="px-4 py-2 font-medium">Event</th>
                        <th class="px-4 py-2 font-medium">Transaction</th>
                        <th class="px-4 py-2 font-medium">IP</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <template
                        v-for="delivery in deliveries.data"
                        :key="delivery.id"
                    >
                        <tr>
                            <td class="px-4 py-2 whitespace-nowrap">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:underline"
                                    :aria-expanded="openRows.has(delivery.id)"
                                    @click="toggleRow(delivery.id)"
                                >
                                    <component
                                        :is="
                                            openRows.has(delivery.id)
                                                ? ChevronDown
                                                : ChevronRight
                                        "
                                        class="size-4"
                                    />
                                    {{ formatTime(delivery.created_at) }}
                                </button>
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="outcomeVariant(delivery.outcome)"
                                >
                                    {{ outcomeLabels[delivery.outcome] }}
                                </Badge>
                                <p
                                    v-if="delivery.reason"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{ delivery.reason }}
                                </p>
                            </td>
                            <td class="px-4 py-2">
                                <p class="font-medium">
                                    {{ delivery.type ?? "—" }}
                                </p>
                                <p
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ delivery.event_id }}
                                </p>
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-xs text-muted-foreground"
                            >
                                {{ delivery.transaction_id ?? "—" }}
                            </td>
                            <td
                                class="px-4 py-2 font-mono text-xs text-muted-foreground"
                            >
                                {{ delivery.ip ?? "—" }}
                            </td>
                        </tr>
                        <tr v-if="openRows.has(delivery.id)" class="bg-muted/30">
                            <td colspan="5" class="px-4 py-3">
                                <pre
                                    v-if="delivery.payload"
                                    class="max-h-96 overflow-auto rounded-lg bg-background p-3 font-mono text-xs"
                                    >{{
                                        JSON.stringify(
                                            delivery.payload,
                                            null,
                                            2,
                                        )
                                    }}</pre
                                >
                                <p v-else class="text-xs text-muted-foreground">
                                    No payload kept. The body of an unsigned
                                    request is not trusted, so it is not stored.
                                </p>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div
            v-if="deliveries.prev_page_url || deliveries.next_page_url"
            class="flex justify-between"
        >
            <Button v-if="deliveries.prev_page_url" as-child variant="outline">
                <Link :href="deliveries.prev_page_url" preserve-scroll
                    >Newer</Link
                >
            </Button>
            <span v-else />
            <Button v-if="deliveries.next_page_url" as-child variant="outline">
                <Link :href="deliveries.next_page_url" preserve-scroll
                    >Older</Link
                >
            </Button>
        </div>
    </div>
</template>
