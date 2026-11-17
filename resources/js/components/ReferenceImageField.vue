<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ImagePlus, LoaderCircle, X } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { store as uploadRoute } from '@/routes/generations/reference-images';

type Item = {
    id: number;
    previewUrl: string;
    url: string | null;
    error: string | null;
};

const maxFileBytes = 5 * 1024 * 1024;
const acceptedTypes = ['image/png', 'image/jpeg', 'image/webp'];

const {
    max,
    canUpload,
    errors = {},
} = defineProps<{
    max: number;
    canUpload: boolean;
    errors?: Record<string, string | undefined>;
}>();

const urls = defineModel<string[]>({ required: true });
const uploading = defineModel<boolean>('uploading', { default: false });

const items = ref<Item[]>([]);
const isDragging = ref(false);
const message = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const http = useHttp<{ image: File | null }, { url: string }>({ image: null });

let nextId = 1;
const queue: { item: Item; file: File }[] = [];
let isUploading = false;

function sync(): void {
    urls.value = items.value.flatMap((item) => (item.url ? [item.url] : []));
    uploading.value = items.value.some(
        (item) => item.url === null && item.error === null,
    );
}

function addFiles(files: File[]): void {
    message.value = null;

    if (!canUpload) {
        message.value = 'Log in to upload reference images.';

        return;
    }

    for (const file of files) {
        if (items.value.length >= max) {
            message.value = `You can add up to ${max} images.`;

            break;
        }

        if (!acceptedTypes.includes(file.type)) {
            message.value = 'Only PNG, JPG or WebP images are allowed.';

            continue;
        }

        if (file.size > maxFileBytes) {
            message.value = 'Each image must be 5 MB or smaller.';

            continue;
        }

        const item: Item = {
            id: nextId++,
            previewUrl: URL.createObjectURL(file),
            url: null,
            error: null,
        };

        items.value.push(item);
        queue.push({ item: items.value[items.value.length - 1], file });
    }

    sync();
    void uploadNext();
}

/**
 * Upload one file at a time, so the shared request object is never used twice at once.
 */
async function uploadNext(): Promise<void> {
    if (isUploading) {
        return;
    }

    isUploading = true;

    for (let next = queue.shift(); next; next = queue.shift()) {
        const { item, file } = next;

        if (!items.value.includes(item)) {
            continue;
        }

        try {
            http.image = file;
            const response = await http.submit(uploadRoute());

            item.url = response.url;
        } catch {
            item.error = http.errors.image ?? 'Upload failed. Try again.';
        }

        sync();
    }

    isUploading = false;
}

function remove(item: Item): void {
    items.value = items.value.filter((candidate) => candidate !== item);
    URL.revokeObjectURL(item.previewUrl);
    message.value = null;
    sync();
}

function onSelect(event: Event): void {
    const input = event.target as HTMLInputElement;

    addFiles(Array.from(input.files ?? []));
    input.value = '';
}

function onDrop(event: DragEvent): void {
    isDragging.value = false;
    addFiles(Array.from(event.dataTransfer?.files ?? []));
}

// The form was reset from outside, so forget the previews too.
watch(urls, (value) => {
    if (value.length === 0 && items.value.every((item) => item.url !== null)) {
        items.value.forEach((item) => URL.revokeObjectURL(item.previewUrl));
        items.value = [];
    }
});

onBeforeUnmount(() => {
    items.value.forEach((item) => URL.revokeObjectURL(item.previewUrl));
});
</script>

<template>
    <section class="flex flex-col gap-3">
        <div class="flex items-center justify-between">
            <div class="flex flex-col gap-1">
                <Label>Reference images</Label>
                <p class="text-xs text-muted-foreground">
                    Optional. Up to {{ max }} images (PNG, JPG or WebP, 5 MB
                    each).
                </p>
            </div>
            <span class="text-xs text-muted-foreground tabular-nums">
                {{ items.length }} / {{ max }}
            </span>
        </div>

        <ul
            v-if="items.length > 0"
            class="grid grid-cols-2 gap-3 sm:grid-cols-4"
        >
            <li
                v-for="(item, index) in items"
                :key="item.id"
                class="flex flex-col gap-1.5"
            >
                <div
                    class="group relative aspect-square overflow-hidden rounded-lg border bg-muted"
                    :class="{ 'border-destructive': item.error }"
                >
                    <img
                        :src="item.previewUrl"
                        :alt="`Reference image ${index + 1}`"
                        class="size-full object-cover"
                        :class="{ 'opacity-50': item.url === null }"
                    />
                    <div
                        v-if="item.url === null && item.error === null"
                        class="absolute inset-0 flex items-center justify-center"
                    >
                        <LoaderCircle class="size-6 animate-spin" />
                    </div>
                    <button
                        type="button"
                        class="absolute top-1.5 right-1.5 rounded-full bg-background/90 p-1 shadow-sm hover:bg-background"
                        :aria-label="`Remove reference image ${index + 1}`"
                        @click="remove(item)"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <InputError
                    :message="item.error ?? errors[`reference_images.${index}`]"
                />
            </li>
        </ul>

        <label
            v-if="items.length < max"
            class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border border-dashed p-6 text-center text-sm transition-colors hover:bg-muted/50 focus-within:ring-[3px] focus-within:ring-ring/50"
            :class="{ 'border-primary bg-muted/50': isDragging }"
            @dragover.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
        >
            <ImagePlus class="size-6 text-muted-foreground" />
            <span class="font-medium">
                Drop images here or click to upload
            </span>
            <input
                ref="fileInput"
                type="file"
                class="sr-only"
                multiple
                accept="image/png,image/jpeg,image/webp"
                @change="onSelect"
            />
        </label>

        <InputError :message="message ?? undefined" />
    </section>
</template>
