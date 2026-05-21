<?php

namespace App\Controller;

use App\Entity\CustomizationRequest;
use App\Entity\Orders;
use App\Entity\Products;
use App\Entity\User;
use App\Repository\OrdersRepository;
use App\Repository\ProductsRepository;
use App\Service\VerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ApiController extends AbstractController
{
    #[Route('/customer/products', name: 'api_customer_products', methods: ['GET'])]
    public function products(ProductsRepository $productsRepository): JsonResponse
    {
        $products = $productsRepository->findAll();
        $data = array_map(fn ($product) => $this->formatProduct($product), $products);

        return $this->json([
            'success' => true,
            'message' => 'Products fetched successfully',
            'data' => [
                'products' => $data,
            ],
            'meta' => [
                'count' => count($data),
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/customer/products/{id}', name: 'api_customer_product_show', methods: ['GET'])]
    public function product(int $id, ProductsRepository $productsRepository): JsonResponse
    {
        $product = $productsRepository->find($id);
        if (!$product) {
            return $this->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return $this->json([
            'success' => true,
            'message' => 'Product fetched successfully',
            'data' => [
                'product' => $this->formatProduct($product),
            ],
            'meta' => [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/customer/me', name: 'api_customer_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CUSTOMER');
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        return $this->json([
            'success' => true,
            'message' => 'Profile fetched successfully',
            'data' => [
                'user' => $this->formatUser($user),
            ],
            'meta' => [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/customer/orders', name: 'api_customer_orders', methods: ['GET'])]
    public function orders(OrdersRepository $ordersRepository): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CUSTOMER');
        $currentUser = $this->getUser();
        $orders = $ordersRepository->findBy(['processedBy' => $currentUser]);

        $data = array_map(fn (Orders $order) => $this->formatOrder($order), $orders);

        return $this->json([
            'success' => true,
            'message' => 'Orders fetched successfully',
            'data' => [
                'orders' => $data,
            ],
            'meta' => [
                'count' => count($data),
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/customer/orders', name: 'api_customer_orders_create', methods: ['POST'])]
    public function createOrder(
        Request $request,
        EntityManagerInterface $entityManager,
        ProductsRepository $productsRepository
    ): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CUSTOMER');
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Invalid JSON payload'], 400);
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            return $this->json(['success' => false, 'message' => 'At least one order item is required'], 422);
        }

        $shippingAddress = trim((string) ($payload['shippingAddress'] ?? $payload['shipping_address'] ?? ''));
        $customerName = trim((string) ($payload['customerName'] ?? $payload['customer_name'] ?? $user->getName()));
        $customerEmail = trim((string) ($payload['customerEmail'] ?? $payload['customer_email'] ?? $user->getEmail()));
        if ($customerName === '' || $customerEmail === '') {
            return $this->json(['success' => false, 'message' => 'Customer name and email are required'], 422);
        }
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'A valid customer email is required'], 422);
        }
        if (strlen($shippingAddress) < 8) {
            return $this->json(['success' => false, 'message' => 'A complete shipping address is required'], 422);
        }

        $summary = [];
        $total = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $productId = (int) ($item['id'] ?? $item['productId'] ?? $item['product_id'] ?? 0);
            if ($productId <= 0) {
                return $this->json(['success' => false, 'message' => 'Each order item must include a valid product id'], 422);
            }

            $product = $productsRepository->find($productId);
            if (!$product) {
                return $this->json(['success' => false, 'message' => 'One or more products are no longer available'], 422);
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            if ($quantity < 1 || $quantity > 99) {
                return $this->json(['success' => false, 'message' => 'Product quantity must be between 1 and 99'], 422);
            }

            $name = $product->getName();
            $price = (float) $product->getPrice();
            $total += $price * $quantity;
            $summary[] = sprintf('%s x%d (Php %0.2f)', $name, $quantity, $price);
        }

        if ($summary === []) {
            return $this->json(['success' => false, 'message' => 'Order items are invalid'], 422);
        }

        $order = new Orders();
        $order->setCustomerName($customerName);
        $order->setCustomerEmail($customerEmail);
        $order->setShippingAddress($shippingAddress !== '' ? $shippingAddress : null);
        $order->setOrderedProducts(implode(', ', $summary));
        $order->setStatus('Pending');
        $order->setTotalPrice(number_format($total, 2, '.', ''));
        $order->setProcessedBy($user);

        $entityManager->persist($order);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Order created successfully',
            'data' => [
                'order' => $this->formatOrder($order),
            ],
            'meta' => [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ], 201);
    }

    #[Route('/customer/customization-requests', name: 'api_customer_customization_requests', methods: ['GET'])]
    public function customizationRequests(EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CUSTOMER');
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $requests = $entityManager->getRepository(CustomizationRequest::class)->findBy(
            ['customer' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->json([
            'success' => true,
            'message' => 'Customization requests fetched successfully',
            'data' => [
                'customization_requests' => array_map(
                    fn (CustomizationRequest $request) => $this->formatCustomizationRequest($request),
                    $requests
                ),
            ],
            'meta' => [
                'count' => count($requests),
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }

    #[Route('/customer/customization-requests', name: 'api_customer_customization_requests_create', methods: ['POST'])]
    public function createCustomizationRequest(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_CUSTOMER');
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Invalid JSON payload'], 400);
        }

        $productType = trim((string) ($payload['productType'] ?? $payload['product_type'] ?? ''));
        $designDescription = trim((string) ($payload['designDescription'] ?? $payload['design_description'] ?? ''));
        if (!in_array($productType, ['T-shirt', 'Accessories'], true)) {
            return $this->json(['success' => false, 'message' => 'Choose either T-shirt or Accessories'], 422);
        }
        if (strlen($designDescription) < 10) {
            return $this->json(['success' => false, 'message' => 'Design description must be at least 10 characters'], 422);
        }

        $customizationRequest = new CustomizationRequest();
        $customizationRequest->setCustomer($user);
        $customizationRequest->setProductType($productType);
        $customizationRequest->setBaseColor($this->nullableTrim($payload['baseColor'] ?? $payload['base_color'] ?? null));
        $customizationRequest->setSize($this->nullableTrim($payload['size'] ?? null));
        $customizationRequest->setPlacement($this->nullableTrim($payload['placement'] ?? null));
        $customizationRequest->setDesignDescription($designDescription);
        $customizationRequest->setNotes($this->nullableTrim($payload['notes'] ?? null));

        $entityManager->persist($customizationRequest);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Customization request sent successfully',
            'data' => [
                'customization_request' => $this->formatCustomizationRequest($customizationRequest),
            ],
            'meta' => [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ], 201);
    }

    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        VerificationService $verificationService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Invalid JSON payload'], 400);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        $username = trim((string) ($payload['username'] ?? $email));
        $password = (string) ($payload['password'] ?? '');
        $fullName = trim((string) ($payload['fullName'] ?? $payload['name'] ?? ''));
        $agreeTerms = filter_var($payload['agreeTerms'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($username === '' || $email === '' || $password === '' || $fullName === '') {
            return $this->json([
                'success' => false,
                'message' => 'name, email, and password are required',
            ], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'A valid email address is required'], 422);
        }
        if (strlen($password) < 8) {
            return $this->json(['success' => false, 'message' => 'Password must be at least 8 characters'], 422);
        }
        if (!$agreeTerms) {
            return $this->json(['success' => false, 'message' => 'You must agree to the terms'], 422);
        }

        $existing = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($existing) {
            return $this->json(['success' => false, 'message' => 'Username already exists'], 409);
        }

        $emailExists = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($emailExists) {
            return $this->json(['success' => false, 'message' => 'Email already exists'], 409);
        }

        $user = new User();
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setFullName($fullName !== '' ? $fullName : $username);
        $user->setRoles(['ROLE_CUSTOMER']);
        $user->setPassword($passwordHasher->hashPassword($user, $password));
        $user->setIsVerified(false);

        $entityManager->persist($user);
        $entityManager->flush();

        $verificationService->generateTokenFor($user);
        $verificationService->sendVerificationEmail($user);

        return $this->json([
            'success' => true,
            'message' => 'Registration successful. Verify your email before login.',
            'data' => [
                'user' => $this->formatUser($user),
            ],
            'meta' => [
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
        ], 201);
    }

    private function formatProduct(Products $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'title' => $product->getName(),
            'description' => $product->getDescription(),
            'price' => (float) $product->getPrice(),
            'category' => $product->getCategory()?->getName(),
            'categoryName' => $product->getCategory()?->getName(),
            'image' => $product->getImage(),
            'imageUrl' => $product->getImage() ? '/uploads/images/' . $product->getImage() : null,
            'status' => 'In stock',
        ];
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'verified' => $user->isVerified(),
        ];
    }

    private function formatOrder(Orders $order): array
    {
        return [
            'id' => $order->getId(),
            'customer_name' => $order->getCustomerName(),
            'customer_email' => $order->getCustomerEmail(),
            'shipping_address' => $order->getShippingAddress(),
            'status' => $order->getStatus(),
            'total_price' => (float) $order->getTotalPrice(),
            'ordered_products' => $order->getOrderedProducts(),
            'created_at' => $order->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function formatCustomizationRequest(CustomizationRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'product_type' => $request->getProductType(),
            'base_color' => $request->getBaseColor(),
            'size' => $request->getSize(),
            'placement' => $request->getPlacement(),
            'design_description' => $request->getDesignDescription(),
            'notes' => $request->getNotes(),
            'status' => $request->getStatus(),
            'staff_response' => $request->getStaffResponse(),
            'created_at' => $request->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $request->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

