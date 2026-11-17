<script setup lang="ts">
import { Head, Link, router, useForm, useHttp, usePage } from '@inertiajs/vue3';
import { Info, Sparkles, TriangleAlert } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { computed, reactive, ref, watch } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import InputError from '@/components/InputError.vue';
import ReferenceImageField from '@/components/ReferenceImageField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { formatBalance } from '@/lib/utils';
import { home, login, register } from '@/routes';
import {
    estimate as estimateRoute,
    index as historyRoute,
    store as storeRoute,
} from '@/routes/generations';

type Size = '1024x1024' | '1536x1024' | '1024x1536';
type Quality = 'low' | 'medium' | 'high';

type EstimatePayload = {
    prompt: string;
    size: Size;
    quality: Quality;
    reference_images: string[];
};

type Estimate = {
    currency: string;
    min_price: string;
    max_price: string;
};

const maxPromptLength = 4000;
const maxReferenceImages = 4;
const estimateDebounceMilliseconds = 300;

const sizeOptions: {
    value: Size;
    label: string;
    hint: string;
    shapeClass: string;
}[] = [
    {
        value: '1024x1024',
        label: 'Square',
        hint: '1024 × 1024',
        shapeClass: 'h-7 w-7',
    },
    {
        value: '1536x1024',
        label: 'Landscape',
        hint: '1536 × 1024',
        shapeClass: 'h-6 w-9',
    },
    {
        value: '1024x1536',
        label: 'Portrait',
        hint: '1024 × 1536',
        shapeClass: 'h-9 w-6',
    },
];

const qualityOptions: { value: Quality; label: string; hint: string }[] = [
    { value: 'low', label: 'Low', hint: 'Fast drafts' },
    { value: 'medium', label: 'Medium', hint: 'Balanced' },
    { value: 'high', label: 'High', hint: 'Best detail' },
];

const form = reactive<{
    prompt: string;
    size: Size;
    quality: Quality;
    referenceImages: string[];
}>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    referenceImages: [],
});

const promptLength = computed(() => [...form.prompt].length);
const hasPrompt = computed(() => form.prompt.trim().length > 0);
const isUploadingReferenceImage = ref(false);
const selectedSize = computed(
    () => sizeOptions.find((option) => option.value === form.size)!,
);
const selectedQuality = computed(
    () => qualityOptions.find((option) => option.value === form.quality)!,
);

const estimateRequest = useHttp<EstimatePayload, Estimate>({
    prompt: '',
    size: form.size,
    quality: form.quality,
    reference_images: [],
});
const estimate = ref<Estimate | null>(null);
const estimateUnavailableMessage = ref<string | null>(null);
const isEstimating = computed(() => estimateRequest.processing);
const generateForm = useForm<EstimatePayload>({
    prompt: '',
    size: form.size,
    quality: form.quality,
    reference_images: [],
});
const page = usePage();
const isLoggedIn = computed(() => !!page.props.auth.user);
const balance = computed(() => page.props.auth.user?.balance ?? null);

/**
 * A hint only. The server checks the balance again before it holds any money.
 */
const isBalanceTooLow = computed(
    () =>
        balance.value !== null &&
        estimate.value !== null &&
        Number(balance.value) < Number(estimate.value.max_price),
);
const validationErrors = computed(
    () =>
        ({
            ...estimateRequest.errors,
            ...generateForm.errors,
        }) as Record<string, string | undefined>,
);
const generationError = computed(
    () =>
        (generateForm.errors as Record<string, string | undefined>).generation,
);

const fetchEstimate = useDebounceFn(() => {
    if (!hasPrompt.value) {
        return;
    }

    estimateRequest.cancel();
    estimateRequest.prompt = form.prompt;
    estimateRequest.size = form.size;
    estimateRequest.quality = form.quality;
    estimateRequest.reference_images = form.referenceImages;

    estimateRequest
        .post(estimateRoute.url(), {
            onSuccess: (response) => {
                estimate.value = response;
                estimateUnavailableMessage.value = null;
            },
            onError: () => {
                estimate.value = null;
            },
            onHttpException: () => {
                estimate.value = null;
                estimateUnavailableMessage.value =
                    'Pricing is unavailable right now. Please try again shortly.';
            },
        })
        .catch(() => {
            // Handled by the callbacks above, or the request was cancelled.
        });
}, estimateDebounceMilliseconds);

