import './page/emporiqa-integration-index';

const { Module } = Shopware;

Module.register('emporiqa-integration', {
    type: 'plugin',
    name: 'emporiqa-integration',
    title: 'emporiqa-integration.general.title',
    description: 'emporiqa-integration.general.description',
    color: '#1a73e8',
    icon: 'regular-cog',

    routes: {
        index: {
            component: 'emporiqa-integration-index',
            path: 'index',
        },
    },

    navigation: [{
        label: 'emporiqa-integration.general.title',
        color: '#1a73e8',
        path: 'emporiqa.integration.index',
        icon: 'regular-cog',
        parent: 'sw-settings',
        position: 100,
    }],

    settingsItem: {
        group: 'plugins',
        to: 'emporiqa.integration.index',
        icon: 'regular-cog',
        label: 'emporiqa-integration.general.title',
    },
});
