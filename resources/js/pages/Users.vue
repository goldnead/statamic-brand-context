<script setup>
import { ref, computed } from 'vue';
import { Head, router } from '@statamic/cms/inertia';
import { Header, Panel, Card, Badge, Button, ConfirmationModal, EmptyStateMenu } from '@statamic/cms/ui';

const props = defineProps([
    'brand',        // { id, name, handle } — the brand in the switcher, the only one this screen writes to
    'users',        // [{ id, name, email, assigned, unassigned_anywhere }]
    'attachUrl',    // POST
    'detachUrl',    // DELETE
    'canManage',    // bool
]);

const t = (key, replacements = {}) => __(`brand-context::messages.${key}`, replacements);

/**
 * A refused assignment must say why. There is no input field on this screen to
 * hang a message on — the id comes from the row, not from typing — so the
 * message is shown twice: inline in the row it belongs to, and in the summary
 * above for anything that names no user at all. Following marketing 1.5.3: an
 * error the server sends and the screen swallows is worse than no error.
 */
const formErrors = ref({});
const failedUserId = ref(null);

const generalErrors = computed(() =>
    Object.entries(formErrors.value)
        .filter(() => ! failedUserId.value)
        .map(([, message]) => message)
);

const rowError = (user) =>
    failedUserId.value === user.id ? formErrors.value.user_id : null;

const busy = ref(null);

/**
 * Removal is confirmed, assignment is not — the same asymmetry core applies.
 * Losing brand access is not recoverable from this screen alone: the user drops
 * out of every brand-scoped listing, and if this was their only membership they
 * fall back to counting as a member of every brand, which is a different state
 * than they were in before. Assigning is additive and trivially undone.
 */
const pendingRemoval = ref(null);

function request(user, remove) {
    busy.value = user.id;
    failedUserId.value = null;
    formErrors.value = {};

    const options = {
        preserveScroll: true,
        onError: (errors) => {
            formErrors.value = errors || {};
            failedUserId.value = errors?.user_id ? user.id : null;
        },
        onFinish: () => { busy.value = null; },
    };

    if (remove) {
        router.delete(props.detachUrl, { ...options, data: { user_id: user.id } });
    } else {
        router.post(props.attachUrl, { user_id: user.id }, options);
    }
}

function toggle(user) {
    if (! props.canManage) return;

    if (user.assigned) {
        pendingRemoval.value = user;

        return;
    }

    request(user, false);
}

function confirmRemoval() {
    const user = pendingRemoval.value;

    if (! user) return;

    pendingRemoval.value = null;
    request(user, true);
}
</script>

<template>
    <Head :title="[t('nav_brand_members'), brand.name]" />

    <div class="max-w-page mx-auto">
        <Header :title="t('nav_brand_members')" :subtitle="brand.name" icon="users" />

        <!--
            The transition rule, where the person acting on it will read it.
            Putting it only in the CHANGELOG would mean an operator sees names
            in a list they never assigned and concludes the filter is broken.
        -->
        <Panel class="mb-4" data-brand-context-transition-note>
            <div class="p-4 text-sm text-gray-600 dark:text-gray-300">
                <p>{{ t('scope_note', { brand: brand.name }) }}</p>
                <p class="mt-2">{{ t('transition_note') }}</p>
            </div>
        </Panel>

        <Panel v-if="generalErrors.length" class="mb-4" data-brand-context-form-errors>
            <div class="p-4 text-sm text-red-600 dark:text-red-400">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
            </div>
        </Panel>

        <EmptyStateMenu
            v-if="! users.length"
            :heading="t('empty_heading')"
            :description="t('empty_description')"
            data-brand-context-empty
        />

        <Panel v-else :heading="t('users_heading')">
            <Card>
                <div class="divide-y divide-gray-200 dark:divide-gray-800">
                    <div
                        v-for="user in users"
                        :key="user.id"
                        class="flex items-center justify-between py-3"
                        :data-brand-user="user.id"
                    >
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium">{{ user.name }}</span>
                                <Badge
                                    v-if="user.assigned"
                                    variant="success"
                                    :text="t('state_member')"
                                    data-brand-user-state="assigned"
                                />
                                <Badge
                                    v-else-if="user.unassigned_anywhere"
                                    :text="t('state_unassigned')"
                                    data-brand-user-state="unassigned"
                                />
                                <Badge
                                    v-else
                                    variant="flat"
                                    :text="t('state_elsewhere')"
                                    data-brand-user-state="elsewhere"
                                />
                            </div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ user.email }}</div>
                            <div
                                v-if="rowError(user)"
                                class="mt-1 text-xs text-red-600 dark:text-red-400"
                                data-brand-user-error
                            >
                                {{ rowError(user) }}
                            </div>
                        </div>

                        <Button
                            v-if="canManage"
                            :text="user.assigned ? t('action_remove') : t('action_assign')"
                            :variant="user.assigned ? 'danger' : 'default'"
                            size="sm"
                            :disabled="busy === user.id"
                            @click="toggle(user)"
                        />
                    </div>
                </div>
            </Card>
        </Panel>

        <ConfirmationModal
            :open="!! pendingRemoval"
            danger
            :title="t('remove_confirm_title', { brand: brand.name })"
            :body-text="pendingRemoval
                ? t('remove_confirm_body', { user: pendingRemoval.name, brand: brand.name })
                : ''"
            :button-text="t('remove_confirm_button')"
            data-brand-context-remove-confirm
            @update:open="(open) => { if (! open) pendingRemoval = null; }"
            @confirm="confirmRemoval"
            @cancel="pendingRemoval = null"
        />
    </div>
</template>
