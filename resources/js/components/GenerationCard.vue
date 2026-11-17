<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    Download,
    ImageOff,
    LoaderCircle,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { destroy, download, show } from '@/routes/generations';
import type { Generation } from '@/types';

/**
 * Shows one generation. The History page polls for updates, so this card makes no network calls,
 * except once to get a fresh signed link when the image fails to load, and when the user deletes it.
 */
const props = defineProps<{
    generation: Generation;
}>();

const emit = defineEmits<{
    updated: [generation: Generation];
}>();

const tickMilliseconds = 500;

/**
 * Stop waiting for the S3 copy after this long. The server keeps retrying in the background.
 */
const imageWaitMilliseconds = 2 * 60 * 1000;

const current = computed(() => props.generation);
const imageFailed = ref(false);
const hasRetriedImage = ref(false);
const now = ref(Date.now());
const http = useHttp();

const isWorking = computed(
    () =>
        current.value.status === 'queued' ||
        current.value.status === 'processing',
);

/**
 * A generation still in progress holds money from the balance, so it can only be deleted once it has finished.
 */
const isDeletable = computed(
    () =>
        current.value.status === 'succeeded' ||
        current.value.status === 'failed',
);
const isDeleting = ref(false);

function deleteGeneration(): void {
    router.delete(destroy(current.value.id), {
        preserveScroll: true,
        onStart: () => {
            isDeleting.value = true;
        },
        onFinish: () => {
            isDeleting.value = false;
        },
    });
}

const isWaitingForImage = computed(() => {
    if (!current.value.image_pending || !current.value.completed_at) {
        return false;
    }

    return (
        now.value - Date.parse(current.value.completed_at) <
        imageWaitMilliseconds
    );
});

/**
 * Pixelforge gives no progress number, so this is an estimate from the status and the time.
 * It never passes 95% until the image has really succeeded.
 */
const progress = computed(() => {
    if (current.value.status === 'succeeded') {
        return 100;
    }

    if (current.value.status === 'queued') {
        const secondsQueued =
            (now.value - Date.parse(current.value.created_at)) / 1000;

        return Math.min(15, 5 + secondsQueued);
    }

    if (current.value.status === 'processing') {
        const startedAt = current.value.started_at ?? current.value.created_at;
        const secondsProcessing = (now.value - Date.parse(startedAt)) / 1000;

        return Math.min(95, 15 + 80 * (1 - Math.exp(-secondsProcessing / 15)));
    }

    return 0;
});

const statusLabel = computed(
    () =>
        ({
            queued: 'Queued',
            processing: 'Generating',
            succeeded: 'Done',
            failed: 'Failed',
        })[current.value.status],
);

const statusVariant = computed(() => {
    if (current.value.status === 'failed') {
        return 'destructive';
    }

    return current.value.status === 'succeeded' ? 'default' : 'secondary';
});

const aspectClass = computed(
    () =>
        ({
            '1024x1024': 'aspect-square',
            '1536x1024': 'aspect-[3/2]',
            '1024x1536': 'aspect-[2/3]',
        })[current.value.size],
);

const createdAtLabel = computed(() =>
    new Date(current.value.created_at).toLocaleString(),
);

/**
 * Show the exact cost without trailing zeros, keeping at least two decimals. No floats are involved.
 */
function formatCost(value: string): string {
    const [whole, fraction = ''] = value.split('.');
    const trimmed = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `$${whole}.${trimmed}`;
}

async function onImageError(): Promise<void> {
    if (hasRetriedImage.value) {
        imageFailed.value = true;

        return;
    }

    // The signed link may have expired. Ask for a fresh one once.
    hasRetriedImage.value = true;

    try {
        emit('updated', (await http.submit(show(current.value.id))) as Generation);
    } catch {
        imageFailed.value = true;
    }
}

let tickTimer: ReturnType<typeof setInterval> | undefined;

