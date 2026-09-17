const Plugin = window.PluginBaseClass;

export default class EmporiqaCartPlugin extends Plugin {
    static options = {
        cartApiUrl: '/emporiqa/api/cart',
    };

    init() {
        this._registerCartHandler();
    }

    _registerCartHandler() {
        const cartApiUrl = this.options.cartApiUrl;

        window.EmporiqaCartHandler = async function ({ action, items }) {
            try {
                switch (action) {
                    case 'add':
                        return await handleAdd(cartApiUrl, items);
                    case 'view':
                        return await handleGet(cartApiUrl);
                    case 'update':
                        return await handleUpdate(cartApiUrl, items);
                    case 'remove':
                        return await handleRemove(cartApiUrl, items);
                    case 'clear':
                        return await handleClear(cartApiUrl);
                    case 'checkout':
                        return await handleCheckout(cartApiUrl);
                    default:
                        return { success: false, error: 'Unknown action: ' + action, checkoutUrl: null, cart: null };
                }
            } catch (err) {
                return { success: false, error: err.message || 'Cart operation failed', checkoutUrl: null, cart: null };
            }
        };
    }
}

async function apiRequest(url, method = 'GET', body = null) {
    const options = {
        method,
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
    };

    if (body && method === 'POST') {
        options.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    const response = await fetch(url, options);

    if (!response.ok) {
        let error = 'Request failed (' + response.status + ')';

        try {
            const body = await response.json();
            error = body?.error || error;
        } catch {
            // Response body was not valid JSON.
        }

        return { success: false, error, checkoutUrl: null, cart: null };
    }

    return response.json();
}

async function handleAdd(baseUrl, items) {
    if (!items || items.length === 0) {
        return { success: false, error: 'No items provided', checkoutUrl: null, cart: null };
    }

    let lastResult = null;
    for (const item of items) {
        lastResult = await apiRequest(baseUrl + '/add', 'POST', {
            product_id: item.product_id || '',
            variation_id: item.variation_id || '',
            quantity: item.quantity || 1,
        });

        if (!lastResult.success) {
            return lastResult;
        }
    }

    refreshShopwareCart();
    return lastResult;
}

async function handleGet(baseUrl) {
    return apiRequest(baseUrl);
}

async function handleUpdate(baseUrl, items) {
    if (!items || items.length === 0) {
        return { success: false, error: 'No items provided', checkoutUrl: null, cart: null };
    }

    let lastResult = null;
    for (const item of items) {
        lastResult = await apiRequest(baseUrl + '/update', 'POST', {
            product_id: item.product_id || '',
            variation_id: item.variation_id || '',
            quantity: item.quantity || 1,
        });

        if (!lastResult.success) {
            return lastResult;
        }
    }

    refreshShopwareCart();
    return lastResult;
}

async function handleRemove(baseUrl, items) {
    if (!items || items.length === 0) {
        return { success: false, error: 'No items provided', checkoutUrl: null, cart: null };
    }

    let lastResult = null;
    for (const item of items) {
        lastResult = await apiRequest(baseUrl + '/remove', 'POST', {
            product_id: item.product_id || '',
            variation_id: item.variation_id || '',
        });

        if (!lastResult.success) {
            return lastResult;
        }
    }

    refreshShopwareCart();
    return lastResult;
}

async function handleClear(baseUrl) {
    const result = await apiRequest(baseUrl + '/clear', 'POST');
    refreshShopwareCart();
    return result;
}

async function handleCheckout(baseUrl) {
    return apiRequest(baseUrl + '/checkout-url');
}

function refreshShopwareCart() {
    // Trigger Shopware's built-in cart widget refresh
    const event = new CustomEvent('Emporiqa/CartUpdated');
    document.dispatchEvent(event);

    // Also trigger the standard Shopware cart widget update
    if (window.PluginManager) {
        try {
            const cartWidgetPlugins = window.PluginManager.getPluginInstances('CartWidget');
            if (cartWidgetPlugins && cartWidgetPlugins.length > 0) {
                cartWidgetPlugins.forEach(plugin => {
                    if (typeof plugin.fetch === 'function') {
                        plugin.fetch();
                    }
                });
            }
        } catch {
            // CartWidget plugin may not be available
        }
    }
}
