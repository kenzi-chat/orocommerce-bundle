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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Renders the Kenzi connect/disconnect button on the system configuration page.
 *
 * This is a ui_only form type — it does not store a value itself. The form
 * view emits a bootstrap dict consumed by `kenzi-connect-component.js`. The
 * JS owns the full lifecycle (connect popup, configure, integration GET,
 * disconnect) so this class never reads connection state — only the
 * `secret_exists` short-circuit flag.
 *
 * @extends AbstractType<null>
 */
class KenziConnectButtonType extends AbstractType
{
    private const SUPPORTED_GRANTS = ['commerce'];

    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ApplicationUrlResolver $urlResolver,
        private readonly UrlGeneratorInterface $router,
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
     * Emit bootstrap data for the JS component.
     *
     * One fact about connection state crosses this boundary:
     *   - `secret_exists` — drives the loading-flow short-circuit. When
     *     false, JS renders the connect button without an outbound HTTP
     *     call.
     *
     * Everything else is derived from operator config (URLs) or static
     * (supported grants).
     */
    #[\Override]
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $appBaseUrl = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_APP_BASE_URL)
        );
        $sharedSecret = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_SHARED_SECRET)
        );

        $view->vars['kenzi_bootstrap'] = [
            'kenzi_app_origin' => $appBaseUrl,
            'instance_key' => $this->urlResolver->instanceKey(),
            'supported_grants' => self::SUPPORTED_GRANTS,
            'endpoints' => [
                'connect' => $this->router->generate('kenzi_connect'),
                'configure' => $this->router->generate('kenzi_configure'),
                'integration' => $this->router->generate('kenzi_integration'),
                'disconnect' => $this->router->generate('kenzi_disconnect'),
            ],
            'secret_exists' => $sharedSecret !== '',
        ];
    }

    #[\Override]
    public function getBlockPrefix(): string
    {
        return 'kenzi_connect_button';
    }
}
