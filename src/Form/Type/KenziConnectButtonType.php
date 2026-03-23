<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Form\Type;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

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
        private readonly RouterInterface $router,
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
     * Passes connection state and the scoped Website ID to the template.
     *
     * When the admin views the website_configuration page, Oro sets
     * the ConfigManager scope before building the form. All config reads
     * resolve for the selected Website automatically. The scope ID
     * (website entity ID) is passed to the JS for the connect/disconnect
     * AJAX calls to the ConnectController.
     */
    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $connectedAt = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_CONNECTED_AT)
        );
        $workspaceId = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_WORKSPACE_ID)
        );
        $storeKey = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_STORE_KEY)
        );

        // getScopeId() returns the current website ID when on the
        // website_configuration page, or 0 when on global scope.
        //
        // Design decision (KZP-218): websiteId=0 is intentionally allowed.
        // The original spec called for disabling the button on global scope,
        // but CE editions only have global scope. Allowing websiteId=0 lets
        // the connect flow work on both CE (global) and EE (per-website).
        // ConnectController handles the null-website path accordingly.
        $websiteId = $this->configManager->getScopeId();

        // Before the first connection, store_key is empty in config.
        // Derive it from the Website entity's URL hostname so the popup
        // receives a store_key for the initial handshake.
        // Works on both CE (global scope, websiteId=0) and EE (website
        // scope, websiteId>0) — oro_website.url resolves via Oro's
        // config cascade regardless of scope.
        if ($storeKey === '') {
            $websiteUrl = (string) $this->configManager->get('oro_website.url');
            $storeKey = (string) parse_url($websiteUrl, PHP_URL_HOST);
        }

        // app_base_url is the Kenzi app origin — used to open the connect popup
        // and validate incoming postMessage events. Seeded by data migration
        // from the KENZI_APP_BASE env var (or defaults to https://app.kenzi.chat).
        $kenziOrigin = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_APP_BASE_URL)
        );

        // Generate the absolute URL to the Oro admin dashboard.
        // Kenzi stores this so agents can deep-link directly to orders,
        // customers, and products in the admin panel.
        $adminUrl = rtrim(
            $this->router->generate('oro_default', [], UrlGeneratorInterface::ABSOLUTE_URL),
            '/'
        );

        $view->vars['is_connected'] = $connectedAt !== '';
        $view->vars['workspace_id'] = $workspaceId;
        $view->vars['store_key'] = $storeKey;
        $view->vars['connected_at'] = $connectedAt;
        $view->vars['website_id'] = $websiteId;
        $view->vars['kenzi_origin'] = $kenziOrigin;
        $view->vars['admin_url'] = $adminUrl;
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'kenzi_connect_button';
    }
}
