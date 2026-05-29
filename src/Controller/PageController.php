<?php

namespace App\Controller;

use App\Entity\Orders;
use App\Entity\User;
use App\Entity\CustomizationRequest;
use App\Repository\OrdersRepository;
use App\Repository\ProductsRepository;
use App\Service\RealtimePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PageController extends AbstractController
{
    #[Route('/brand-video', name: 'app_brand_video', methods: ['GET'])]
    public function brandVideo(): BinaryFileResponse
    {
        $videoPath = $this->getParameter('kernel.project_dir') . '/assets/video/SKD - 2025.mp4';
        if (!is_file($videoPath)) {
            throw new NotFoundHttpException('Brand video not found.');
        }

        return new BinaryFileResponse($videoPath);
    }

    #[Route('/design-assets/{name}', name: 'app_design_asset', requirements: ['name' => '[^/]+'], methods: ['GET'])]
    public function designAsset(string $name): BinaryFileResponse
    {
        $path = $this->findDesignAssetPath($name);
        if ($path) {
            return new BinaryFileResponse($path);
        }

        throw new NotFoundHttpException('Design asset not found.');
    }

    #[Route('/page', name: 'app_page')]
    public function home(): Response
    {
        return $this->render('page/index.html.twig');
    }

    #[Route('/about', name: 'app_page_about')]
    public function about(): Response
    {
        return $this->render('page/about.html.twig');
    }

    #[Route('/contact', name: 'app_page_contact', methods: ['GET', 'POST'])]
    public function contact(Request $request, MailerInterface $mailer): Response
    {
        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('contact', $token)) {
                $this->addFlash('error', 'Invalid form token. Please try again.');
                return $this->redirectToRoute('app_page_contact');
            }

            $name = trim((string) $request->request->get('name', ''));
            $fromEmail = trim((string) $request->request->get('email', ''));
            $topic = trim((string) $request->request->get('topic', ''));
            $message = trim((string) $request->request->get('message', ''));

            if ($name === '' || $fromEmail === '' || $message === '') {
                $this->addFlash('error', 'Please fill in your name, email, and message.');
                return $this->redirectToRoute('app_page_contact');
            }

            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $this->addFlash('error', 'Please enter a valid email address.');
                return $this->redirectToRoute('app_page_contact');
            }

            // Use env overrides when present (easy testing to your Gmail).
            // Note: Brevo may reject unverified "From" addresses, so Reply-To is always the user.
            $to = $_ENV['CONTACT_TO_EMAIL'] ?? 'support@skadoosh.com';
            $from = $_ENV['CONTACT_FROM_EMAIL'] ?? 'a56f02001@smtp-brevo.com';

            $email = (new Email())
                ->from($from)
                ->to($to)
                ->replyTo($fromEmail)
                ->subject('SKADOOSH Contact: ' . ($topic !== '' ? $topic : 'Message'))
                ->text(
                    "Name: {$name}\n" .
                    "Email: {$fromEmail}\n" .
                    "Topic: " . ($topic !== '' ? $topic : '—') . "\n\n" .
                    $message . "\n"
                );

            try {
                $mailer->send($email);
                return $this->redirectToRoute('app_page_contact', ['sent' => 1]);
            } catch (TransportExceptionInterface $e) {
                $this->addFlash('error', 'Email could not be sent. Check MAILER_DSN (Brevo) configuration.');
                return $this->redirectToRoute('app_page_contact');
            }
        }

        return $this->render('page/contact.html.twig');
    }

    #[Route('/accessories', name: 'app_page_accessories')]
    public function accessories(): Response
    {
        return $this->render('page/shirts.html.twig');
    }

    #[Route('/shop', name: 'app_shop')]
    public function shop(Request $request, ProductsRepository $productsRepository): Response
    {
        $category = strtolower((string) $request->query->get('category', ''));
        $query = trim((string) $request->query->get('q', ''));
        $products = $productsRepository->findForShop($category, 24, $query);

        return $this->render('page/shop.html.twig', [
            'products' => $products,
            'activeCategory' => $category,
            'searchQuery' => $query,
        ]);
    }

    #[Route('/cart', name: 'app_cart', methods: ['GET'])]
    public function cart(ProductsRepository $productsRepository): Response
    {
        $suggestions = $productsRepository->findBy([], ['id' => 'DESC'], 8);

        return $this->render('page/cart.html.twig', [
            'suggestions' => $suggestions,
        ]);
    }

    #[Route('/cart/checkout', name: 'app_cart_checkout', methods: ['POST'])]
    public function checkout(
        Request $request,
        ProductsRepository $productsRepository,
        EntityManagerInterface $entityManager,
        RealtimePublisher $realtimePublisher
    ): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'Please log in before checking out.'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if ($items === []) {
            return $this->json(['success' => false, 'message' => 'Your cart is empty.'], 422);
        }

        $summary = [];
        $total = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int) ($item['id'] ?? 0);
            $product = $productId > 0 ? $productsRepository->find($productId) : null;
            if (!$product) {
                return $this->json(['success' => false, 'message' => 'One or more cart products are no longer available.'], 422);
            }

            $quantity = max(1, min(99, (int) ($item['quantity'] ?? 1)));
            $price = (float) $product->getPrice();
            $total += $price * $quantity;

            $details = [];
            if (!empty($item['color'])) {
                $details[] = 'Color: ' . trim((string) $item['color']);
            }
            if (!empty($item['size'])) {
                $details[] = 'Size: ' . trim((string) $item['size']);
            }

            $summary[] = sprintf(
                '%s x%d (Php %0.2f)%s',
                $product->getName(),
                $quantity,
                $price,
                $details ? ' - ' . implode(', ', $details) : ''
            );
        }

        if ($summary === []) {
            return $this->json(['success' => false, 'message' => 'Your cart items are invalid.'], 422);
        }

        $customerName = $user->getFullName() ?: $user->getUsername();
        $customerEmail = $user->getEmail() ?: $user->getUsername();

        $order = new Orders();
        $order->setCustomerName($customerName);
        $order->setCustomerEmail($customerEmail);
        $order->setShippingAddress('No shipping address provided');
        $order->setOrderedProducts(implode("\n", $summary));
        $order->setStatus('Pending');
        $order->setTotalPrice(number_format($total, 2, '.', ''));
        $order->setProcessedBy($user);

        $entityManager->persist($order);
        $entityManager->flush();
        $realtimePublisher->orderCreated($order);

        return $this->json([
            'success' => true,
            'message' => 'Order placed successfully.',
            'receiptUrl' => $this->generateUrl('app_order_receipt', ['id' => $order->getId()]),
        ], 201);
    }

    #[Route('/receipt/{id}', name: 'app_order_receipt', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function receipt(int $id, OrdersRepository $ordersRepository): Response
    {
        $order = $ordersRepository->find($id);
        if (!$order) {
            throw new NotFoundHttpException('Receipt not found.');
        }

        $this->denyUnlessOrderOwnerOrStaff($order);

        return $this->render('page/receipt.html.twig', [
            'order' => $order,
        ]);
    }

    #[Route('/my-orders', name: 'app_my_orders', methods: ['GET'])]
    public function myOrders(OrdersRepository $ordersRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('warning', 'Please log in to view your orders.');

            return $this->redirectToRoute('app_login');
        }

        $orders = $ordersRepository->findForCustomer($user);

        return $this->render('page/my_orders.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/account', name: 'app_customer_profile', methods: ['GET'])]
    public function customerProfile(OrdersRepository $ordersRepository, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('warning', 'Please log in to view your profile.');

            return $this->redirectToRoute('app_login');
        }

        $orders = $ordersRepository->findForCustomer($user);
        $customizationRequests = $entityManager->getRepository(CustomizationRequest::class)->findBy(
            ['customer' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('page/customer_profile.html.twig', [
            'user' => $user,
            'orders' => $orders,
            'customizationRequests' => $customizationRequests,
        ]);
    }

    #[Route('/my-customization-requests', name: 'app_my_customization_requests', methods: ['GET'])]
    public function myCustomizationRequests(EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('warning', 'Please log in to view your customization requests.');

            return $this->redirectToRoute('app_login');
        }

        $customizationRequests = $entityManager->getRepository(CustomizationRequest::class)->findBy(
            ['customer' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('page/my_customization_requests.html.twig', [
            'customizationRequests' => $customizationRequests,
        ]);
    }

    #[Route('/shop/{id}', name: 'app_shop_product', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function shopProduct(int $id, Request $request, ProductsRepository $productsRepository): Response
    {
        $product = $productsRepository->find($id);
        if (!$product) {
            throw new NotFoundHttpException('Product not found.');
        }

        // Uniqlo-like query params
        $colorCode = (string) $request->query->get('colorCode', 'COL01');
        $sizeCode = (string) $request->query->get('sizeCode', 'SMA003');

        // Simple option sets (UI-only). If you later add real variants, replace these arrays.
        $colors = [
            ['code' => 'COL01', 'name' => 'White', 'hex' => '#f8fafc'],
            ['code' => 'COL45', 'name' => 'Green', 'hex' => '#22c55e'],
            ['code' => 'COL63', 'name' => 'Blue', 'hex' => '#2563eb'],
            ['code' => 'COL65', 'name' => 'Pink', 'hex' => '#f9a8d4'],
        ];
        $colors = array_map(function (array $color) use ($product): array {
            $assetName = $this->findFirstDesignAssetName($this->buildColorAssetCandidates($product->getName() ?? '', $color['name']));
            if ($assetName) {
                $color['imageUrl'] = $this->generateUrl('app_design_asset', ['name' => $assetName]);
            }

            return $color;
        }, $colors);
        if (!in_array($colorCode, array_column($colors, 'code'), true)) {
            $colorCode = 'COL01';
        }

        $sizes = [
            ['code' => 'SMA002', 'label' => 'XS'],
            ['code' => 'SMA003', 'label' => 'S'],
            ['code' => 'SMA004', 'label' => 'M'],
            ['code' => 'SMA005', 'label' => 'L'],
            ['code' => 'SMA006', 'label' => 'XL'],
            ['code' => 'SMA007', 'label' => 'XXL'],
        ];

        return $this->render('page/product.html.twig', [
            'product' => $product,
            'colorCode' => $colorCode,
            'sizeCode' => $sizeCode,
            'colors' => $colors,
            'sizes' => $sizes,
        ]);
    }

    private function findDesignAssetPath(string $name): ?string
    {
        $directories = [
            $this->getParameter('kernel.project_dir') . '/assets/IMAGES',
            $this->getParameter('kernel.project_dir') . '/assets/IMAGES/shop_colors',
        ];

        foreach ($directories as $directory) {
            foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
                $path = $directory . '/' . $name . '.' . $extension;
                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * @param string[] $names
     */
    private function findFirstDesignAssetName(array $names): ?string
    {
        foreach ($names as $name) {
            if ($this->findDesignAssetPath($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function buildColorAssetCandidates(string $productName, string $colorName): array
    {
        $title = trim($productName);
        $color = trim($colorName);
        $titleSlug = $this->assetSlug($title);
        $colorSlug = $this->assetSlug($color);

        return array_values(array_unique(array_filter([
            "{$title} {$color}",
            "{$title}_{$color}",
            "{$title}-{$color}",
            "{$titleSlug}_{$colorSlug}",
            "{$titleSlug}-{$colorSlug}",
            "{$titleSlug}{$colorSlug}",
            strtolower("{$title} {$color}"),
            strtolower("{$title}_{$color}"),
            strtolower("{$title}-{$color}"),
            "color_{$colorSlug}",
            "shop_{$colorSlug}",
            $colorSlug,
        ])));
    }

    private function assetSlug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/i', '_', $slug) ?? '';

        return trim($slug, '_');
    }

    private function denyUnlessOrderOwnerOrStaff(Orders $order): void
    {
        if ($this->isGranted('ROLE_STAFF') || $this->isGranted('ROLE_ADMIN')) {
            return;
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $emails = array_filter([$user->getEmail(), $user->getUsername()]);
            if ($order->getProcessedBy()?->getId() === $user->getId() || in_array($order->getCustomerEmail(), $emails, true)) {
                return;
            }
        }

        throw $this->createAccessDeniedException('You cannot view this receipt.');
    }
}  
