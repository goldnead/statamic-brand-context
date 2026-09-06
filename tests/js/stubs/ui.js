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

// Alert carries both a heading and a body, and this package uses both: the
// scope note and the transition rule are two separate statements.
export const Alert = {
    name: 'Alert',
    props: {
        text: { type: [String, Number], default: null },
        heading: { type: String, default: null },
        variant: { type: String, default: 'default' },
    },
    setup(props, { slots, attrs }) {
        return () => h('div', { 'data-stub': 'Alert', 'data-variant': props.variant, ...attrs }, [
            props.heading,
            props.heading && props.text ? ' ' : null,
            props.text,
            slots.default?.(),
        ]);
    },
};
export const Header = textual('header', 'Header');
export const Panel = container('section', 'Panel');
export const Card = container('div', 'Card');
export const Badge = textual('span', 'Badge');
export const EmptyStateMenu = {
    name: 'EmptyStateMenu',
    props: ['heading', 'description'],
    // The slot is rendered, unlike the first version of this stub, which
    // dropped it. A stub that swallows a slot cannot fail when the component
    // under test puts something in one — and an empty state whose only content
    // is in that slot would have passed while rendering an empty box.
    setup(props, { attrs, slots }) {
        return () => h('div', { 'data-stub': 'EmptyStateMenu', ...attrs }, [
            props.heading,
            props.description,
            slots.default?.(),
        ]);
    },
};

export const EmptyStateItem = {
    name: 'EmptyStateItem',
    props: ['heading', 'description', 'icon', 'href', 'target'],
    setup(props, { attrs }) {
        return () => h('a', { 'data-stub': 'EmptyStateItem', href: props.href, ...attrs }, [
            props.heading,
            props.description,
        ]);
    },
};

export const Icon = {
    name: 'Icon',
    props: ['name'],
    setup(props, { attrs }) {
        return () => h('i', { 'data-stub': 'Icon', 'data-icon': props.name, ...attrs });
    },
};

export const Field = {
    name: 'Field',
    props: ['label', 'instructions'],
    setup(props, { attrs, slots }) {
        return () => h('div', { 'data-stub': 'Field', ...attrs }, [
            props.label,
            props.instructions,
            slots.default?.(),
        ]);
    },
};

/**
 * The three form controls, as `v-model`-shaped as the real ones: they render
 * the value they were given and emit `update:modelValue` on input. A stub that
 * only rendered would let a component pass while never writing anything back.
 */
function control(tag, name, type = null) {
    return {
        name,
        props: ['modelValue', 'type', 'rows', 'placeholder', 'inputAttrs', 'disabled'],
        emits: ['update:modelValue'],
        setup(props, { attrs, emit }) {
            return () => h(tag, {
                'data-stub': name,
                type: type ?? props.type ?? 'text',
                value: props.modelValue,
                ...attrs,
                onInput: (e) => emit('update:modelValue', e.target.value),
            });
        },
    };
}

export const Input = control('input', 'Input');
export const Textarea = control('textarea', 'Textarea');

export const Select = {
    name: 'Select',
    props: ['modelValue', 'options', 'placeholder', 'adaptiveWidth', 'disabled'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
        return () => h('select', {
            'data-stub': 'Select',
            value: props.modelValue,
            ...attrs,
            onChange: (e) => emit('update:modelValue', e.target.value),
        }, (props.options ?? []).map((o) => h('option', { value: o.value }, o.label)));
    },
};

export const Switch = {
    name: 'Switch',
    props: ['modelValue', 'disabled'],
    emits: ['update:modelValue'],
    setup(props, { attrs, emit }) {
        return () => h('button', {
            'data-stub': 'Switch',
            'data-checked': props.modelValue ? 'true' : 'false',
            ...attrs,
            onClick: () => emit('update:modelValue', ! props.modelValue),
        });
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
