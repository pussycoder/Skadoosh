<?php

namespace App\Service;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmNotificationService
{
    private ?Messaging $messaging = null;

    public function __construct(private readonly string $firebaseCredentials)
    {
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        $message = CloudMessage::withTarget('token', $token)
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        $this->messaging()->send($message);
    }

    private function messaging(): Messaging
    {
        $credentials = json_decode($this->firebaseCredentials, true);

        return $this->messaging ??= (new Factory())
            ->withServiceAccount(is_array($credentials) ? $credentials : $this->firebaseCredentials)
            ->createMessaging();
    }
}