import template from './emporiqa-integration-index.html.twig';
import './emporiqa-integration-index.scss';

const { Component, Mixin } = Shopware;

const CONFIG_PREFIX = 'EmporiqaIntegration.config.';

Component.register('emporiqa-integration-index', {
    template,

    inject: ['emporiqaService'],

    mixins: [Mixin.getByName('notification')],

    // Applied to the Store ID / Webhook Secret fields. Shopware 6.7's Meteor
    // form components don't forward autocomplete/name to the inner <input>,
    // so the template attributes alone don't stop Chrome from autofilling the
    // saved admin login into this text + password pair. Set the guards on the
    // real input directly, and use the readonly-until-focus trick, browsers
    // never autofill a readonly field on load, and typing still works once it
    // gains focus and the flag is removed.
    directives: {
        noAutofill: {
            mounted(el) {
                const input = el.matches('input') ? el : el.querySelector('input');
                if (!input) {
                    return;
                }
                input.setAttribute('autocomplete', 'new-password');
                input.setAttribute('readonly', 'readonly');
                const unlock = () => input.removeAttribute('readonly');
                input.addEventListener('focus', unlock, { once: true });
                input.addEventListener('pointerdown', unlock, { once: true });
            },
        },
    },

    data() {
        return {
            activeTab: 'settings',

            // Shown on the Sync tab right after a successful one-click connect
            justConnected: false,

            // One-click connect
            isInitiatingConnect: false,

            // Settings (all config values)
            isLoadingSettings: false,
            isSavingSettings: false,
            settingsSaved: false,
            saveError: false,
            loadError: false,
            settings: {
                storeId: '',
                webhookSecret: '',
                webhookUrl: 'https://emporiqa.com/webhooks/sync/',
                syncProducts: true,
                syncPages: true,
                brandAttribute: '',
                batchSize: 50,
            },

            // Locale codes to sync; null means all languages (nothing saved yet)
            enabledLanguages: null,

            // Sales channel IDs to sync; null means all storefront channels (nothing saved yet)
            enabledSalesChannels: null,

            // Connection test
            isTestingConnection: false,
            connectionResult: null,

            // Sales channels & property groups (for dropdowns/tables)
            salesChannels: [],
            propertyGroups: [],

            // Sync tab
            isLoading: false,
            overview: null,
            showCliCommands: false,

            // Driven bulk sync (progress bar)
            isSyncRunning: false,
            syncCancelled: false,
            syncingEntities: [],
            syncProgress: { processed: 0, total: 0, percent: 0 },
            syncLog: [],
        };
    },

    metaInfo() {
        return {
            title: this.$createTitle(),
        };
    },

    computed: {
        // 6.7 renders sw-alert through Meteor's mt-banner, whose variant names differ
        alertVariants() {
            return Shopware.Feature.isActive('v6.7.0.0')
                ? { success: 'positive', warning: 'attention', error: 'critical' }
                : { success: 'success', warning: 'warning', error: 'error' };
        },

        emporiqaLogoUrl() {
            const base = (Shopware.Context.api.assetsPath || '').replace(/\/+$/, '');
            return `${base}/emporiqaintegration/static/emporiqa-logo.png`;
        },

        orderTrackingUrl() {
            const origin = window.location.origin;
            return `${origin}/emporiqa/api/order/tracking`;
        },

        dashboardUrl() {
            return 'https://emporiqa.com/platform/';
        },

        docsUrl() {
            return 'https://emporiqa.com/docs/shopware/';
        },

        hasOverview() {
            return this.overview !== null;
        },

        isConnected() {
            return !!(this.settings.storeId && this.settings.webhookSecret);
        },

        availableSalesChannels() {
            return this.salesChannels.map((channel) => ({ id: channel.id, name: channel.name }));
        },

        availableLanguages() {
            const languages = new Map();

            this.salesChannels.forEach((channel) => {
                (channel.domains || []).forEach((domain) => {
                    if (domain.languageCode && !languages.has(domain.languageCode)) {
                        languages.set(domain.languageCode, {
                            code: domain.languageCode,
                            name: domain.language || domain.languageCode,
                        });
                    }
                });
            });

            return [...languages.values()];
        },

        brandAttributeOptions() {
            const options = [
                { value: '', label: this.$t('emporiqa-integration.settings.syncSettings.useManufacturer') },
            ];

            this.propertyGroups.forEach((group) => {
                options.push({ value: group.id, label: group.name });
            });

            return options;
        },
    },

    created() {
        // Landed here from a successful one-click connect: open the Sync tab
        // and prompt the merchant to send their catalog (PrestaShop parity).
        if ((this.$route.query || {}).connected === '1') {
            this.activeTab = 'sync';
            this.justConnected = true;
        }

        this.loadSettings();
        this.loadSalesChannels();
        this.loadPropertyGroups();
        this.loadOverview();
    },

    methods: {
        startConnect() {
            this.isInitiatingConnect = true;

            this.emporiqaService.connectInitiate(window.location.origin)
                .then((response) => {
                    if (response && response.url) {
                        window.location.assign(response.url);
                        return;
                    }
                    this.isInitiatingConnect = false;
                    this.createNotificationError({
                        message: this.$t('emporiqa-integration.connect.initiateError'),
                    });
                })
                .catch((error) => {
                    this.isInitiatingConnect = false;
                    this.createNotificationError({
                        message: error.response?.data?.error
                            || this.$t('emporiqa-integration.connect.initiateError'),
                    });
                });
        },

        loadSettings() {
            this.isLoadingSettings = true;

            this.emporiqaService.loadSystemConfig()
                .then((response) => {
                    const vals = response ?? {};
                    // Map from prefixed keys to flat settings
                    Object.keys(this.settings).forEach((key) => {
                        const prefixedKey = CONFIG_PREFIX + key;
                        if (vals[prefixedKey] !== undefined && vals[prefixedKey] !== null) {
                            this.settings[key] = vals[prefixedKey];
                        }
                    });
                    this.enabledLanguages = this.parseSelection(vals[`${CONFIG_PREFIX}enabledLanguages`]);
                    this.enabledSalesChannels = this.parseSelection(vals[`${CONFIG_PREFIX}enabledSalesChannels`]);
                })
                .catch(() => {
                    this.loadError = true;
                })
                .finally(() => {
                    this.isLoadingSettings = false;
                });
        },

        saveSettings() {
            // Never post the defaults over stored credentials while they are still loading
            if (this.isLoadingSettings || this.loadError) {
                return;
            }

            const enabledLanguages = this.selectionPayload(this.enabledLanguages, this.availableLanguages.map((language) => language.code));
            if (enabledLanguages === null) {
                this.createNotificationError({
                    message: this.$t('emporiqa-integration.settings.advanced.enabledLanguagesRequired'),
                });
                return;
            }

            const enabledSalesChannels = this.selectionPayload(this.enabledSalesChannels, this.availableSalesChannels.map((channel) => channel.id));
            if (enabledSalesChannels === null) {
                this.createNotificationError({
                    message: this.$t('emporiqa-integration.settings.advanced.enabledSalesChannelsRequired'),
                });
                return;
            }

            this.isSavingSettings = true;
            this.settingsSaved = false;
            this.saveError = false;

            this.emporiqaService.saveSettings({ ...this.settings, enabledLanguages, enabledSalesChannels })
                .then(() => {
                    this.settingsSaved = true;
                    setTimeout(() => { this.settingsSaved = false; }, 3000);
                })
                .catch(() => {
                    this.saveError = true;
                    setTimeout(() => { this.saveError = false; }, 5000);
                })
                .finally(() => {
                    this.isSavingSettings = false;
                });
        },

        testConnection() {
            this.isTestingConnection = true;
            this.connectionResult = null;

            this.emporiqaService.testConnection()
                .then((response) => {
                    this.connectionResult = response;
                })
                .catch((error) => {
                    this.connectionResult = {
                        success: false,
                        message: error.response?.data?.message || this.$t('emporiqa-integration.settings.connection.errorGeneric'),
                    };
                })
                .finally(() => {
                    this.isTestingConnection = false;
                });
        },

        openExternal(url) {
            window.open(url, '_blank', 'noopener');
        },

        loadOverview() {
            this.isLoading = true;

            this.emporiqaService.syncOverview()
                .then((response) => {
                    this.overview = response;
                })
                .catch(() => {
                    this.overview = null;
                })
                .finally(() => {
                    this.isLoading = false;
                });
        },

        sendCatalog() {
            this.justConnected = false;
            this.startSync(['products', 'pages']);
        },

        dismissWelcome() {
            this.justConnected = false;
        },

        async startSync(entities) {
            if (this.isSyncRunning) {
                return;
            }

            this.isSyncRunning = true;
            this.syncCancelled = false;
            this.syncingEntities = entities;
            this.syncLog = [];
            this.syncProgress = { processed: 0, total: 0, percent: 0 };

            this.addSyncLog(this.$t('emporiqa-integration.sync.driver.initializing'), 'info');

            let initResponse;
            try {
                initResponse = await this.emporiqaService.syncInit(entities);
            } catch (error) {
                this.addSyncLog(
                    error.response?.data?.error || this.$t('emporiqa-integration.sync.driver.initFailed'),
                    'error',
                );
                this.isSyncRunning = false;
                this.syncingEntities = [];
                return;
            }

            if (!initResponse || !initResponse.success) {
                this.addSyncLog(
                    (initResponse && initResponse.error) || this.$t('emporiqa-integration.sync.driver.initFailed'),
                    'error',
                );
                this.isSyncRunning = false;
                this.syncingEntities = [];
                return;
            }

            const sessions = initResponse.sessions || [];
            const batchSize = initResponse.batchSize || 50;

            if (sessions.length === 0) {
                this.addSyncLog(this.$t('emporiqa-integration.sync.driver.nothingToSync'), 'info');
                this.isSyncRunning = false;
                this.syncingEntities = [];
                this.loadOverview();
                return;
            }

            this.syncProgress.total = sessions.reduce((sum, session) => sum + session.total, 0);

            const workQueue = [];
            sessions.forEach((session) => {
                const totalPages = Math.ceil(session.total / batchSize);
                this.addSyncLog(
                    this.$t('emporiqa-integration.sync.driver.started', { entity: this.entityLabel(session.entity) }),
                    'info',
                );
                for (let page = 1; page <= totalPages; page++) {
                    workQueue.push({ entity: session.entity, sessionId: session.sessionId, page, totalPages });
                }
            });

            const entityErrors = {};
            let processed = 0;

            for (const work of workQueue) {
                if (this.syncCancelled) {
                    this.addSyncLog(this.$t('emporiqa-integration.sync.driver.cancelled'), 'warning');
                    this.isSyncRunning = false;
                    this.syncingEntities = [];
                    return;
                }

                const batchResult = await this.runSyncBatch(work);

                if (batchResult.success) {
                    processed += batchResult.processed || 0;
                    this.addSyncLog(
                        this.$t('emporiqa-integration.sync.driver.batchProcessed', {
                            entity: this.entityLabel(work.entity),
                            page: work.page,
                            totalPages: work.totalPages,
                            count: batchResult.processed || 0,
                        }),
                        'info',
                    );
                } else {
                    entityErrors[work.entity] = (entityErrors[work.entity] || 0) + 1;
                    const reason = batchResult.error ? ` ${batchResult.error}` : '';
                    this.addSyncLog(
                        this.$t('emporiqa-integration.sync.driver.batchError', {
                            entity: this.entityLabel(work.entity),
                            page: work.page,
                        }) + reason,
                        'error',
                    );
                }

                this.syncProgress.processed = processed;
                this.syncProgress.percent = this.syncProgress.total > 0
                    ? Math.min(100, Math.round((processed / this.syncProgress.total) * 100))
                    : 0;
            }

            let hadErrors = Object.keys(entityErrors).length > 0;

            for (const session of sessions) {
                if (entityErrors[session.entity]) {
                    this.addSyncLog(
                        this.$t('emporiqa-integration.sync.driver.skippedIncomplete', {
                            entity: this.entityLabel(session.entity),
                        }),
                        'warning',
                    );
                    continue;
                }

                const completeResult = await this.runSyncComplete(session);

                if (completeResult.success) {
                    this.addSyncLog(
                        this.$t('emporiqa-integration.sync.driver.completed', { entity: this.entityLabel(session.entity) }),
                        'success',
                    );
                } else {
                    hadErrors = true;
                    const reason = completeResult.error ? ` ${completeResult.error}` : '';
                    this.addSyncLog(
                        this.$t('emporiqa-integration.sync.driver.completeError', {
                            entity: this.entityLabel(session.entity),
                        }) + reason,
                        'error',
                    );
                }
            }

            this.isSyncRunning = false;
            this.syncingEntities = [];
            this.loadOverview();

            if (hadErrors) {
                this.createNotificationWarning({
                    message: this.$t('emporiqa-integration.sync.driver.finishedWithErrors'),
                });
            } else {
                this.syncProgress.percent = 100;
                this.createNotificationSuccess({
                    message: this.$t('emporiqa-integration.sync.driver.finishedSuccess', { count: processed }),
                });
            }
        },

        async runSyncBatch(work) {
            try {
                return await this.emporiqaService.syncBatch(work.entity, work.sessionId, work.page);
            } catch (error) {
                return {
                    success: false,
                    error: error.response?.data?.error || this.$t('emporiqa-integration.sync.driver.batchFailed'),
                };
            }
        },

        async runSyncComplete(session) {
            try {
                return await this.emporiqaService.syncComplete(session.entity, session.sessionId);
            } catch (error) {
                return {
                    success: false,
                    error: error.response?.data?.error || this.$t('emporiqa-integration.sync.driver.completeFailed'),
                };
            }
        },

        cancelSync() {
            this.syncCancelled = true;
        },

        addSyncLog(message, type = 'info') {
            this.syncLog.push({ message, type });
        },

        entityLabel(entity) {
            return entity === 'products'
                ? this.$t('emporiqa-integration.sync.products')
                : this.$t('emporiqa-integration.sync.pages');
        },

        loadSalesChannels() {
            this.emporiqaService.getSalesChannels()
                .then((response) => {
                    this.salesChannels = response;
                })
                .catch(() => {
                    this.salesChannels = [];
                });
        },

        loadPropertyGroups() {
            this.emporiqaService.getPropertyGroups()
                .then((response) => {
                    this.propertyGroups = response;
                })
                .catch(() => {
                    this.propertyGroups = [];
                });
        },

        async copyOrderTrackingUrl() {
            try {
                await navigator.clipboard.writeText(this.orderTrackingUrl);
                if (this.createNotificationSuccess) {
                    this.createNotificationSuccess({
                        message: this.$t('emporiqa-integration.settings.connection.copyUrl'),
                    });
                }
            } catch {
                /* clipboard unavailable, ignore */
            }
        },

        parseSelection(raw) {
            let values = raw;
            if (typeof raw === 'string') {
                try {
                    values = JSON.parse(raw);
                } catch {
                    values = null;
                }
            }

            return Array.isArray(values) && values.length > 0 ? values : null;
        },

        // null = nothing saved = everything selected
        isSelected(selection, value) {
            return selection === null || selection.includes(value);
        },

        toggleSelection(key, available, value, checked) {
            if (typeof checked !== 'boolean') {
                return;
            }

            const current = this[key] === null ? available : this[key];
            const without = current.filter((entry) => entry !== value);

            this[key] = checked ? [...without, value] : without;
        },

        // Returns [] when everything available is selected, so entries added to the
        // shop later are synced too. Returns null when nothing is selected, which
        // is not a valid setting. Entries that no longer exist are dropped.
        selectionPayload(selection, available) {
            if (selection === null) {
                return [];
            }

            const selected = selection.filter((value) => available.includes(value));

            if (available.length > 0 && selected.length === 0) {
                return null;
            }

            return available.length > 0 && selected.length === available.length
                ? []
                : selected;
        },

        setSetting(key, value) {
            if (value instanceof Event) {
                return;
            }
            this.settings[key] = value;
        },

    },
});
