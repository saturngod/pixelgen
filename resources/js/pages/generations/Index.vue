<script setup lang="ts">
import { Head, Link, router, useHttp } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import { useDocumentVisibility } from '@vueuse/core';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { index, statuses } from '@/routes/generations';
import type { Generation, SimplePaginator } from '@/types';

const props = defineProps<{
    generations: SimplePaginator<Generation>;
}>();

/**
 * Poll quickly at first, then slow down. Generations usually finish within 30 seconds.
 */
const fastPollMilliseconds = 3000;
const slowPollMilliseconds = 10000;
const fastPhaseMilliseconds = 30000;

/**
 * Stop waiting for the S3 copy after this long. The server keeps retrying in the background.
 */
const imageWaitMilliseconds = 2 * 60 * 1000;

const items = ref<Generation[]>([...props.generations.data]);
const visibility = useDocumentVisibility();
const http = useHttp();

let pollTimer: ReturnType<typeof setTimeout> | undefined;
let pollingStartedAt = Date.now();

watch(
    () => props.generations.data,
    (generations) => {
        items.value = [...generations];
        pollingStartedAt = Date.now();
        schedulePoll();
    },
);

function needsUpdate(generation: Generation): boolean {
    if (generation.status === 'queued' || generation.status === 'processing') {
        return true;
    }

    return (
        generation.image_pending &&
        generation.completed_at !== null &&
        Date.now() - Date.parse(generation.completed_at) < imageWaitMilliseconds
    );
}

function pendingIds(): string[] {
    return items.value.filter(needsUpdate).map((generation) => generation.id);
}

function isFinished(generation: Generation): boolean {
    return generation.status === 'succeeded' || generation.status === 'failed';
}

function replace(updated: Generation[]): void {
    const hasJustFinished = updated.some((generation) => {
        const previous = items.value.find((item) => item.id === generation.id);

        return (
            previous !== undefined &&
            !isFinished(previous) &&
            isFinished(generation)
        );
    });

    items.value = items.value.map(
        (item) =>
            updated.find((generation) => generation.id === item.id) ?? item,
    );

    if (hasJustFinished) {
        // The hold was settled, so refresh the balance shown in the header.
        router.reload({ only: ['auth'] });
    }
}

/**
 * Wait, then poll, but only while the tab is visible and a card is unfinished.
 */
function schedulePoll(): void {
    clearTimeout(pollTimer);

    if (visibility.value !== 'visible' || pendingIds().length === 0) {
        return;
    }

    const delay =
        Date.now() - pollingStartedAt < fastPhaseMilliseconds
            ? fastPollMilliseconds
            : slowPollMilliseconds;

    pollTimer = setTimeout(poll, delay);
}

/**
 * Ask for every unfinished card in one request.
 */
async function poll(): Promise<void> {
    clearTimeout(pollTimer);

    const ids = pendingIds();

    if (ids.length > 0) {
        try {
            const response = (await http.submit(
                statuses({ query: { ids } }),
            )) as { generations: Generation[] };

            replace(response.generations);
        } catch {
            // A failed poll is retried on the next round.
        }
    }

    schedulePoll();
}

watch(visibility, (state) => {
    if (state === 'visible') {
        // Catch up at once when the user comes back to the tab.
        void poll();

        return;
    }

    clearTimeout(pollTimer);
});

onMounted(schedulePoll);

onBeforeUnmount(() => {
    clearTimeout(pollTimer);
});
</script>

<template>
    <Head title="History" />

    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">
                    Your images
                </h1>
                <p class="text-sm text-muted-foreground">
                    You can leave this page. Images keep generating and will be
                    here when you come back.
                </p>
            </div>
            <Button as-child>
                <Link :href="home()">
                    <ImagePlus />
                    New image
                </Link>
            </Button>
        </div>

        <div
            v-if="items.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            No images yet. Create your first one.
        </div>

        <div
            v-else
            class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-3"
        >
            <GenerationCard
                v-for="generation in items"
                :key="generation.id"
                :generation="generation"
                @updated="(updated) => replace([updated])"
            />
        </div>

        <div
            v-if="generations.prev_page_url || generations.next_page_url"
            class="flex justify-between"
        >
            <Button v-if="generations.prev_page_url" as-child variant="outline">
                <Link :href="generations.prev_page_url">Newer</Link>
            </Button>
            <span v-else />
            <Button v-if="generations.next_page_url" as-child variant="outline">
                <Link :href="generations.next_page_url">Older</Link>
            </Button>
        </div>
    </div>
</template>
