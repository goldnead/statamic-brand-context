import { describe, it, expect, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import Users from '../../resources/js/pages/Users.vue';
import { router } from './stubs/inertia.js';

const brand = { id: 1, name: 'Acme', handle: 'acme' };

function props(overrides = {}) {
    return {
        brand,
        users: [],
        attachUrl: '/cp/brand-members/attach',
        detachUrl: '/cp/brand-members/detach',
        canManage: true,
        ...overrides,
    };
}

const member = { id: 'a', name: 'Ada', email: 'ada@example.test', assigned: true, unassigned_anywhere: false };
const unassigned = { id: 'b', name: 'Bo', email: 'bo@example.test', assigned: false, unassigned_anywhere: true };
const elsewhere = { id: 'c', name: 'Cy', email: 'cy@example.test', assigned: false, unassigned_anywhere: false };

beforeEach(() => {
    router.post.mockClear();
    router.delete.mockClear();
});

describe('Brand members screen', () => {
    it('shows an empty state instead of an empty bordered box', () => {
        const wrapper = mount(Users, { props: props() });

        expect(wrapper.find('[data-brand-context-empty]').exists()).toBe(true);
        expect(wrapper.find('[data-stub="Card"]').exists()).toBe(false);
    });

    it('renders one row per user with the state badge that row is in', () => {
        const wrapper = mount(Users, { props: props({ users: [member, unassigned, elsewhere] }) });

        expect(wrapper.findAll('[data-brand-user]')).toHaveLength(3);
        expect(wrapper.find('[data-brand-user="a"] [data-brand-user-state="assigned"]').exists()).toBe(true);
        expect(wrapper.find('[data-brand-user="b"] [data-brand-user-state="unassigned"]').exists()).toBe(true);
        expect(wrapper.find('[data-brand-user="c"] [data-brand-user-state="elsewhere"]').exists()).toBe(true);
    });

    it('assigns without a confirmation, because assigning is additive', async () => {
        const wrapper = mount(Users, { props: props({ users: [unassigned] }) });

        await wrapper.find('[data-brand-user="b"] button').trigger('click');

        expect(router.post).toHaveBeenCalledOnce();
        expect(router.post.mock.calls[0][0]).toBe('/cp/brand-members/attach');
        expect(router.post.mock.calls[0][1]).toEqual({ user_id: 'b' });
        expect(wrapper.find('[data-brand-context-remove-confirm]').exists()).toBe(false);
    });

    // Losing brand access is not recoverable from this screen alone, so removal
    // asks first — and must not fire the request before it is confirmed.
    it('asks before removing, and only then sends the delete', async () => {
        const wrapper = mount(Users, { props: props({ users: [member] }) });

        await wrapper.find('[data-brand-user="a"] button').trigger('click');

        expect(router.delete).not.toHaveBeenCalled();
        expect(wrapper.find('[data-brand-context-remove-confirm]').exists()).toBe(true);

        await wrapper.find('[data-brand-context-remove-confirm] [data-stub-confirm]').trigger('click');

        expect(router.delete).toHaveBeenCalledOnce();
        expect(router.delete.mock.calls[0][0]).toBe('/cp/brand-members/detach');
        expect(router.delete.mock.calls[0][1].data).toEqual({ user_id: 'a' });
    });

    it('hides the action entirely for a user without the permission', () => {
        const wrapper = mount(Users, { props: props({ users: [member], canManage: false }) });

        expect(wrapper.find('[data-brand-user="a"] button').exists()).toBe(false);
    });

    // The transition rule is the single most surprising thing about this package.
    // If it ever stops being on the screen, an operator reads the list as broken.
    it('states the transition rule on the screen', () => {
        const wrapper = mount(Users, { props: props({ users: [member] }) });

        expect(wrapper.find('[data-brand-context-transition-note]').text())
            .toContain('brand-context::messages.transition_note');
    });
});
