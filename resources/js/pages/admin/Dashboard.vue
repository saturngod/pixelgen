<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ChevronDown, ChevronRight, TriangleAlert } from "@lucide/vue";
import { ref } from "vue";
import InputError from "@/components/InputError.vue";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { dashboard as adminDashboardRoute } from "@/routes/admin";
import { index as adminWebhooksRoute } from "@/routes/admin/webhooks";
import type { AdminDayRow, AdminTotals, AdminUserRow } from "@/types";

const props = defineProps<{
    startDate: string;
    endDate: string;
    maxDays: number;
    totals: AdminTotals;
    days: AdminDayRow[];
    users: AdminUserRow[];
    pixelforgeError: string | null;
}>();

const rangeForm = useForm({
    start_date: props.startDate,
    end_date: props.endDate,
});

function showRange() {
    rangeForm.get(adminDashboardRoute.url(), { preserveScroll: true });
}

const openDays = ref<Set<string>>(new Set());

function toggleDay(date: string) {
    const next = new Set(openDays.value);

    if (next.has(date)) {
        next.delete(date);
    } else {
        next.add(date);
    }

    openDays.value = next;
}

/**
 * Show dollars with four decimals, such as "$1.2345" or "−$0.0012". The exact amount is in the title.
 */
function usd(amount: string | null): string {
    if (amount === null) {
        return "—";
    }

    const isNegative = amount.startsWith("-");
    const [whole, fraction = ""] = amount.replace("-", "").split(".");

    return `${isNegative ? "−" : ""}$${whole}.${fraction.slice(0, 4).padEnd(4, "0")}`;
}

function isZero(amount: string | null): boolean {
    return amount !== null && /^-?0(\.0*)?$/.test(amount);
}

function differenceClass(row: AdminDayRow | AdminTotals): string {
    if (row.cost_difference === null) {
        return "text-muted-foreground";
    }

    return isZero(row.cost_difference) &&
        row.generations === row.pixelforge_generations
        ? "text-emerald-600 dark:text-emerald-400"
        : "font-medium text-destructive";
}

function profitClass(amount: string): string {
    return amount.startsWith("-") ? "text-destructive" : "";
}

const summaryCards = [
    {
        label: "Credit spend",
        key: "credit_spend",
        hint: "What users were charged",
    },
    {
        label: "Provider cost",
        key: "provider_cost",
        hint: "What Pixelforge charged us",
    },
    {
        label: "Profit",
        key: "profit",
        hint: "Credit spend minus provider cost",
    },
] as const;
</script>

