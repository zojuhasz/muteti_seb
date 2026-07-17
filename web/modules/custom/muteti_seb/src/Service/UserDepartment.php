<?php

namespace Drupal\muteti_seb\Service;

use Drupal\Core\Session\AccountInterface;

final class UserDepartment {

  public static function get(AccountInterface $account): string {
    $roles = $account->getRoles();
    if (in_array('muteti_department_urol', $roles, TRUE)) {
      return 'Urológia';
    }
    if (in_array('muteti_department_onkorad', $roles, TRUE)) {
      return 'Onkoradiológia';
    }
    return 'Sebészet';
  }

}