/**
 * Move the progress bar and the image wait along locally. This timer makes no requests.
 */
watch(
    () => isWorking.value || current.value.image_pending,
    (shouldTick) => {
        clearInterval(tickTimer);

        if (shouldTick) {
            tickTimer = setInterval(() => {
                now.value = Date.now();
            }, tickMilliseconds);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearInterval(tickTimer);
});
</script>

<template>
    <Card class="overflow-hidden py-0">
        <div
            class="relative flex items-center justify-center bg-muted"
            :class="aspectClass"
        >
            <img
                v-if="current.image_url && !imageFailed"
                :src="current.image_url"
                :alt="current.prompt"
                class="size-full object-cover"
                loading="lazy"
                @error="onImageError"
            />
            <div
                v-else-if="isWorking"
                class="flex w-3/4 flex-col items-center gap-3 text-sm text-muted-foreground"
            >
                <LoaderCircle class="size-6 animate-spin" />
                <div
                    class="h-2 w-full overflow-hidden rounded-full bg-background"
                    role="progressbar"
                    aria-label="Estimated progress"
                    :aria-valuenow="Math.round(progress)"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-[width] duration-500"
                        :style="{ width: `${progress}%` }"
                    />
                </div>
                <span class="tabular-nums">
                    About {{ Math.round(progress) }}%
                </span>
            </div>
            <div
                v-else-if="isWaitingForImage"
                class="flex flex-col items-center gap-2 text-sm text-muted-foreground"
            >
                <LoaderCircle class="size-6 animate-spin" />
                Saving image…
            </div>
            <div
                v-else-if="current.status === 'failed'"
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-destructive"
            >
                <TriangleAlert class="size-6" />
                {{ current.error }}
            </div>
            <div
                v-else
                class="flex flex-col items-center gap-2 p-4 text-center text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                {{
                    current.image_pending
                        ? 'The image is taking longer than usual. Check back later.'
                        : 'Image unavailable'
                }}
            </div>
        </div>

        <CardContent class="flex flex-col gap-3 pb-5">
            <div class="flex items-center justify-between gap-2">
                <Badge :variant="statusVariant">{{ statusLabel }}</Badge>
                <div class="flex items-center gap-1">
                    <span class="text-xs text-muted-foreground">
                        {{ createdAtLabel }}
                    </span>
                    <Button
                        v-if="current.image_url && !imageFailed"
                        as="a"
                        :href="download.url(current.id)"
                        variant="ghost"
                        size="icon"
                        class="size-7 text-muted-foreground hover:text-foreground"
                        aria-label="Download image"
                    >
                        <Download class="size-4" />
                    </Button>
                    <Dialog v-if="isDeletable">
                        <DialogTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-7 text-muted-foreground hover:text-destructive"
                                aria-label="Delete image"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Delete this image?</DialogTitle>
                                <DialogDescription>
                                    The image is removed from your history and
                                    from storage. This cannot be undone. Any
                                    charge stays in your billing history.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter class="gap-2">
                                <DialogClose as-child>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    variant="destructive"
                                    :disabled="isDeleting"
                                    @click="deleteGeneration"
                                >
                                    <LoaderCircle
                                        v-if="isDeleting"
                                        class="animate-spin"
                                    />
                                    Delete image
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>

            <p class="line-clamp-3 text-sm">{{ current.prompt }}</p>

            <dl class="grid grid-cols-2 gap-2 text-xs">
                <div>
                    <dt class="text-muted-foreground">Estimated</dt>
                    <dd class="font-medium tabular-nums">
                        ${{ current.estimated_min_price }} – ${{
                            current.estimated_max_price
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Actual cost</dt>
                    <dd class="font-medium tabular-nums">
                        {{
                            current.actual_cost !== null
                                ? formatCost(current.actual_cost)
                                : '—'
                        }}
                    </dd>
                </div>
            </dl>
        </CardContent>
    </Card>
</template>
