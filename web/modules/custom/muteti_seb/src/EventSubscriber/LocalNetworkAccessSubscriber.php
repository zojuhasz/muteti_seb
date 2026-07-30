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
   * Routes needed to view and operate the surgical allocation page.
   */
  private const ALLOWED_ROUTES = [
    'muteti_seb.surgery',
    'muteti_seb.program_pdf',
    'muteti_seb.assignment',
    'muteti_seb.day_type',
    'muteti_seb.availability_update',
    'muteti_seb.daily_info',
    'user.logout',
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

    $route_name = (string) $request->attributes->get('_route');
    if ($route_name !== '' && !in_array($route_name, self::ALLOWED_ROUTES, TRUE)) {
      throw new AccessDeniedHttpException(
        'A local szerepkör kizárólag a műtéti beosztást érheti el.'
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
