import template from './emporiqa-config-link.html.twig';

const { Component } = Shopware;

const CONFIG_PREFIX = 'EmporiqaIntegration.config.';

// Rendered inside the native plugin configuration page (config.xml <component>).
// All settings live on the Emporiqa page, so it forwards there right away and only
// renders the connection state + link as a fallback if navigation is not possible.
Component.register('emporiqa-config-link', {
    template,

    inject: ['emporiqaService'],

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

    data() {
        return {
            storeId: '',
            connected: false,
            loaded: false,
        };
    },

    created() {
        if (this.$router && this.$route && this.$route.name !== 'emporiqa.integration.index') {
            this.$router.replace({ name: 'emporiqa.integration.index' });
            return;
        }

        this.emporiqaService.loadSystemConfig()
            .then((values) => {
                const config = values || {};
                this.storeId = config[`${CONFIG_PREFIX}storeId`] || '';
                this.connected = !!(this.storeId && config[`${CONFIG_PREFIX}webhookSecret`]);
            })
            .catch(() => {
                this.connected = false;
            })
            .finally(() => {
                this.loaded = true;
            });
    },

    methods: {
        openSettings() {
            this.$router.push({ name: 'emporiqa.integration.index' });
        },
    },
});
