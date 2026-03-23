<?php

declare(strict_types=1);

namespace Kenzi\OroCommerceBundle\EventListener;

use Kenzi\OroCommerceBundle\Async\OrderWebhookTopic;
use Oro\Bundle\OrderBundle\Entity\Order;
use Oro\Component\Action\Event\ExtendableActionEvent;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listens for the checkout completion event and enqueues an order.created
 * webhook message for asynchronous dispatch to Kenzi.
 *
 * The extendable_action.finish_checkout event is dispatched by OroCommerce's
 * PlaceOrder and Purchase workflow transitions. The event data contains:
 *   - 'order'        => Order entity
 *   - 'checkout'     => Checkout entity
 *   - 'responseData' => payment response array
 *   - 'email'        => customer email string
 *
 * Access via $event->getData()->get('order'), NOT $event->getContext().
 */
class OrderCheckoutListener
{
    public function __construct(
        private readonly MessageProducerInterface $messageProducer,
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

        $orderId = $order->getId();

        /** @phpstan-ignore identical.alwaysFalse (getId() returns null before persistence) */
        if ($orderId === null) {
            return;
        }

        $this->messageProducer->send(
            OrderWebhookTopic::getName(),
            ['order_id' => $orderId, 'event' => 'order.created']
        );
    }
}
