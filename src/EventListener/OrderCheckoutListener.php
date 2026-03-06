<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\EventListener;

use Kenzi\OroCommerceBundle\Serializer\OrderPayloadSerializer;
use Kenzi\OroCommerceBundle\Webhook\WebhookDispatcher;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\Action\Event\ExtendableActionEvent;
use Psr\Log\LoggerInterface;

/**
 * Listens for the checkout completion event and dispatches an order.created webhook to Kenzi.
 *
 * The extendable_action.finish_checkout event is dispatched by OroCommerce's
 * PlaceOrder and Purchase workflow transitions. The event data contains:
 *   - 'order'        => Order entity
 *   - 'checkout'     => Checkout entity
 *   - 'responseData' => payment response array
 *   - 'email'        => customer email string
 *
 * Access via $event->getData()->get('order'), NOT $event->getContext().
 *
 * The order's Website is passed to the dispatcher so config is read for the
 * correct website (each OroCommerce Website can have its own Kenzi connection).
 */
class OrderCheckoutListener
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly OrderPayloadSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFinishCheckout(ExtendableActionEvent $event): void
    {
        $data = $event->getData();

        if ($data === null) {
            return;
        }

        $order = $data->get('order');

        if (!$order instanceof Order) {
            $this->logger->debug('Kenzi: finish_checkout event without Order entity, skipping');
            return;
        }

        $website = $order->getWebsite();

        if (!$this->dispatcher->isEnabledForWebsite($website)) {
            $this->logger->debug('Kenzi: order.created webhook skipped, sync not enabled for website', [
                'website_id' => $website?->getId(),
            ]);

            return;
        }

        try {
            $payload = $this->serializer->serialize($order, 'order.created');
            $this->dispatcher->dispatch($payload, 'order.created', $order->getWebsite());
        } catch (\Throwable $e) {
            $this->logger->error('Kenzi: order.created webhook dispatch failed', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
