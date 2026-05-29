<?php

namespace App\Service;

use App\Entity\CustomizationRequest;
use App\Entity\Orders;
use App\Entity\User;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FirebaseNotificationSender
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function sendOrderUpdate(Orders $order): void
    {
        $customer = $order->getProcessedBy();
        if (!$customer instanceof User) {
            return;
        }

        $this->sendToUser($customer, [
            'title' => 'Order update',
            'body' => sprintf('Receipt #%d is now %s.', $order->getId(), $order->getStatus()),
            'data' => [
                'type' => 'order:updated',
                'orderId' => (string) $order->getId(),
                'status' => (string) $order->getStatus(),
            ],
        ]);
    }

    public function sendCustomizationUpdate(CustomizationRequest $request): void
    {
        $customer = $request->getCustomer();
        if (!$customer instanceof User) {
            return;
        }

        $this->sendToUser($customer, [
            'title' => 'Customization update',
            'body' => sprintf('Request #%d is now %s.', $request->getId(), $request->getStatus()),
            'data' => [
                'type' => 'customization:updated',
                'requestId' => (string) $request->getId(),
                'status' => $request->getStatus(),
            ],
        ]);
    }

    private function sendToUser(User $user, array $message): void
    {
        $token = $user->getFcmToken();
        $serviceAccount = $this->serviceAccount();
        if ($serviceAccount === null || $token === null || $token === '') {
            return;
        }

        try {
            $accessToken = $this->accessToken($serviceAccount);
            if ($accessToken === null) {
                return;
            }

            $this->httpClient->request('POST', sprintf(
                'https://fcm.googleapis.com/v1/projects/%s/messages:send',
                rawurlencode((string) $serviceAccount['project_id'])
            ), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $message['title'],
                            'body' => $message['body'],
                        ],
                        'data' => $this->stringifyData($message['data']),
                        'android' => [
                            'priority' => 'HIGH',
                            'notification' => [
                                'channel_id' => 'skadoosh_updates',
                                'sound' => 'default',
                            ],
                        ],
                    ],
                ],
                'timeout' => 3.0,
            ])->getStatusCode();
        } catch (\Throwable) {
            // Push notifications should not block the order/customization update.
        }
    }

    private function serviceAccount(): ?array
    {
        $json = trim((string) ($_ENV['FIREBASE_SERVICE_ACCOUNT_JSON'] ?? $_SERVER['FIREBASE_SERVICE_ACCOUNT_JSON'] ?? ''));
        if ($json === '') {
            return null;
        }

        $serviceAccount = json_decode($json, true);
        if (!is_array($serviceAccount)) {
            return null;
        }

        foreach (['project_id', 'client_email', 'private_key'] as $key) {
            if (!isset($serviceAccount[$key]) || !is_string($serviceAccount[$key]) || trim($serviceAccount[$key]) === '') {
                return null;
            }
        }

        return $serviceAccount;
    }

    private function accessToken(array $serviceAccount): ?string
    {
        $now = time();
        $assertion = $this->jwt([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], $serviceAccount['private_key']);

        if ($assertion === null) {
            return null;
        }

        $response = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
            'timeout' => 3.0,
        ]);

        $payload = $response->toArray(false);

        return is_string($payload['access_token'] ?? null) ? $payload['access_token'] : null;
    }

    private function jwt(array $claims, string $privateKey): ?string
    {
        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function stringifyData(array $data): array
    {
        $strings = [];
        foreach ($data as $key => $value) {
            $strings[(string) $key] = (string) $value;
        }

        return $strings;
    }
}
