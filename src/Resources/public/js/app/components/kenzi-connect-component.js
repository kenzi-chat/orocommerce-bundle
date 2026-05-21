define(function(require) {
    'use strict';

    const BaseComponent = require('oroui/js/app/components/base/component');
    const Modal = require('oroui/js/modal');
    const mediator = require('oroui/js/mediator');
    const $ = require('jquery');
    const __ = require('orotranslation/js/translator');

    /**
     * Kenzi Connect — page component for the connect lifecycle UI on the
     * system configuration page.
     *
     * Two questions drive what the user sees:
     *   1. "Does the bundle have a shared secret?" Read from
     *      `this.bootstrap.secret_exists` — no HTTP call needed.
     *   2. "What does Kenzi say about the integration?" Answered by
     *      GET /integration, projected through `connectionState()`.
     *
     * Three views map from those answers:
     *   - 'disconnected' — no working integration. Either no secret stored,
     *                      or Kenzi did not return a 200 (bundle 4XX/5XX,
     *                      Kenzi unreachable, network error, etc.). Show
     *                      the Connect button so the user can start fresh.
     *   - 'connected'    — Kenzi confirms `configured && claimed`. Show
     *                      the green status panel and the Disconnect button.
     *   - 'incomplete'   — Kenzi returned 200 but the integration is not
     *                      yet `configured && claimed`. Show the yellow
     *                      warning panel and the Disconnect button.
     *
     * The integration object is the state. The JS doesn't track its own
     * state — every render flows from the latest projection.
     */
    const KenziConnectComponent = BaseComponent.extend({
        bootstrap: null,
        _messageHandler: null,
        _popup: null,

        constructor: function KenziConnectComponent(options) {
            KenziConnectComponent.__super__.constructor.call(this, options);
        },

        initialize: function(options) {
            this.$el = options._sourceElement;

            try {
                this.bootstrap = JSON.parse(this.$el.attr('data-kenzi-bootstrap') || '{}');
            } catch (err) {
                this.bootstrap = {};
                console.error('Kenzi Connect: invalid bootstrap', err);
            }

            this.$el.on('click', '[data-action="connect"]', (e) => this.onConnect(e));
            this.$el.on('click', '[data-action="disconnect"]', () => this.onDisconnect());

            this._renderInitial();

            KenziConnectComponent.__super__.initialize.call(this, options);
        },

        /**
         * Loading flow: short-circuit to disconnected when no secret is
         * stored locally. Otherwise GET /integration and project.
         */
        _renderInitial: async function() {
            if (!this.bootstrap.secret_exists) {
                this._render('disconnected', null);
                return;
            }

            try {
                const result = await this._fetch('GET', this.bootstrap.endpoints.integration);
                this._render(connectionState(result.body), result.body);
            } catch (err) {
                console.error('Kenzi Connect: integration GET failed', err);
                this._render('disconnected', null);
            }
        },

        /**
         * Open the Kenzi popup and wire up the postMessage handler.
         *
         * window.open() runs synchronously inside the click handler so the
         * browser counts it as a user gesture and doesn't block the popup.
         */
        onConnect: function(e) {
            const $button = $(e.currentTarget);
            $button.prop('disabled', true);

            const origin = this.bootstrap.kenzi_app_origin;

            if (!origin) {
                $button.prop('disabled', false);
                this._flashError(__('kenzi_oro_commerce.connect.error.no_kenzi_origin'));
                return;
            }

            const params = new URLSearchParams({
                type: 'oro_commerce',
                key: this.bootstrap.instance_key || '',
                supported_grants: (this.bootstrap.supported_grants || []).join(',')
            });

            this._popup = window.open(
                origin + '/connect?' + params.toString(),
                'kenzi_connect',
                'width=500,height=700,scrollbars=yes,resizable=yes'
            );

            if (!this._popup || this._popup.closed) {
                $button.prop('disabled', false);
                this._flashError(__('kenzi_oro_commerce.connect.error.popup_blocked'));
                return;
            }

            this._messageHandler = (e) => this._handleMessage(e);
            window.addEventListener('message', this._messageHandler);
        },

        /**
         * Validate the postMessage event and run the connect chain:
         * /connect → /configure → adapter → render.
         */
        _handleMessage: async function(event) {
            if (event.origin !== this.bootstrap.kenzi_app_origin) {
                return;
            }
            if (event.source !== this._popup) {
                return;
            }

            const payload = event.data;

            if (!payload || typeof payload !== 'object') {
                return;
            }
            if (typeof payload.shared_secret !== 'string'
                || typeof payload.workspace_id !== 'string'
                || !Array.isArray(payload.grants)) {
                return;
            }

            this._cleanupPopup();

            try {
                const connectResult = await this._fetch('POST', this.bootstrap.endpoints.connect, {
                    shared_secret: payload.shared_secret,
                    workspace_id: payload.workspace_id,
                    grants: payload.grants
                });

                if (!connectResult.body || !connectResult.body.ok) {
                    this._reportError(connectResult, 'kenzi_oro_commerce.connect.error.store_failed');
                    this._render('incomplete', null);
                    return;
                }

                // /connect succeeded — proceed to /configure.
                const configureResult = await this._fetch('POST', this.bootstrap.endpoints.configure);

                if (!configureResult.body || !configureResult.body.ok) {
                    this._reportError(configureResult, 'kenzi_oro_commerce.connect.error.configure_failed');
                    this._render('incomplete', null);
                    return;
                }

                // /configure succeeded — reload to re-render the projection
                // and refresh derived UI (widget_enabled checkbox, bootstrap dict).
                window.location.reload();
            } catch (err) {
                console.error('Kenzi Connect: connect chain failed', err);
                this._flashError(__('kenzi_oro_commerce.connect.error.store_failed'));
                this._render('incomplete', null);
            }
        },

        /**
         * Confirm with a modal, then POST /disconnect. On success, reload
         * the page so all derived state (widget_enabled, bootstrap flags,
         * other config-driven UI) refreshes from the now-cleared config.
         * On failure, flash and leave the view alone.
         */
        onDisconnect: function() {
            const modal = new Modal({
                title: __('kenzi_oro_commerce.connect.confirm.disconnect_title'),
                content: __('kenzi_oro_commerce.connect.confirm.disconnect'),
                okText: __('kenzi_oro_commerce.connect.button.disconnect'),
                cancelText: __('kenzi_oro_commerce.connect.confirm.cancel')
            });

            modal.on('ok', async () => {
                try {
                    const result = await this._fetch('POST', this.bootstrap.endpoints.disconnect);
                    if (!result.body || !result.body.ok) {
                        this._reportError(result, 'kenzi_oro_commerce.connect.error.disconnect_failed');
                        return;
                    }
                    window.location.reload();
                } catch (err) {
                    console.error('Kenzi Connect: disconnect failed', err);
                    this._flashError(__('kenzi_oro_commerce.connect.error.disconnect_failed'));
                }
            });

            modal.open();
        },

        /**
         * Show one slot, populate placeholders, hide the rest.
         */
        _render: function(view, integration) {
            this.$el.find('[data-state]').prop('hidden', true);

            const $slot = this.$el.find('[data-state="' + view + '"]');
            if ($slot.length === 0) {
                return;
            }
            $slot[0].hidden = false;

            // Always enable the connect button when entering the disconnected view.
            if (view === 'disconnected') {
                $slot.find('[data-action="connect"]').prop('disabled', false);
            }

            if (view === 'connected' && integration) {
                $slot.find('[data-field]').each(function() {
                    const path = this.getAttribute('data-field');
                    this.textContent = formatFieldValue(getPath(integration, path));
                });
            }
        },

        /**
         * POST/GET to the given URL with an optional JSON body. Returns a
         * Promise that resolves with `{ body }` regardless of HTTP
         * status — `body` is the parsed JSON response, or `null` if the
         * response wasn't JSON-decodable.
         */
        _fetch: function(method, url, body) {
            const options = {
                url: url,
                method: method,
                dataType: 'json'
            };

            if (body !== undefined) {
                options.contentType = 'application/json';
                options.data = JSON.stringify(body);
            }

            return new Promise(function(resolve) {
                $.ajax(options).done(function(data, _textStatus, jqXHR) {
                    resolve({ body: data });
                }).fail(function(jqXHR) {
                    let parsed = null;
                    try {
                        parsed = jqXHR.responseJSON || JSON.parse(jqXHR.responseText || '');
                    } catch (err) {
                        parsed = null;
                    }
                    resolve({ body: parsed });
                });
            });
        },

        _reportError: function(result, messageKey) {
            const body = result && result.body;

            if (body && typeof body.consoleError === 'string') {
                console.error('Kenzi Connect [' + messageKey + ']', body.consoleError);
            }

            this._flashError(body && typeof body.error === 'string' ? body.error : __(messageKey));
        },

        _flashError: function(message) {
            mediator.execute('showFlashMessage', 'error', message);
        },

        _cleanupPopup: function() {
            if (this._messageHandler) {
                window.removeEventListener('message', this._messageHandler);
                this._messageHandler = null;
            }
            // Close the popup so it doesn't outlive the component — otherwise
            // dispose() leaves the popup open with no listener attached.
            if (this._popup && !this._popup.closed) {
                this._popup.close();
            }
            this._popup = null;
        },

        dispose: function() {
            if (this.disposed) {
                return;
            }

            this._cleanupPopup();
            this.$el.off('click');

            KenziConnectComponent.__super__.dispose.call(this);
        }
    });

    /**
     * Pure projection of the GET /integration response → view name.
     *
     * Three outcomes:
     *   - ok: false          → 'disconnected'. No working integration on
     *                          Kenzi's side, so let the user start fresh.
     *   - ok: true + both flags   → 'connected'.
     *   - ok: true + missing flag → 'incomplete'.
     *
     * `disconnected` is also short-circuited in `_renderInitial` when
     * `secret_exists` is false (skips the HTTP call). So the JS reaches
     * `disconnected` via two paths — the short-circuit and this projection.
     */
    function connectionState(body) {
        if (!body || !body.ok) return 'disconnected';
        if (body.configured && body.claimed) return 'connected';
        return 'incomplete';
    }

    function getPath(obj, path) {
        return path.split('.').reduce(function(acc, key) {
            return (acc !== null && acc !== undefined) ? acc[key] : undefined;
        }, obj);
    }

    function formatFieldValue(value) {
        if (value === null || value === undefined) {
            return '';
        }
        if (Array.isArray(value)) {
            return value.join(', ');
        }
        return String(value);
    }

    return KenziConnectComponent;
});
