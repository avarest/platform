<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Cart\Order;

use Shopware\Core\Checkout\Cart\Cart\Cart;
use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\CheckoutContext;
use Shopware\Core\Checkout\Order\Exception\CustomerHasNoActiveBillingAddressException;
use Shopware\Core\Checkout\Order\Exception\DeliveryWithoutAddressException;
use Shopware\Core\Checkout\Order\Exception\EmptyCartException;
use Shopware\Core\Framework\ORM\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\ORM\RepositoryInterface;

class OrderPersister implements OrderPersisterInterface
{
    /**
     * @var RepositoryInterface
     */
    private $repository;

    /**
     * @var OrderConverter
     */
    private $converter;

    public function __construct(RepositoryInterface $repository, OrderConverter $converter)
    {
        $this->repository = $repository;
        $this->converter = $converter;
    }

    /**
     * @throws CustomerNotLoggedInException
     * @throws CustomerHasNoActiveBillingAddressException
     * @throws DeliveryWithoutAddressException
     * @throws EmptyCartException
     */
    public function persist(Cart $cart, CheckoutContext $context): EntityWrittenContainerEvent
    {
        $order = $this->converter->convert($cart, $context);

        return $this->repository->create([$order], $context->getContext());
    }
}
