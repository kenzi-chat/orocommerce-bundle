<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Form\Type;

use Kenzi\OroCommerceBundle\DependencyInjection\Configuration;
use Oro\Bundle\ConfigBundle\Config\ConfigManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<bool>
 */
class KenziWidgetToggleType extends AbstractType
{
    public function __construct(
        private readonly ConfigManager $configManager,
    ) {
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $secret = (string) $this->configManager->get(
            Configuration::getConfigKeyByName(Configuration::PARAM_NAME_SHARED_SECRET)
        );

        $resolver->setDefaults([
            'disabled' => $secret === '',
        ]);
    }

    #[\Override]
    public function getParent(): string
    {
        return CheckboxType::class;
    }
}
