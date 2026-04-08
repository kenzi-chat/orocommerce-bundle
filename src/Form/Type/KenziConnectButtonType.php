<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Form\Type;

use Kenzi\OroCommerceBundle\Application\ApplicationUrlResolver;
use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Renders the Kenzi connect/disconnect button on the system configuration page.
 *
 * This is a ui_only form type — it does not store a value itself.
 * The actual credentials are stored by ConnectController via AJAX.
 * The form view receives connection state so the Twig template can
 * show the appropriate UI (connect button vs. connected status).
 *
 * @extends AbstractType<null>
 */
class KenziConnectButtonType extends AbstractType
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ApplicationUrlResolver $urlResolver,
    ) {
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'mapped' => false,
        ]);
    }

    /**
     * Passes connection state to the template.
     *
     * Connection config is global (one Oro instance = one Kenzi integration).
     * The instance_key is the application hostname, derived from Oro's
     * oro_ui.application_url system config. The api_url is the full
     * back-office API base URL (scheme + host + /admin/api) for backfill requests.
     */
    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // Connection config is global — one integration per Oro instance.
        $connectedAt = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_CONNECTED_AT)
        );
        $workspaceId = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_WORKSPACE_ID)
        );
        $instanceKey = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_INSTANCE_KEY)
        );

        // Before the first connection, instance_key is empty in config.
        // Derive it from the application hostname so the popup receives
        // an instance_key for the initial handshake.
        if ($instanceKey === '') {
            $instanceKey = $this->urlResolver->instanceKey();
        }

        // app_base_url is the Kenzi app origin — used to open the connect popup
        // and validate incoming postMessage events. Seeded by data migration
        // from the KENZI_APP_BASE env var (or defaults to https://app.kenzi.chat).
        $kenziOrigin = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_APP_BASE_URL)
        );

        // `credentials_delivered` is false until OAuth2 client_id/secret have
        // been successfully PATCHed to Kenzi. When `is_connected` is true but
        // this flag is false, the integration is partially connected —
        // webhooks work, but backfill cannot authenticate yet.
        $credentialsDelivered = (bool) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_CREDENTIALS_DELIVERED)
        );

        $view->vars['is_connected'] = $connectedAt !== '';
        $view->vars['credentials_delivered'] = $credentialsDelivered;
        $view->vars['workspace_id'] = $workspaceId;
        $view->vars['instance_key'] = $instanceKey;
        $view->vars['connected_at'] = $connectedAt;
        $view->vars['kenzi_origin'] = $kenziOrigin;
        $view->vars['admin_url'] = $this->urlResolver->adminUrl();
        $view->vars['api_url'] = $this->urlResolver->apiUrl();
        $view->vars['token_url'] = $this->urlResolver->tokenUrl();
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'kenzi_connect_button';
    }
}