<template>
    <Head title="Admin report" />

    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <nav class="mb-2 flex gap-1" aria-label="Admin pages">
                    <Button as-child size="sm" variant="secondary">
                        <Link :href="adminDashboardRoute()">Report</Link>
                    </Button>
                    <Button as-child size="sm" variant="ghost">
                        <Link :href="adminWebhooksRoute()">Webhooks</Link>
                    </Button>
                </nav>
                <h1 class="text-xl font-semibold tracking-tight">
                    Admin report
                </h1>
                <p class="text-sm text-muted-foreground">
                    Credit spend, provider costs and profit by UTC day and by
                    user. A generation counts on the day it completed, as
                    Pixelforge bills it.
                </p>
            </div>

            <form
                class="flex flex-wrap items-end gap-2"
                @submit.prevent="showRange"
            >
                <div class="grid gap-1">
                    <Label for="start_date">From</Label>
                    <Input
                        id="start_date"
                        v-model="rangeForm.start_date"
                        type="date"
                        class="w-40"
                    />
                </div>
                <div class="grid gap-1">
                    <Label for="end_date">To</Label>
                    <Input
                        id="end_date"
                        v-model="rangeForm.end_date"
                        type="date"
                        class="w-40"
                    />
                </div>
                <Button type="submit" :disabled="rangeForm.processing">
                    Show
                </Button>
            </form>
        </div>

        <div
            v-if="rangeForm.errors.start_date || rangeForm.errors.end_date"
            class="-mt-4 flex flex-col items-end"
        >
            <InputError :message="rangeForm.errors.start_date" />
            <InputError :message="rangeForm.errors.end_date" />
        </div>

        <Alert v-if="pixelforgeError" variant="destructive">
            <TriangleAlert />
            <AlertTitle>Not checked against Pixelforge</AlertTitle>
            <AlertDescription>{{ pixelforgeError }}</AlertDescription>
        </Alert>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card v-for="card in summaryCards" :key="card.key">
                <CardHeader>
                    <CardDescription>{{ card.label }}</CardDescription>
                    <CardTitle
                        class="text-2xl tracking-tight tabular-nums"
                        :class="
                            card.key === 'profit'
                                ? profitClass(totals.profit)
                                : ''
                        "
                        :title="`$${totals[card.key]}`"
                    >
                        {{ usd(totals[card.key]) }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-xs text-muted-foreground">
                    {{ card.hint }} · {{ totals.generations }} images
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardDescription>Pixelforge reported</CardDescription>
                    <CardTitle
                        class="text-2xl tracking-tight tabular-nums"
                        :title="
                            totals.pixelforge_cost
                                ? `$${totals.pixelforge_cost}`
                                : undefined
                        "
                    >
                        {{ usd(totals.pixelforge_cost) }}
                    </CardTitle>
                </CardHeader>
                <CardContent
                    class="text-xs tabular-nums"
                    :class="differenceClass(totals)"
                >
                    <template v-if="totals.cost_difference === null">
                        Not available
                    </template>
                    <template v-else>
                        Difference {{ usd(totals.cost_difference) }} ·
                        {{ totals.pixelforge_generations }} images
                    </template>
                </CardContent>
            </Card>
        </div>

        <section class="flex flex-col gap-3">
            <h2 class="font-semibold">By day</h2>
            <div class="overflow-x-auto rounded-xl border bg-background">
                <table class="w-full text-sm">
                    <thead
                        class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-2 font-medium">Date (UTC)</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Images
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Pixelforge reported
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Difference
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <template v-for="day in days" :key="day.date">
                            <tr
                                :class="
                                    day.generations === 0 &&
                                    !day.pixelforge_generations
                                        ? 'text-muted-foreground'
                                        : ''
                                "
                            >
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <button
                                        v-if="day.users.length > 0"
                                        type="button"
                                        class="inline-flex items-center gap-1 hover:underline"
                                        :aria-expanded="openDays.has(day.date)"
                                        @click="toggleDay(day.date)"
                                    >
                                        <component
                                            :is="
                                                openDays.has(day.date)
                                                    ? ChevronDown
                                                    : ChevronRight
                                            "
                                            class="size-4"
                                        />
                                        {{ day.date }}
                                    </button>
                                    <span v-else class="pl-5">{{
                                        day.date
                                    }}</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">
                                    {{ day.generations }}
                                    <span
                                        v-if="
                                            day.pixelforge_generations !==
                                                null &&
                                            day.pixelforge_generations !==
                                                day.generations
                                        "
                                        class="text-destructive"
                                        title="Images Pixelforge billed"
                                    >
                                        / {{ day.pixelforge_generations }}
                                    </span>
                                </td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :title="`$${day.credit_spend}`"
                                >
                                    {{ usd(day.credit_spend) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :title="`$${day.provider_cost}`"
                                >
                                    {{ usd(day.provider_cost) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :title="
                                        day.pixelforge_cost
                                            ? `$${day.pixelforge_cost}`
                                            : undefined
                                    "
                                >
                                    {{ usd(day.pixelforge_cost) }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :class="differenceClass(day)"
                                    :title="
                                        day.cost_difference
                                            ? `$${day.cost_difference}`
                                            : undefined
                                    "
                                >
                                    {{
                                        day.cost_difference === null
                                            ? "—"
                                            : isZero(day.cost_difference)
                                              ? "Match"
                                              : usd(day.cost_difference)
                                    }}
                                </td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :class="profitClass(day.profit)"
                                    :title="`$${day.profit}`"
                                >
                                    {{ usd(day.profit) }}
                                </td>
                            </tr>
                            <template v-if="openDays.has(day.date)">
                                <tr
                                    v-for="user in day.users"
                                    :key="`${day.date}-${user.user_id}`"
                                    class="bg-muted/30 text-xs"
                                >
                                    <td class="py-1.5 pr-4 pl-10">
                                        {{
                                            user.name ?? `User #${user.user_id}`
                                        }}
                                        <span class="text-muted-foreground">{{
                                            user.email
                                        }}</span>
                                    </td>
                                    <td
                                        class="px-4 py-1.5 text-right tabular-nums"
                                    >
                                        {{ user.generations }}
                                    </td>
                                    <td
                                        class="px-4 py-1.5 text-right tabular-nums"
                                    >
                                        {{ usd(user.credit_spend) }}
                                    </td>
                                    <td
                                        class="px-4 py-1.5 text-right tabular-nums"
                                    >
                                        {{ usd(user.provider_cost) }}
                                    </td>
                                    <td class="px-4 py-1.5" />
                                    <td class="px-4 py-1.5" />
                                    <td
                                        class="px-4 py-1.5 text-right tabular-nums"
                                        :class="profitClass(user.profit)"
                                    >
                                        {{ usd(user.profit) }}
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="flex flex-col gap-3">
            <h2 class="font-semibold">By user</h2>
            <div
                v-if="users.length === 0"
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                No images completed in this range.
            </div>
            <div v-else class="overflow-x-auto rounded-xl border bg-background">
                <table class="w-full text-sm">
                    <thead
                        class="border-b bg-muted/50 text-left text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-4 py-2 font-medium">User</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Images
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Credit spend
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Provider cost
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Profit
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="user in users" :key="user.user_id">
                            <td class="px-4 py-2">
                                <p class="font-medium">
                                    {{ user.name ?? `User #${user.user_id}` }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ user.email }}
                                </p>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums">
                                {{ user.generations }}
                            </td>
                            <td
                                class="px-4 py-2 text-right tabular-nums"
                                :title="`$${user.credit_spend}`"
                            >
                                {{ usd(user.credit_spend) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right tabular-nums"
                                :title="`$${user.provider_cost}`"
                            >
                                {{ usd(user.provider_cost) }}
                            </td>
                            <td
                                class="px-4 py-2 text-right tabular-nums"
                                :class="profitClass(user.profit)"
                                :title="`$${user.profit}`"
                            >
                                {{ usd(user.profit) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
