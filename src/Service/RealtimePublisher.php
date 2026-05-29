<?php

namespace App\Service;

use App\Entity\CustomizationRequest;
use App\Entity\Orders;
use App\Entity\RealtimeEvent;
use Doctrine\ORM\EntityManagerInterface;

class RealtimePublisher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FirebaseNotificationSender $firebaseNotificationSender,
    ) {
    }

    public function orderCreated(Orders $order): void
    {
        $this->publish('order:created', $this->formatOrder($order), $this->roomsForCustomer($order->getProcessedBy()?->getId()));
    }

    public function orderUpdated(Orders $order): void
    {
        $this->publish('order:updated', $this->formatOrder($order), $this->roomsForCustomer($order->getProcessedBy()?->getId()));
        $this->firebaseNotificationSender->sendOrderUpdate($order);
    }

    public function customizationCreated(CustomizationRequest $request): void
    {
        $this->publish(
            'customization:created',
            $this->formatCustomizationRequest($request),
            $this->roomsForCustomer($request->getCustomer()?->getId())
        );
    }

    public function customizationUpdated(CustomizationRequest $request): void
    {
        $this->publish(
            'customization:updated',
            $this->formatCustomizationRequest($request),
            $this->roomsForCustomer($request->getCustomer()?->getId())
        );
        $this->firebaseNotificationSender->sendCustomizationUpdate($request);
    }

    private function publish(string $event, array $payload, array $rooms): void
    {
        $payload['rooms'] = $rooms;

        $this->entityManager->persist(new RealtimeEvent($event, $payload));
        $this->entityManager->flush();
    }

    private function roomsForCustomer(?int $customerId): array
    {
        $rooms = ['admin'];
        if ($customerId !== null) {
            $rooms[] = 'customer:' . $customerId;
        }

        return $rooms;
    }

    private function formatOrder(Orders $order): array
    {
        return [
            'id' => $order->getId(),
            'customer_id' => $order->getProcessedBy()?->getId(),
            'customer_name' => $order->getCustomerName(),
            'customer_email' => $order->getCustomerEmail(),
            'shipping_address' => $order->getShippingAddress(),
            'status' => $order->getStatus(),
            'total_price' => (float) $order->getTotalPrice(),
            'ordered_products' => $order->getOrderedProducts(),
            'created_at' => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $order->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function formatCustomizationRequest(CustomizationRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'customer_id' => $request->getCustomer()?->getId(),
            'customer_name' => $request->getCustomer()?->getName(),
            'product_type' => $request->getProductType(),
            'base_color' => $request->getBaseColor(),
            'size' => $request->getSize(),
            'placement' => $request->getPlacement(),
            'design_description' => $request->getDesignDescription(),
            'notes' => $request->getNotes(),
            'design_snapshot' => $request->getDesignSnapshot(),
            'status' => $request->getStatus(),
            'staff_response' => $request->getStaffResponse(),
            'created_at' => $request->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $request->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
