<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\Orders;
use App\Entity\User;
use App\Form\OrdersType;
use App\Repository\OrdersRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders')]
final class OrdersController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route(name: 'app_orders_index', methods: ['GET'])]
    public function index(OrdersRepository $ordersRepository): Response
    {
        // Require authentication (staff or admin)
        $this->denyAccessUnlessGranted('ROLE_USER');
        // Admin + staff can see all orders (staff can edit/update)
        $orders = $ordersRepository->findAll();

        return $this->render('orders/index.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/new', name: 'app_orders_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        // Only admin can create orders
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $order = new Orders();
        $order->setProcessedBy($this->getUser());
        $form = $this->createForm(OrdersType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedProducts = $form->get('products')->getData();
            if ($selectedProducts && count($selectedProducts) > 0) {
                $productSummary = [];
                foreach ($selectedProducts as $product) {
                    $productSummary[] = sprintf('%s (Php %0.2f)', $product->getName(), $product->getPrice());
                }
                $order->setOrderedProducts(implode(', ', $productSummary));
            }

            $entityManager->persist($order);
            $entityManager->flush();

            // Log activity
            $this->logActivity('Create', 'Order', $order->getId(), 'Order created: ' . $order->getCustomerName());

            $this->addFlash('success', 'Order created successfully!');
            return $this->redirectToRoute('app_orders_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('orders/new.html.twig', [
            'order' => $order,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_orders_show', methods: ['GET'])]
    public function show(Orders $order): Response
    {
        // Require authentication (staff or admin)
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('orders/show.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_orders_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Orders $order, EntityManagerInterface $entityManager): Response
    {
        // Require authentication (staff or admin)
        $this->denyAccessUnlessGranted('ROLE_USER');

        $form = $this->createForm(OrdersType::class, $order);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selectedProducts = $form->get('products')->getData();
            if ($selectedProducts && count($selectedProducts) > 0) {
                $productSummary = [];
                foreach ($selectedProducts as $product) {
                    $productSummary[] = sprintf('%s (Php %0.2f)', $product->getName(), $product->getPrice());
                }
                $order->setOrderedProducts(implode(', ', $productSummary));
            }

            $entityManager->flush();

            // Log activity
            $this->logActivity('Update', 'Order', $order->getId(), 'Order updated: ' . $order->getCustomerName());

            $this->addFlash('success', 'Order updated successfully!');
            return $this->redirectToRoute('app_orders_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('orders/edit.html.twig', [
            'order' => $order,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_orders_delete', methods: ['POST'])]
    public function delete(Request $request, Orders $order, EntityManagerInterface $entityManager): Response
    {
        // Only admin can delete orders
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($this->isCsrfTokenValid('delete'.$order->getId(), $request->getPayload()->getString('_token'))) {
            $orderName = $order->getCustomerName();
            $orderId = $order->getId();
            
            // Log activity before deletion
            $this->logActivity('Delete', 'Order', $orderId, 'Order deleted: ' . $orderName);
            
            $entityManager->remove($order);
            $entityManager->flush();

            $this->addFlash('success', 'Order deleted successfully!');
        }

        return $this->redirectToRoute('app_orders_index', [], Response::HTTP_SEE_OTHER);
    }

    private function logActivity(string $action, string $entityType, ?int $entityId, ?string $description = null): void
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return;
        }

        $log = new ActivityLog();
        $log->setUser($user);
        
        // Get the primary role
        $roles = $user->getRoles();
        $primaryRole = 'ROLE_USER';
        foreach ($roles as $role) {
            if ($role !== 'ROLE_USER') {
                $primaryRole = $role;
                break;
            }
        }
        
        $log->setRole($primaryRole);
        $log->setAction($action);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setDescription($description);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
