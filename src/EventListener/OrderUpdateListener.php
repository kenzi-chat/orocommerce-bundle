<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\EventListener;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Kenzi\OroCommerceBundle\Serializer\OrderPayloadSerializer;
use Kenzi\OroCommerceBundle\Webhook\WebhookDispatcher;
use Oro\Bundle\OrderBundle\Entity\Order;
use Psr\Log\LoggerInterface;

/**
 * Listens for Order entity updates via Doctrine entity lifecycle events
 * and dispatches order.updated webhooks to Kenzi.
 *
 * Uses the doctrine.orm.entity_listener tag (NOT doctrine.event_listener).
 * OroCommerce uses Doctrine\Persistence\Event\LifecycleEventArgs,
 * NOT Doctrine\ORM\Event\PostUpdateEventArgs.
 *
 * The Order entity is passed as the first parameter (type-hinted),
 * not accessed via $args->getObject().
 *
 * The order's Website is passed to the dispatcher for config scoping.
 */
class OrderUpdateListener
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly OrderPayloadSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postUpdate(Order $order, LifecycleEventArgs $args): void
    {
        $website = $order->getWebsite();

        if (!$this->dispatcher->isEnabledForWebsite($website)) {
            $this->logger->debug('Kenzi: order.updated webhook skipped, sync not enabled for website', [
                'website_id' => $website?->getId(),
            ]);

            return;
        }

        try {
            $payload = $this->serializer->serialize($order, 'order.updated');
            $this->dispatcher->dispatch($payload, 'order.updated', $order->getWebsite());
        } catch (\Throwable $e) {
            $this->logger->error('Kenzi: order.updated webhook dispatch failed', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
