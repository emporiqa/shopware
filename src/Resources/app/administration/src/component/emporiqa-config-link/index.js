import template from './emporiqa-config-link.html.twig';

const { Component } = Shopware;

// Rendered inside the native plugin configuration page (config.xml <component>),
// points merchants to the full Emporiqa page for connect, languages and sync.
Component.register('emporiqa-config-link', {
    template,

    props: {
        value: {
            required: false,
            default: null,
        },
        error: {
            type: Object,
            required: false,
            default: null,
        },
    },

    methods: {
        openSettings() {
            this.$router.push({ name: 'emporiqa.integration.index' });
        },
    },
});
