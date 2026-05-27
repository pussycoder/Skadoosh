<?php

namespace App\Controller;

use App\Entity\CustomizationRequest;
use App\Entity\User;
use App\Form\CustomizationRequestStatusType;
use App\Form\CustomizationRequestType;
use App\Service\FcmNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/customization')]
class CustomizationController extends AbstractController
{
    public function __construct(private FcmNotificationService $fcmNotificationService)
    {
    }

    #[Route('', name: 'app_customization_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            $this->addFlash('warning', 'Please log in before sending a customization request.');

            return $this->redirectToRoute('app_login');
        }

        $customizationRequest = new CustomizationRequest();
        $customizationRequest->setCustomer($user);
        $customizationRequest->setProductType('T-shirt');
        $customizationRequest->setBaseColor(null);
        $customizationRequest->setDesignDescription('Customer created a design mockup using the customization editor.');

        $requestedType = strtolower((string) $request->query->get('type', ''));
        if (in_array($requestedType, ['accessory', 'accessories'], true)) {
            $customizationRequest->setProductType('Accessories');
        } elseif (in_array($requestedType, ['shirt', 'tshirt', 't-shirt'], true)) {
            $customizationRequest->setProductType('T-shirt');
        }

        $form = $this->createForm(CustomizationRequestType::class, $customizationRequest);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $customizationRequest->setBaseColor(null);
            $customizationRequest->setDesignDescription('Customer created a design mockup using the customization editor.');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $snapshotPath = $this->saveDesignSnapshot((string) $request->request->get('design_snapshot', ''));
            if ($snapshotPath) {
                $customizationRequest->setDesignSnapshot($snapshotPath);
            }

            $entityManager->persist($customizationRequest);
            $entityManager->flush();

            $this->addFlash('success', 'Customization request sent. Staff can now review it.');

            return $this->redirectToRoute('app_my_customization_requests');
        }

        if ($form->isSubmitted()) {
            $this->addFlash('error', $this->customizationFormErrorMessage($form));
        }

        $myRequests = $entityManager->getRepository(CustomizationRequest::class)->findBy(
            ['customer' => $user],
            ['createdAt' => 'DESC']
        );

        return $this->render('customization/new.html.twig', [
            'form' => $form,
            'requests' => $myRequests,
            'baseImages' => [
                'tshirt' => $this->designAssetUrl('base_tshirt'),
                'tote' => $this->designAssetUrl('base_tote_bag'),
            ],
        ]);
    }

    #[Route('/requests', name: 'app_customization_requests_index', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessStaffOrAdmin();

        $requests = $entityManager->getRepository(CustomizationRequest::class)->findBy([], ['createdAt' => 'DESC']);

        return $this->render('customization/index.html.twig', [
            'requests' => $requests,
        ]);
    }

    #[Route('/requests/{id}', name: 'app_customization_requests_show', methods: ['GET', 'POST'])]
    public function show(Request $request, CustomizationRequest $customizationRequest, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessStaffOrAdmin();

        $previousStatus = $customizationRequest->getStatus();
        $previousStaffResponse = $customizationRequest->getStaffResponse();
        $form = $this->createForm(CustomizationRequestStatusType::class, $customizationRequest);
        $form->handleRequest($request);
        $canHandleRequest = $this->canHandleCustomizationRequests();

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$canHandleRequest) {
                throw $this->createAccessDeniedException('Only staff users can update customization requests.');
            }

            $entityManager->flush();

            if (
                $previousStatus !== $customizationRequest->getStatus()
                || $previousStaffResponse !== $customizationRequest->getStaffResponse()
            ) {
                $this->notifyCustomerCustomizationUpdated($customizationRequest);
            }

            $this->addFlash('success', 'Customization request updated.');

            return $this->redirectToRoute('app_customization_requests_show', [
                'id' => $customizationRequest->getId(),
            ]);
        }

        return $this->render('customization/show.html.twig', [
            'customizationRequest' => $customizationRequest,
            'form' => $form,
            'canHandleRequest' => $canHandleRequest,
        ]);
    }

    private function denyUnlessStaffOrAdmin(): void
    {
        if (!$this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Only staff or admin users can view customization requests.');
        }
    }

    private function canHandleCustomizationRequests(): bool
    {
        $user = $this->getUser();

        return $user instanceof User
            && in_array('ROLE_STAFF', $user->getRoles(), true)
            && !in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function designAssetUrl(string $name): ?string
    {
        $directory = $this->getParameter('kernel.project_dir') . '/assets/IMAGES';
        foreach (['png', 'jpg', 'jpeg', 'webp'] as $extension) {
            if (is_file($directory . '/' . $name . '.' . $extension)) {
                return $this->generateUrl('app_design_asset', ['name' => $name]);
            }
        }

        return null;
    }

    private function saveDesignSnapshot(string $snapshot): ?string
    {
        if (!preg_match('/^data:image\/(?:png|jpeg|jpg);base64,(.+)$/', $snapshot, $matches)) {
            return null;
        }

        $imageData = base64_decode($matches[1], true);
        if ($imageData === false) {
            return null;
        }

        $relativeDirectory = '/uploads/customization_designs';
        $directory = $this->getParameter('kernel.project_dir') . '/public' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        $filename = 'custom-design-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.jpg';
        $path = $directory . '/' . $filename;

        return file_put_contents($path, $imageData) === false ? null : $relativeDirectory . '/' . $filename;
    }

    private function customizationFormErrorMessage($form): string
    {
        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $label = $origin?->getConfig()->getOption('label') ?: $origin?->getName();
            $errors[] = trim((string) $label . ': ' . $error->getMessage());
        }

        if ($errors === []) {
            return 'Customization request was not saved. Please choose what to design and try again.';
        }

        return 'Customization request was not saved: ' . implode(' ', array_unique($errors));
    }

    private function notifyCustomerCustomizationUpdated(CustomizationRequest $customizationRequest): void
    {
        $customer = $customizationRequest->getCustomer();
        $fcmToken = $customer?->getFcmToken();
        if (!$customer instanceof User || !$fcmToken) {
            return;
        }

        $body = sprintf('Your customization request is now %s.', $customizationRequest->getStatus());
        if ($customizationRequest->getStaffResponse()) {
            $body .= ' Staff added a response.';
        }

        try {
            $this->fcmNotificationService->sendToToken(
                $fcmToken,
                'Customization Update',
                $body,
                [
                    'type' => 'customization_update',
                    'requestId' => (string) $customizationRequest->getId(),
                    'status' => $customizationRequest->getStatus(),
                ]
            );
        } catch (\Throwable) {
            $this->addFlash('warning', 'Customization request was updated, but the push notification could not be sent.');
        }
    }
}