watch(
    () => [form.prompt, form.size, form.quality, form.referenceImages],
    () => {
        if (hasPrompt.value) {
            fetchEstimate();

            return;
        }

        estimateRequest.cancel();
        estimateRequest.clearErrors();
        estimate.value = null;
        estimateUnavailableMessage.value = null;
    },
);

function submit(): void {
    if (!isLoggedIn.value) {
        router.visit(login());

        return;
    }

    if (generateForm.processing) {
        return;
    }

    generateForm.prompt = form.prompt;
    generateForm.size = form.size;
    generateForm.quality = form.quality;
    generateForm.reference_images = form.referenceImages;

    generateForm.submit(storeRoute(), { preserveScroll: true });
}
</script>

<template>
    <div>
        <Head title="Create an image" />

        <div class="mb-8 max-w-2xl">
            <Badge variant="secondary" class="mb-3">
                <Sparkles />
                AI image generation
            </Badge>
            <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                Describe it. We'll draw it.
            </h1>
            <p class="mt-2 text-muted-foreground">
                Write a prompt, pick a size and quality, and see the price
                before you generate.
            </p>
        </div>

        <form
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]"
            @submit.prevent="submit"
        >
            <Card>
                <CardContent class="flex flex-col gap-8">
                    <section class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <Label for="prompt">Prompt</Label>
                            <span
                                class="text-xs tabular-nums"
                                :class="
                                    promptLength > maxPromptLength
                                        ? 'text-destructive'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ promptLength.toLocaleString() }} /
                                {{ maxPromptLength.toLocaleString() }}
                            </span>
                        </div>
                        <Textarea
                            id="prompt"
                            v-model="form.prompt"
                            :maxlength="maxPromptLength"
                            rows="6"
                            class="min-h-36 resize-y"
                            placeholder="A cozy reading nook by a rainy window, warm lamp light, watercolor style…"
                            :aria-invalid="!!validationErrors.prompt"
                        />
                        <InputError :message="validationErrors.prompt" />
                    </section>

                    <section class="flex flex-col gap-3">
                        <Label>Size</Label>
                        <RadioGroupRoot
                            v-model="form.size"
                            class="grid grid-cols-3 gap-3"
                            aria-label="Size"
                        >
                            <RadioGroupItem
                                v-for="option in sizeOptions"
                                :key="option.value"
                                :value="option.value"
                                class="flex flex-col items-center gap-3 rounded-lg border bg-background p-4 text-center transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[state=checked]:border-primary data-[state=checked]:bg-accent"
                            >
                                <span class="flex h-10 items-center">
                                    <span
                                        class="rounded-sm border-2 border-current text-muted-foreground"
                                        :class="option.shapeClass"
                                    />
                                </span>
                                <span class="flex flex-col gap-0.5">
                                    <span class="text-sm font-medium">
                                        {{ option.label }}
                                    </span>
                                    <span
                                        class="text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ option.hint }}
                                    </span>
                                </span>
                            </RadioGroupItem>
                        </RadioGroupRoot>
                    </section>

                    <section class="flex flex-col gap-3">
                        <Label>Quality</Label>
                        <RadioGroupRoot
                            v-model="form.quality"
                            class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1"
                            aria-label="Quality"
                        >
                            <RadioGroupItem
                                v-for="option in qualityOptions"
                                :key="option.value"
                                :value="option.value"
                                class="flex flex-col items-center rounded-md px-3 py-2 text-sm transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 data-[state=checked]:bg-background data-[state=checked]:shadow-sm"
                            >
                                <span class="font-medium">
                                    {{ option.label }}
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ option.hint }}
                                </span>
                            </RadioGroupItem>
                        </RadioGroupRoot>
                    </section>

                    <ReferenceImageField
                        v-model="form.referenceImages"
                        v-model:uploading="isUploadingReferenceImage"
                        :max="maxReferenceImages"
                        :can-upload="isLoggedIn"
                        :errors="validationErrors"
                    />
                </CardContent>
            </Card>

            <div class="lg:sticky lg:top-6 lg:self-start">
                <Card>
                    <CardHeader>
                        <CardTitle>Estimated cost</CardTitle>
                        <CardDescription>
                            Updates as you change your prompt and options.
                        </CardDescription>
                    </CardHeader>

                    <CardContent class="flex flex-col gap-5">
                        <div>
                            <template v-if="!hasPrompt">
                                <p
                                    class="text-4xl font-semibold tracking-tight text-muted-foreground/50"
                                >
                                    $—
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Write a prompt to see the price.
                                </p>
                            </template>
                            <div
                                v-else-if="estimate"
                                class="transition-opacity"
                                :class="{ 'opacity-50': isEstimating }"
                                aria-live="polite"
                            >
                                <p class="text-sm text-muted-foreground">
                                    Up to
                                </p>
                                <p
                                    class="text-4xl font-semibold tracking-tight tabular-nums"
                                >
                                    ${{ estimate.max_price }}
                                </p>
                                <p
                                    v-if="
                                        estimate.min_price !==
                                        estimate.max_price
                                    "
                                    class="mt-1 text-sm text-muted-foreground tabular-nums"
                                >
                                    Could be as low as ${{ estimate.min_price }}
                                </p>
                            </div>
                            <p
                                v-else-if="
                                    estimateRequest.hasErrors && !isEstimating
                                "
                                class="text-sm text-muted-foreground"
                            >
                                Fix the highlighted fields to see the price.
                            </p>
                            <p
                                v-else-if="
                                    estimateUnavailableMessage && !isEstimating
                                "
                                class="flex gap-2 text-sm text-destructive"
                            >
                                <TriangleAlert class="mt-px size-4 shrink-0" />
                                {{ estimateUnavailableMessage }}
                            </p>
                            <div v-else class="flex flex-col gap-2">
                                <div
                                    class="h-10 w-32 animate-pulse rounded-md bg-muted"
                                />
                                <div
                                    class="h-4 w-40 animate-pulse rounded bg-muted"
                                />
                            </div>
                        </div>

                        <Separator />

                        <dl class="flex flex-col gap-2.5 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Size</dt>
                                <dd class="font-medium">
                                    {{ selectedSize.label }}
                                    <span
                                        class="text-muted-foreground tabular-nums"
                                    >
                                        · {{ selectedSize.hint }}
                                    </span>
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">Quality</dt>
                                <dd class="font-medium">
                                    {{ selectedQuality.label }}
                                </dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    Reference images
                                </dt>
                                <dd class="font-medium tabular-nums">
                                    {{ form.referenceImages.length }}
                                </dd>
                            </div>
                            <div
                                v-if="balance !== null"
                                class="flex justify-between"
                            >
                                <dt class="text-muted-foreground">
                                    Your balance
                                </dt>
                                <dd
                                    class="font-medium tabular-nums"
                                    :class="{
                                        'text-destructive': isBalanceTooLow,
                                    }"
                                >
                                    {{ formatBalance(balance) }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>

                    <CardFooter class="flex flex-col gap-3">
                        <p
                            v-if="generationError"
                            class="flex w-full gap-2 text-sm text-destructive"
                            role="alert"
                        >
                            <TriangleAlert class="mt-px size-4 shrink-0" />
                            {{ generationError }}
                        </p>
                        <p
                            v-else-if="isBalanceTooLow"
                            class="flex w-full gap-2 text-sm text-destructive"
                        >
                            <TriangleAlert class="mt-px size-4 shrink-0" />
                            Your balance is too low for this image. Try a
                            smaller size or lower quality.
                        </p>
                        <Button
                            type="submit"
                            size="lg"
                            class="w-full"
                            :disabled="
                                !estimate ||
                                isBalanceTooLow ||
                                isEstimating ||
                                isUploadingReferenceImage ||
                                generateForm.processing
                            "
                        >
                            <Sparkles />
                            {{
                                !isLoggedIn
                                    ? 'Log in to generate'
                                    : generateForm.processing
                                      ? 'Starting…'
                                      : 'Generate image'
                            }}
                        </Button>
                        <p class="flex gap-1.5 text-xs text-muted-foreground">
                            <Info class="mt-px size-3.5 shrink-0" />
                            You're only charged if the image is generated
                            successfully.
                        </p>
                    </CardFooter>
                </Card>
            </div>
        </form>
    </div>
</template>
