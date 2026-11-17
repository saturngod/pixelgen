<script setup lang="ts">
import { Link, usePage } from "@inertiajs/vue3";
import { ChartColumn, CreditCard, LogOut, Settings } from "@lucide/vue";
import { computed } from "vue";
import AppLogo from "@/components/AppLogo.vue";
import UserInfo from "@/components/UserInfo.vue";
import { Button } from "@/components/ui/button";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Toaster } from "@/components/ui/sonner";
import { useCurrentUrl } from "@/composables/useCurrentUrl";
import { formatBalance } from "@/lib/utils";
import { getInitials } from "@/composables/useInitials";
import { home, login, logout, register } from "@/routes";
import { dashboard as adminDashboardRoute } from "@/routes/admin";
import { index as billingRoute } from "@/routes/billing";
import { index as historyRoute } from "@/routes/generations";
import { edit as editProfile } from "@/routes/profile";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { router } from "@inertiajs/vue3";

const page = usePage();
const user = computed(() => page.props.auth.user);
const { isCurrentUrl } = useCurrentUrl();
const year = new Date().getFullYear();

function handleLogout(): void {
    router.flushAll();
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-muted/30">
        <header
            class="sticky top-0 z-40 border-b bg-background/80 backdrop-blur"
        >
            <div
                class="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4 sm:px-6"
            >
                <div class="flex items-center gap-6">
                    <Link :href="home()" class="flex items-center">
                        <AppLogo />
                    </Link>

                    <nav v-if="user" class="flex items-center gap-1">
                        <Button
                            as-child
                            size="sm"
                            :variant="
                                isCurrentUrl(home()) ? 'secondary' : 'ghost'
                            "
                        >
                            <Link :href="home()">Create</Link>
                        </Button>
                        <Button
                            as-child
                            size="sm"
                            :variant="
                                isCurrentUrl(historyRoute())
                                    ? 'secondary'
                                    : 'ghost'
                            "
                        >
                            <Link :href="historyRoute()">My images</Link>
                        </Button>
                    </nav>
                </div>

                <div v-if="user" class="flex items-center gap-2">
                    <Link
                        :href="billingRoute()"
                        class="rounded-full border px-3 py-1 text-sm font-medium tabular-nums transition-colors hover:bg-muted"
                        :class="{
                            'border-destructive/40 text-destructive':
                                Number(user.balance) <= 0,
                            'bg-muted': isCurrentUrl(billingRoute()),
                        }"
                        title="Your balance. Open billing."
                    >
                        {{ formatBalance(user.balance) }}
                    </Link>
                    <DropdownMenu>
                        <DropdownMenuTrigger :as-child="true">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-10 w-auto rounded-full p-1"
                            >
                                <Avatar
                                    class="size-8 overflow-hidden rounded-full"
                                >
                                    <AvatarImage
                                        v-if="user.avatar"
                                        :src="user.avatar"
                                        :alt="user.name"
                                    />
                                    <AvatarFallback
                                        class="rounded-lg bg-neutral-200 font-semibold text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ getInitials(user.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <DropdownMenuLabel class="p-0 font-normal">
                                <div
                                    class="flex items-center gap-2 px-1 py-1.5 text-left text-sm"
                                >
                                    <UserInfo :user="user" :show-email="true" />
                                </div>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem :as-child="true">
                                <Link
                                    class="block w-full cursor-pointer"
                                    :href="billingRoute()"
                                >
                                    <CreditCard class="mr-2 h-4 w-4" />
                                    Billing
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem :as-child="true">
                                <Link
                                    class="block w-full cursor-pointer"
                                    :href="editProfile()"
                                >
                                    <Settings class="mr-2 h-4 w-4" />
                                    Settings
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                v-if="user.is_admin"
                                :as-child="true"
                            >
                                <Link
                                    class="block w-full cursor-pointer"
                                    :href="adminDashboardRoute()"
                                >
                                    <ChartColumn class="mr-2 h-4 w-4" />
                                    Admin report
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem :as-child="true">
                                <Link
                                    class="block w-full cursor-pointer"
                                    :href="logout()"
                                    as="button"
                                    data-test="logout-button"
                                    @click="handleLogout"
                                >
                                    <LogOut class="mr-2 h-4 w-4" />
                                    Log out
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>

                <nav v-else class="flex items-center gap-2">
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="login()">Log in</Link>
                    </Button>
                    <Button as-child size="sm">
                        <Link :href="register()">Sign up</Link>
                    </Button>
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-10 sm:px-6">
            <slot />
        </main>

        <footer class="border-t bg-background">
            <div
                class="mx-auto max-w-6xl px-4 py-6 text-sm text-muted-foreground sm:px-6"
            >
                &copy; {{ year }} {{ page.props.name }}
            </div>
        </footer>

        <Toaster />
    </div>
</template>
