<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ArrowLeft, ChartColumn, Webhook } from "@lucide/vue";
import AppLogo from "@/components/AppLogo.vue";
import NavUser from "@/components/NavUser.vue";
import { Separator } from "@/components/ui/separator";
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarTrigger,
} from "@/components/ui/sidebar";
import { Toaster } from "@/components/ui/sonner";
import { useCurrentUrl } from "@/composables/useCurrentUrl";
import { home } from "@/routes";
import { dashboard as adminDashboardRoute } from "@/routes/admin";
import { index as adminWebhooksRoute } from "@/routes/admin/webhooks";

const { isCurrentUrl } = useCurrentUrl();

const navItems = [
    { title: "Report", href: adminDashboardRoute(), icon: ChartColumn },
    { title: "Webhook requests", href: adminWebhooksRoute(), icon: Webhook },
];
</script>

<template>
    <SidebarProvider>
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" as-child>
                            <Link :href="adminDashboardRoute()">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Admin</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <SidebarMenuItem
                                v-for="item in navItems"
                                :key="item.title"
                            >
                                <SidebarMenuButton
                                    as-child
                                    :is-active="isCurrentUrl(item.href)"
                                    :tooltip="item.title"
                                >
                                    <Link :href="item.href">
                                        <component :is="item.icon" />
                                        <span>{{ item.title }}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton as-child tooltip="Back to site">
                            <Link :href="home()">
                                <ArrowLeft />
                                <span>Back to site</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <NavUser />
            </SidebarFooter>
        </Sidebar>

        <SidebarInset>
            <header
                class="flex h-14 shrink-0 items-center gap-2 border-b px-4"
            >
                <SidebarTrigger class="-ml-1" />
                <Separator orientation="vertical" class="mr-2 h-4" />
                <span class="text-sm font-medium">Admin</span>
            </header>
            <main class="flex-1 p-4 sm:p-6">
                <slot />
            </main>
        </SidebarInset>

        <Toaster />
    </SidebarProvider>
</template>
