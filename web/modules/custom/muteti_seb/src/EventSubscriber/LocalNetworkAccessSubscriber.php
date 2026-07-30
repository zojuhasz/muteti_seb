<?php

declare(strict_types=1);

namespace Drupal\muteti_seb\EventSubscriber;

use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restricts users with the local role to the approved hospital networks.
 */
final class LocalNetworkAccessSubscriber implements EventSubscriberInterface {

  /**
   * Networks from which users with the local role may access the site.
   */
  private const ALLOWED_NETWORKS = [
    '192.168.40.0/24',
    '172.16.0.0/16',
    '100.68.0.0/16',
  ];

  /**
   * Constructs the access subscriber.
   */
  public function __construct(
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Denies local-role users arriving from outside the approved networks.
   */
  public function onKernelRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->currentUser->isAnonymous()) {
      return;
    }

    if (!in_array('muteti_local', $this->currentUser->getRoles(), TRUE)) {
      return;
    }

    $request = $event->getRequest();

    // Keep logout available even when the current network is not permitted.
    if ($request->getPathInfo() === '/user/logout') {
      return;
    }

    $client_ip = (string) $request->getClientIp();
    if (!IpUtils::checkIp($client_ip, self::ALLOWED_NETWORKS)) {
      throw new AccessDeniedHttpException(
        'A local szerepkörrel ez az oldal csak az engedélyezett belső hálózatokról érhető el.'
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 30],
    ];
  }

}
