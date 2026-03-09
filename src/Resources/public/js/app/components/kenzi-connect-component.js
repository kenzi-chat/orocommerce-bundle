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
            kenziOrigin: '',
            websiteId: '',
            storeKey: ''
        },

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

            KenziConnectComponent.__super__.initialize.call(this, options);
        },

        /**
         * Handle "Connect to Kenzi" button click.
         * Opens the connect popup and sets up postMessage listener.
         */
        onConnect: function(e) {
            const $button = $(e.currentTarget);
            const kenziOrigin = this.options.kenziOrigin;
            const storeKey = this.options.storeKey;
            const websiteId = this.options.websiteId;
            const self = this;

            if (!kenziOrigin) {
                mediator.execute(
                    'showFlashMessage', 'error',
                    __('kenzi_oro_commerce.connect.error.no_webhook_url')
                );
                return;
            }

            const nonce = crypto.randomUUID();

            const params = new URLSearchParams({
                platform: 'oro_commerce',
                store_key: storeKey || '',
                nonce: nonce,
                origin: window.location.origin
            });

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

                if (!data || data.type !== 'kenzi:connected') {
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
                    secret: data.secret,
                    website_id: parseInt(websiteId, 10)
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
            $.ajax({
                url: this.options.storeUrl,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(credentials)
            }).done(function() {
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
         * Handle "Disconnect" button click.
         */
        onDisconnect: function(e) {
            const $button = $(e.currentTarget);
            const websiteId = this.options.websiteId;
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
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({website_id: parseInt(websiteId, 10)})
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
