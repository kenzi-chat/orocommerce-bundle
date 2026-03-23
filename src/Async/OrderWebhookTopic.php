<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\Async;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Defines the message schema for Kenzi order webhook dispatches.
 *
 * Messages are produced by entity listeners (OrderCheckoutListener,
 * OrderUpdateListener) and consumed by OrderWebhookProcessor.
 */
class OrderWebhookTopic extends AbstractTopic
{
    #[\Override]
    public static function getName(): string
    {
        return 'kenzi.webhook.order';
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Dispatches order webhook to Kenzi.';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['order_id', 'event'])
            ->setDefined('retry_count')
            ->addAllowedTypes('order_id', 'int')
            ->addAllowedTypes('event', 'string')
            ->addAllowedTypes('retry_count', 'int')
            ->setDefault('retry_count', 0);
    }
}
