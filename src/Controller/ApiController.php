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
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        $snapshotPath = $this->saveDesignSnapshot((string) ($payload['designSnapshot'] ?? $payload['design_snapshot'] ?? ''));
        if (!$snapshotPath) {
            $snapshotPath = $this->saveGeneratedDesignSnapshot($payload['designMockup'] ?? $payload['design_mockup'] ?? null, $productType);
        }
        if ($snapshotPath) {
            $customizationRequest->setDesignSnapshot($snapshotPath);
        }

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

    #[Route('/auth/google', name: 'api_auth_google', methods: ['POST'])]
    public function googleAuth(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        HttpClientInterface $httpClient,
        JWTTokenManagerInterface $jwtManager
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['success' => false, 'message' => 'Invalid JSON payload'], 400);
        }

        $idToken = trim((string) ($payload['idToken'] ?? ''));
        if ($idToken === '') {
            return $this->json(['success' => false, 'message' => 'Google ID token is required'], 422);
        }

        try {
            $googleResponse = $httpClient->request('GET', 'https://oauth2.googleapis.com/tokeninfo', [
                'query' => ['id_token' => $idToken],
            ]);
            $googleData = $googleResponse->toArray(false);
        } catch (\Throwable) {
            return $this->json(['success' => false, 'message' => 'Unable to verify Google sign-in token'], 401);
        }

        if ($googleResponse->getStatusCode() !== 200) {
            return $this->json(['success' => false, 'message' => 'Invalid Google sign-in token'], 401);
        }

        $audience = (string) ($googleData['aud'] ?? '');
        $allowedAudiences = array_filter([
            $_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID'] ?? null,
            $_ENV['OAUTH_GOOGLE_CLIENT_ID'] ?? $_SERVER['OAUTH_GOOGLE_CLIENT_ID'] ?? null,
        ]);
        if ($allowedAudiences !== [] && !in_array($audience, $allowedAudiences, true)) {
            return $this->json(['success' => false, 'message' => 'Google token audience is not allowed'], 401);
        }

        $email = strtolower(trim((string) ($googleData['email'] ?? '')));
        $googleId = trim((string) ($googleData['sub'] ?? ''));
        $name = trim((string) ($googleData['name'] ?? $email));
        $emailVerified = filter_var($googleData['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($googleId === '' || $email === '' || !$emailVerified || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['success' => false, 'message' => 'Google account did not return a verified email'], 401);
        }

        $userRepository = $entityManager->getRepository(User::class);
        $user = $userRepository->findOneBy(['googleId' => $googleId])
            ?? $userRepository->findOneBy(['email' => $email]);

        if (!$user instanceof User) {
            $user = new User();
            $user->setUsername($this->uniqueGoogleUsername($email, $entityManager));
            $user->setEmail($email);
            $user->setRoles(['ROLE_CUSTOMER']);
            $user->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(24))));
            $entityManager->persist($user);
        }

        $user->setGoogleId($googleId);
        $user->setFullName($name !== '' ? $name : $user->getName());
        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $entityManager->flush();

        return $this->json([
            'token' => $jwtManager->create($user),
            'user' => $this->formatUser($user),
        ]);
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
            'design_snapshot' => $request->getDesignSnapshot(),
            'status' => $request->getStatus(),
            'staff_response' => $request->getStaffResponse(),
            'created_at' => $request->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updated_at' => $request->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function uniqueGoogleUsername(string $email, EntityManagerInterface $entityManager): string
    {
        $base = preg_replace('/[^a-z0-9_.-]+/i', '_', strstr($email, '@', true) ?: 'google_user');
        $base = trim((string) $base, '._-') ?: 'google_user';
        $candidate = $base;
        $suffix = 1;
        $repository = $entityManager->getRepository(User::class);

        while ($repository->findOneBy(['username' => $candidate])) {
            $candidate = sprintf('%s_%d', $base, ++$suffix);
        }

        return $candidate;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function saveDesignSnapshot(string $snapshot): ?string
    {
        if (preg_match('/^data:image\/svg\+xml;base64,(.+)$/', $snapshot, $matches)) {
            $svgData = base64_decode($matches[1], true);
            if ($svgData === false) {
                return null;
            }

            return $this->writeDesignSnapshotFile($svgData, 'svg');
        }

        if (!preg_match('/^data:image\/(?:png|jpeg|jpg);base64,(.+)$/', $snapshot, $matches)) {
            return null;
        }

        $imageData = base64_decode($matches[1], true);
        if ($imageData === false) {
            return null;
        }

        return $this->writeDesignSnapshotFile($imageData, 'jpg');
    }

    private function saveGeneratedDesignSnapshot(mixed $mockup, string $productType): ?string
    {
        if (!is_array($mockup)) {
            return null;
        }

        $width = max(260, min(900, (int) ($mockup['width'] ?? 360)));
        $height = max(260, min(900, (int) ($mockup['height'] ?? 420)));
        $items = is_array($mockup['items'] ?? null) ? $mockup['items'] : [];
        $baseLabel = $productType === 'Accessories' ? 'Base tote bag' : 'Base T-shirt';
        $baseShape = $productType === 'Accessories'
            ? '<rect x="32%" y="28%" width="36%" height="48%" rx="18" fill="#f8fafc" stroke="#cbd5e1" stroke-width="3"/><path d="M40 95 C40 52 60 30 80 30 C100 30 120 52 120 95" transform="translate(' . ($width * 0.3) . ' ' . ($height * 0.04) . ') scale(' . ($width / 360) . ')" fill="none" stroke="#cbd5e1" stroke-width="10" stroke-linecap="round"/>'
            : '<path d="M30 110 C48 55 82 35 125 42 L150 72 L175 42 C218 35 252 55 270 110 L232 132 L222 88 L222 292 L78 292 L78 88 L68 132 Z" transform="translate(' . (($width - 300) / 2) . ' ' . (($height - 330) / 2) . ') scale(' . min($width / 330, $height / 360) . ')" fill="#f8fafc" stroke="#cbd5e1" stroke-width="3"/>';

        $svg = [
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '">',
            '<rect width="100%" height="100%" fill="#ffffff"/>',
            '<rect x="8" y="8" width="' . ($width - 16) . '" height="' . ($height - 16) . '" rx="28" fill="#f8fafc" stroke="#e2e8f0" stroke-width="2"/>',
            $baseShape,
            '<text x="' . ($width / 2) . '" y="' . ($height - 28) . '" text-anchor="middle" font-family="Arial, sans-serif" font-size="13" font-weight="700" fill="#7a0000">' . htmlspecialchars($baseLabel, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text>',
        ];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $value = trim((string) ($item['value'] ?? ''));
            if ($value === '') {
                continue;
            }

            $type = (string) ($item['type'] ?? 'text');
            $fontSize = $type === 'sticker' ? 42 : 26;
            $x = max(0, min($width, (float) ($item['x'] ?? ($width / 2))));
            $y = max(0, min($height, (float) ($item['y'] ?? ($height / 2))));
            $svg[] = '<text x="' . $x . '" y="' . $y . '" text-anchor="middle" dominant-baseline="middle" font-family="Arial, sans-serif" font-size="' . $fontSize . '" font-weight="900" fill="#7a0000">' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text>';
        }

        $svg[] = '</svg>';

        return $this->writeDesignSnapshotFile(implode('', $svg), 'svg');
    }

    private function writeDesignSnapshotFile(string $contents, string $extension): ?string
    {
        $relativeDirectory = '/uploads/customization_designs';
        $directory = $this->getParameter('kernel.project_dir') . '/public' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        $extension = $extension === 'svg' ? 'svg' : 'jpg';
        $filename = 'custom-design-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $path = $directory . '/' . $filename;

        return file_put_contents($path, $contents) === false ? null : $relativeDirectory . '/' . $filename;
    }
}

