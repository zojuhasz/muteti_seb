<?php

namespace Drupal\muteti_seb\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class DoctorNodeController extends ControllerBase {

  public function listing(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('system.admin_content', [], [
      'query' => ['type' => 'muteti_doctor'],
    ])->toString());
  }

}
