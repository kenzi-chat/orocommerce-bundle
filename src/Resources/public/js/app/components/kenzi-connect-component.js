define(function(require) {
    'use strict';

    const BaseComponent = require('oroui/js/app/components/base/component');
    const Modal = require('oroui/js/modal');
    const mediator = require('oroui/js/mediator');
    const $ = require('jquery');
    const __ = require('orotranslation/js/translator');

    /**
     * Kenzi Connect — Page component for the connect/disconnect button on the
     * system configuration page.
     *
     * Auto-initialized by Oro's PageController from the
     * data-page-component-module attribute set in the form widget template.
     *
     * Opens the Kenzi connect popup, validates the postMessage handshake nonce,
     * and stores credentials via the bundle's ConnectController.
     */
    const KenziConnectComponent = BaseComponent.extend({
        /**
         * @property {Object}
         */
        options: {
            storeUrl: '',
            disconnectUrl: '',
            retryDeliveryUrl: '',
            kenziOrigin: '',
            instanceKey: '',
            adminUrl: '',
            apiUrl: '',
            baseUrl: ''
        },

        /**
         * sessionStorage key used to surface a one-shot warning flash on the
         * page that follows a partial-connect reload. Set by _storeCredentials
         * before reload, read (and cleared) by initialize on the next load.
         */
        PARTIAL_FLASH_KEY: 'kenziConnectPartialFlash',

        /**
         * @property {Function|null} Bound message handler for cleanup
         */
        _messageHandler: null,

        /**
         * @property {number|null} Poll timer for popup close detection
         */
        _pollTimer: null,

        /**
         * @inheritdoc
         */
        constructor: function KenziConnectComponent(options) {
            KenziConnectComponent.__super__.constructor.call(this, options);
        },

        /**
         * @inheritdoc
         */
        initialize: function(options) {
            this.options = $.extend(true, {}, this.options, options);
            this.$el = options._sourceElement;

            this.$el.on('click', '[data-action="connect"]', this.onConnect.bind(this));
            this.$el.on('click', '[data-action="disconnect"]', this.onDisconnect.bind(this));
            this.$el.on('click', '[data-action="retry-delivery"]', this.onRetryDelivery.bind(this));

            this._surfacePartialFlash();

            KenziConnectComponent.__super__.initialize.call(this, options);
        },

        /**
         * Show a warning flash if the previous request reloaded the page in
         * a partially-connected state. The flag is cleared after reading so
         * it only appears once.
         *
         * @private
         */
        _surfacePartialFlash: function() {
            try {
                if (window.sessionStorage.getItem(this.PARTIAL_FLASH_KEY)) {
                    window.sessionStorage.removeItem(this.PARTIAL_FLASH_KEY);
                    mediator.execute(
                        'showFlashMessage', 'warning',
                        __('kenzi_oro_commerce.connect.status.partially_connected_warning')
                    );
                }
            } catch (err) {
                // sessionStorage disabled — silently skip the flash.
            }
        },

        /**
         * Handle "Connect to Kenzi" button click.
         * Opens the connect popup and sets up postMessage listener.
         */
        onConnect: function(e) {
            const $button = $(e.currentTarget);
            const kenziOrigin = this.options.kenziOrigin;
            const instanceKey = this.options.instanceKey;
            const self = this;

            if (!kenziOrigin) {
                mediator.execute(
                    'showFlashMessage', 'error',
                    __('kenzi_oro_commerce.connect.error.no_kenzi_origin')
                );
                return;
            }

            const nonce = crypto.randomUUID();

            const params = new URLSearchParams({
                platform: 'oro_commerce',
                instance_key: instanceKey || '',
                nonce: nonce,
                origin: window.location.origin,
                requested_capabilities: 'commerce'
            });

            if (this.options.apiUrl) {
                params.set('api_url', this.options.apiUrl);
            }

            if (this.options.adminUrl) {
                params.set('admin_url', this.options.adminUrl);
            }

            if (this.options.baseUrl) {
                params.set('base_url', this.options.baseUrl);
            }

            const popup = window.open(
                kenziOrigin + '/connect?' + params.toString(),
                'kenzi_connect',
                'width=500,height=700,scrollbars=yes,resizable=yes'
            );

            if (!popup || popup.closed) {
                mediator.execute(
                    'showFlashMessage', 'error',
                    __('kenzi_oro_commerce.connect.error.popup_blocked')
                );
                return;
            }

            $button.prop('disabled', true);

            const handleMessage = function(event) {
                if (event.origin !== kenziOrigin) {
                    return;
                }

                const data = event.data;

                if (!data || data.type !== 'kenzi_connected') {
                    return;
                }

                if (data.nonce !== nonce) {
                    console.error('Kenzi Connect: nonce mismatch');
                    self._cleanup();
                    $button.prop('disabled', false);
                    return;
                }

                // Acknowledge so the popup can close itself
                popup.postMessage({type: 'kenzi:ack'}, kenziOrigin);
                self._cleanup();

                self._storeCredentials({
                    workspace_id: data.workspace_id,
                    shared_secret: data.shared_secret
                }, $button);
            };

            this._messageHandler = handleMessage;
            window.addEventListener('message', handleMessage);

            // Detect popup closed without completing the handshake
            this._pollTimer = setInterval(function() {
                if (popup.closed) {
                    self._cleanup();
                    $button.prop('disabled', false);
                }
            }, 1000);
        },

        /**
         * POST credentials to ConnectController.
         *
         * @param {Object} credentials
         * @param {jQuery} $button
         * @private
         */
        _storeCredentials: function(credentials, $button) {
            const self = this;

            $.ajax({
                url: this.options.storeUrl,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(credentials)
            }).done(function(response) {
                if (response && response.status === 'partially_connected') {
                    self._markPartialFlash();
                }
                window.location.reload();
            }).fail(function() {
                $button.prop('disabled', false);
                mediator.execute(
                    'showFlashMessage', 'error',
                    __('kenzi_oro_commerce.connect.error.store_failed')
                );
            });
        },

        /**
         * Persist a flag so the next page load shows a partial-connect warning
         * flash. Silently no-ops when sessionStorage is unavailable — the
         * inline Twig banner still communicates the state.
         *
         * @private
         */
        _markPartialFlash: function() {
            try {
                window.sessionStorage.setItem(this.PARTIAL_FLASH_KEY, '1');
            } catch (err) {
                // sessionStorage disabled — fall back to the Twig banner.
            }
        },

        /**
         * Handle "Retry delivery" button click on the partially-connected
         * banner. POSTs to the retry endpoint, which re-runs credential
         * delivery using the already-stored shared secret.
         */
        onRetryDelivery: function(e) {
            const $button = $(e.currentTarget);
            const self = this;

            $button.prop('disabled', true);

            $.ajax({
                url: this.options.retryDeliveryUrl,
                method: 'POST'
            }).done(function(response) {
                if (response && response.status === 'partially_connected') {
                    self._markPartialFlash();
                }
                window.location.reload();
            }).fail(function() {
                $button.prop('disabled', false);
                mediator.execute(
                    'showFlashMessage', 'error',
                    __('kenzi_oro_commerce.connect.error.retry_delivery_failed')
                );
            });
        },

        /**
         * Handle "Disconnect" button click.
         */
        onDisconnect: function(e) {
            const $button = $(e.currentTarget);
            const self = this;

            const modal = new Modal({
                title: __('kenzi_oro_commerce.connect.confirm.disconnect_title'),
                content: __('kenzi_oro_commerce.connect.confirm.disconnect'),
                okText: __('kenzi_oro_commerce.connect.button.disconnect'),
                cancelText: __('kenzi_oro_commerce.connect.confirm.cancel')
            });

            modal.on('ok', function() {
                $button.prop('disabled', true);

                $.ajax({
                    url: self.options.disconnectUrl,
                    method: 'POST'
                }).done(function() {
                    window.location.reload();
                }).fail(function() {
                    $button.prop('disabled', false);
                    mediator.execute(
                        'showFlashMessage', 'error',
                        __('kenzi_oro_commerce.connect.error.disconnect_failed')
                    );
                });
            });

            modal.open();
        },

        /**
         * Remove the postMessage listener and popup poll timer.
         *
         * @private
         */
        _cleanup: function() {
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
            if (this._messageHandler) {
                window.removeEventListener('message', this._messageHandler);
                this._messageHandler = null;
            }
        },

        /**
         * @inheritdoc
         */
        dispose: function() {
            if (this.disposed) {
                return;
            }

            this._cleanup();
            this.$el.off('click');

            KenziConnectComponent.__super__.dispose.call(this);
        }
    });

    return KenziConnectComponent;
});
