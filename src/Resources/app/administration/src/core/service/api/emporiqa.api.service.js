const ApiService = Shopware.Classes.ApiService;

class EmporiqaApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'emporiqa') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'emporiqaService';
    }

    testConnection() {
        return this.httpClient
            .post('/_action/emporiqa/test-connection', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    syncOverview() {
        return this.httpClient
            .post('/_action/emporiqa/sync-overview', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    dataPreview() {
        return this.httpClient
            .post('/_action/emporiqa/data-preview', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    sync(entity) {
        return this.httpClient
            .post('/_action/emporiqa/sync', { entity }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    syncInit(entities) {
        return this.httpClient
            .post('/_action/emporiqa/sync-init', { entities }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    syncBatch(entity, sessionId, cursor) {
        return this.httpClient
            .post('/_action/emporiqa/sync-batch', { entity, sessionId, cursor }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    syncComplete(entity, sessionId) {
        return this.httpClient
            .post('/_action/emporiqa/sync-complete', { entity, sessionId }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getSalesChannels() {
        return this.httpClient
            .post('/_action/emporiqa/sales-channels', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getPropertyGroups() {
        return this.httpClient
            .post('/_action/emporiqa/property-groups', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    getOrderStates() {
        return this.httpClient
            .post('/_action/emporiqa/order-states', {}, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    saveSettings(data) {
        return this.httpClient
            .post('/_action/emporiqa/save-settings', data, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    connectInitiate(origin) {
        return this.httpClient
            .post('/_action/emporiqa/connect/initiate', { origin }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    connectExchange(code, state) {
        return this.httpClient
            .post('/_action/emporiqa/connect/exchange', { code, state }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    actionsUrl(origin) {
        return this.httpClient
            .post('/_action/emporiqa/actions-url', { origin }, { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }

    loadSystemConfig() {
        return this.httpClient
            .get('/_action/system-config?domain=EmporiqaIntegration.config', { headers: this.getBasicHeaders() })
            .then((response) => ApiService.handleResponse(response));
    }
}

export default EmporiqaApiService;
