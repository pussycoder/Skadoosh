<?php

namespace App\Controller;

use App\Entity\RealtimeEvent;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

class RealtimeController extends AbstractController
{
    #[Route('/realtime/stream', name: 'app_realtime_stream', methods: ['GET'])]
    public function eventStream(Request $request, EntityManagerInterface $entityManager): StreamedResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || (!$this->isGranted('ROLE_STAFF') && !$this->isGranted('ROLE_ADMIN'))) {
            throw $this->createAccessDeniedException('Only staff or admin users can subscribe to realtime updates.');
        }

        $lastEventId = (int) ($request->headers->get('Last-Event-ID') ?: $request->query->get('lastEventId', 0));

        $response = new StreamedResponse(function () use ($entityManager, $lastEventId): void {
            $currentId = $lastEventId;
            $deadline = time() + 55;

            while (time() < $deadline && !connection_aborted()) {
                $events = $entityManager->createQueryBuilder()
                    ->select('event')
                    ->from(RealtimeEvent::class, 'event')
                    ->where('event.id > :lastEventId')
                    ->setParameter('lastEventId', $currentId)
                    ->orderBy('event.id', 'ASC')
                    ->setMaxResults(50)
                    ->getQuery()
                    ->getResult();

                foreach ($events as $event) {
                    if (!$event instanceof RealtimeEvent || $event->getId() === null) {
                        continue;
                    }

                    $currentId = $event->getId();
                    echo 'id: ' . $currentId . "\n";
                    echo 'event: ' . $event->getEventType() . "\n";
                    echo 'data: ' . json_encode($event->getPayload(), JSON_THROW_ON_ERROR) . "\n\n";
                }

                if ($events === []) {
                    echo ": keepalive\n\n";
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();

                $entityManager->clear();
                sleep(2);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }
}
