const Plugin = window.PluginBaseClass;

// A signed-in shopper's token is reused for at most this long, so a tab left
// open never hands the widget a token that expired or belongs to a session
// that has since signed out.
const TOKEN_MAX_AGE_MS = 5 * 60 * 1000;

export default class EmporiqaWidgetPlugin extends Plugin {
    static options = {
        storeId: '',
        language: 'en',
        channel: '',
        currency: '',
        widgetBaseUrl: 'https://emporiqa.com',
        userTokenUrl: '/emporiqa/api/user-token',
        loggedIn: false,
    };

    init() {
        const config = this.options;

        if (!config.storeId) {
            return;
        }

        this._tokenAnswer = null;
        this._tokenAnsweredAt = 0;
        this._answerTokenRequests(config);
        this._loadWidget(config);
    }

    _loadWidget(config) {
        const params = new URLSearchParams({
            store_id: config.storeId,
            language: config.language,
            channel: config.channel || '',
        });

        if (config.currency) {
            params.set('currency', config.currency);
        }

        const script = document.createElement('script');
        script.async = true;
        script.crossOrigin = 'anonymous';
        script.src = config.widgetBaseUrl + '/chat/embed/?' + params.toString();
        document.head.appendChild(script);
    }

    /**
     * The customer token never goes into the page or the widget URL (page
     * caches and server logs would hand it to others). When the chat opens,
     * embed.js asks with
     *   window.postMessage({type: 'EMPORIQA_TOKEN_REQUEST', reply: 'port'}, origin, [port])
     * and this answers ONLY on that port, first EMPORIQA_TOKEN_PENDING so the
     * widget holds the first message for the token, then
     * EMPORIQA_CUSTOMER_TOKEN with the token ('' for a guest). A request
     * without a port (an older embed.js) is answered on the window.
     */
    _answerTokenRequests(config) {
        const origin = window.location.origin;

        window.addEventListener('message', (event) => {
            if (event.source !== window || event.origin !== origin) {
                return;
            }
            const data = event.data;
            if (!data || data.type !== 'EMPORIQA_TOKEN_REQUEST') {
                return;
            }
            const port = event.ports && event.ports[0];
            const reply = port
                ? (message) => port.postMessage(message)
                : (message) => window.postMessage(message, origin);

            reply({ type: 'EMPORIQA_TOKEN_PENDING' });
            this._fetchToken(config).then((token) => {
                reply({ type: 'EMPORIQA_CUSTOMER_TOKEN', token });
            });
        });
    }

    _fetchToken(config) {
        // A guest is answered at once, with no request.
        if (!config.loggedIn || !window.fetch) {
            return Promise.resolve('');
        }
        if (this._tokenAnswer && Date.now() - this._tokenAnsweredAt < TOKEN_MAX_AGE_MS) {
            return this._tokenAnswer;
        }

        this._tokenAnsweredAt = Date.now();
        this._tokenAnswer = fetch(config.userTokenUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('token request failed: ' + response.status);
                }
                return response.json();
            })
            .then((data) => (data && typeof data.token === 'string' ? data.token : ''))
            .catch(() => {
                // A failed answer is never reused, so the next request tries again.
                this._tokenAnswer = null;
                return '';
            });

        return this._tokenAnswer;
    }
}
