<?php

declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Controller;

use Brille24\SyliusCustomerOptionsPlugin\Entity\OrderItemInterface;
use Brille24\SyliusCustomerOptionsPlugin\Enumerations\CustomerOptionTypeEnum;
use Brille24\SyliusCustomerOptionsPlugin\Form\Product\ShopCustomerOptionType;
use Brille24\SyliusCustomerOptionsPlugin\Services\OrderItemOptionUpdaterInterface;
use DateTime;
use Exception;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderItemRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;
use Symfony\Component\Routing\RouterInterface;

class EditCustomerOptionsAction
{
    /** @var Environment */
    private $twig;

    /** @var RouterInterface */
    private $router;

    /** @var FormFactoryInterface */
    private $formFactory;

    /** @var OrderItemRepositoryInterface */
    private $orderItemRepository;

    /** @var OrderItemOptionUpdaterInterface */
    private $orderItemOptionUpdater;

    /** @var EventDispatcherInterface */
    private $eventDispatcher;

    /** @var bool */
    private $recalculatePrice;

    public function __construct(
        Environment $twig,
        RouterInterface $router,
        FormFactoryInterface $formFactory,
        OrderItemRepositoryInterface $orderItemRepository,
        OrderItemOptionUpdaterInterface $orderItemOptionUpdater,
        EventDispatcherInterface $eventDispatcher,
        bool $recalculatePrice
    ) {
        $this->twig                   = $twig;
        $this->router = $router;
        $this->formFactory            = $formFactory;
        $this->orderItemRepository    = $orderItemRepository;
        $this->orderItemOptionUpdater = $orderItemOptionUpdater;
        $this->eventDispatcher        = $eventDispatcher;
        $this->recalculatePrice       = $recalculatePrice;
    }

    /**
     * @param Request $request
     *
     * @return Response
     *
     * @throws Exception
     */
    public function __invoke(Request $request): Response
    {
        /** @var OrderItemInterface|null $orderItem */
        $orderItem = $this->orderItemRepository->find($request->attributes->get('orderItem'));
        Assert::notNull($orderItem);

        $this->eventDispatcher->dispatch(
            new ResourceControllerEvent($orderItem),
            'brille24.order_item.pre_update'
        );

        /** @var OrderInterface $order */
        $order = $orderItem->getOrder();

        $orderItemForm = $this->formFactory->create(
            ShopCustomerOptionType::class,
            $this->getCustomerOptionValues($orderItem),
            ['product' => $orderItem->getProduct(), 'channel' => $order->getChannel(), 'mapped' => true]
        );

        $orderItemForm->handleRequest($request);

        if ($orderItemForm->isSubmitted() && $orderItemForm->isValid()) {
            $this->orderItemOptionUpdater->updateOrderItemOptions($orderItem, $orderItemForm->getData(), $this->recalculatePrice);

            $this->eventDispatcher->dispatch(
                new ResourceControllerEvent($orderItem),
                'brille24.order_item.post_update'
            );

            return new RedirectResponse($this->router->generate('sylius_admin_order_show', ['id' => $order->getId()]));
        }

        return new Response(
            $this->twig->render('@Brille24SyliusCustomerOptionsPlugin/Order/editCustomerOption.html.twig', [
                'customerOptionForm' => $orderItemForm->createView(),
                'order'              => $order,
            ])
        );
    }

    /**
     * @param OrderItemInterface $orderItem
     *
     * @return array
     *
     * @throws Exception
     */
    private function getCustomerOptionValues(OrderItemInterface $orderItem): array
    {
        $optionAsAssociativeArray = [];
        $orderItemOptions         = $orderItem->getCustomerOptionConfiguration();

        foreach ($orderItemOptions as $orderItemOption) {
            $customerOption     = $orderItemOption->getCustomerOption();
            $customerOptionType = $customerOption->getType();
            $code               = $orderItemOption->getCustomerOptionCode();

            // Select options use CustomerOptionValues
            if ($customerOptionType === CustomerOptionTypeEnum::MULTI_SELECT) {
                $optionAsAssociativeArray[$code][] = $orderItemOption->getCustomerOptionValue();
            } elseif ($customerOptionType === CustomerOptionTypeEnum::SELECT) {
                $optionAsAssociativeArray[$code] = $orderItemOption->getCustomerOptionValue();
            } else {
                $optionAsAssociativeArray[$code] = $this->transformValue($customerOptionType, $orderItemOption->getScalarValue());
            }
        }

        return $optionAsAssociativeArray;
    }

    /**
     * @param string $customerOptionType
     * @param string|null $value
     *
     * @return bool|DateTime|float|string|null
     *
     * @throws Exception
     */
    private function transformValue(string $customerOptionType, ?string $value)
    {
        if (null === $value) {
            return null;
        }

        switch ($customerOptionType) {
            case CustomerOptionTypeEnum::BOOLEAN:
                return (bool) $value;
            case CustomerOptionTypeEnum::NUMBER:
                return (float) $value;
            case CustomerOptionTypeEnum::DATE:
            case CustomerOptionTypeEnum::DATETIME:
                return new DateTime($value);
            case CustomerOptionTypeEnum::FILE:
                // @TODO: Find a way to handle file options
                return null;
            default:
                return $value;
        }
    }
}
