import { h } from 'vue';

/**
 * Stand-ins for the `@statamic/cms/ui` components. They are deliberately dumb:
 * the real ones belong to Statamic and are Statamic's to test. What these have
 * to preserve is the contract this package relies on — a `text` prop is
 * rendered, attributes fall through to the root element, slots are shown — so
 * a wrong prop name or a swallowed slot still fails here.
 */
function textual(tag, name) {
    return {
        name,
        props: { text: { type: [String, Number], default: null } },
        setup(props, { slots, attrs }) {
            return () => h(tag, { 'data-stub': name, ...attrs }, [props.text, slots.default?.()]);
        },
    };
}

function container(tag, name) {
    return {
        name,
        props: { heading: { type: String, default: null } },
        setup(props, { slots, attrs }) {
            return () => h(tag, { 'data-stub': name, ...attrs }, [props.heading, slots.default?.()]);
        },
    };
}

export const Button = {
    name: 'Button',
    props: ['text', 'variant', 'size', 'icon', 'iconAppend', 'disabled'],
    emits: ['click'],
    setup(props, { attrs, emit, slots }) {
        return () =>
            h(
                'button',
                {
                    'data-stub': 'Button',
                    disabled: props.disabled || undefined,
                    ...attrs,
                    onClick: (e) => emit('click', e),
                },
                [props.text, slots.default?.()]
            );
    },
};

export const Dropdown = {
    name: 'Dropdown',
    props: ['align', 'offset'],
    setup(props, { slots, attrs }) {
        // The real Dropdown renders its trigger and its menu. Both matter here:
        // the trigger carries the current brand name and the aria-label.
        return () => h('div', { 'data-stub': 'Dropdown', ...attrs }, [slots.trigger?.(), slots.default?.()]);
    },
};
export const DropdownMenu = container('div', 'DropdownMenu');
export const DropdownLabel = textual('div', 'DropdownLabel');
export const DropdownItem = {
    name: 'DropdownItem',
    props: ['text', 'icon'],
    emits: ['click'],
    setup(props, { attrs, emit }) {
        return () =>
            h(
                'button',
                { 'data-stub': 'DropdownItem', 'data-icon': props.icon, ...attrs, onClick: (e) => emit('click', e) },
                props.text
            );
    },
};

export const Header = textual('header', 'Header');
export const Panel = container('section', 'Panel');
export const Card = container('div', 'Card');
export const Badge = textual('span', 'Badge');
export const EmptyStateMenu = {
    name: 'EmptyStateMenu',
    props: ['heading', 'description'],
    setup(props, { attrs }) {
        return () => h('div', { 'data-stub': 'EmptyStateMenu', ...attrs }, [props.heading, props.description]);
    },
};

export const ConfirmationModal = {
    name: 'ConfirmationModal',
    props: ['open', 'danger', 'title', 'bodyText', 'buttonText'],
    emits: ['confirm', 'cancel', 'update:open'],
    setup(props, { attrs, emit }) {
        return () =>
            props.open
                ? h('div', { 'data-stub': 'ConfirmationModal', ...attrs }, [
                    props.title,
                    props.bodyText,
                    h('button', { 'data-stub-confirm': '', onClick: () => emit('confirm') }, props.buttonText),
                ])
                : null;
    },
};
