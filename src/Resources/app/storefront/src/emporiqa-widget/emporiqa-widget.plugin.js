const Plugin = window.PluginBaseClass;

const STORAGE_KEY = 'emporiqa_user_token';

export default class EmporiqaWidgetPlugin extends Plugin {
    static options = {
        storeId: '',
        language: 'en',
        channel: '',
        currency: '',
        widgetBaseUrl: 'https://emporiqa.com',
        userTokenUrl: '/emporiqa/api/user-token',
    };

    init() {
        const config = this.options;

        if (!config.storeId) {
            return;
        }

        this._loadWidget(config);
    }

    async _loadWidget(config) {
        const loggedIn = (document.cookie.match(/(?:^|;\s*)sw-states=([^;]*)/) || [])[1]?.includes('logged-in') || false;
        const cached = this._getCachedEntry();
        let userToken;

        if (cached !== undefined && cached.loggedIn === loggedIn) {
            userToken = cached.token;
        } else {
            userToken = await this._fetchUserToken(config.userTokenUrl);
            this._cacheEntry(userToken, loggedIn);
        }

        const params = new URLSearchParams({
            store_id: config.storeId,
            language: config.language,
            channel: config.channel || '',
        });

        if (config.currency) {
            params.set('currency', config.currency);
        }

        if (userToken) {
            params.set('user_id', userToken);
        }

        const script = document.createElement('script');
        script.async = true;
        script.crossOrigin = 'anonymous';
        script.src = config.widgetBaseUrl + '/chat/embed/?' + params.toString();
        document.head.appendChild(script);
    }

    async _fetchUserToken(url) {
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                return null;
            }

            const data = await response.json();
            return data.token || null;
        } catch {
            return null;
        }
    }

    _cacheEntry(token, loggedIn) {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                token: token || null,
                loggedIn,
            }));
        } catch {
            // sessionStorage unavailable
        }
    }

    _getCachedEntry() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return undefined;
            return JSON.parse(raw);
        } catch {
            // ignore
        }
        return undefined;
    }
}
