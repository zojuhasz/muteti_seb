<?php

namespace Drupal\muteti_seb\Service;

use Drupal\Core\Session\AccountInterface;

final class UserDepartment {

  public static function get(AccountInterface $account): string {
    $roles = $account->getRoles();
    if (\Drupal::database()->schema()->tableExists('muteti_department_config')) {
      $departments = \Drupal::database()->select('muteti_department_config', 'd')
        ->fields('d', ['name', 'role_id'])
        ->orderBy('id')
        ->execute()
        ->fetchAll();
      $by_role = [];
      foreach ($departments as $department) {
        $by_role[$department->role_id] = $department->name;
      }
      foreach (['muteti_department_urol', 'muteti_department_onkorad', 'muteti_department_seb'] as $role_id) {
        if (in_array($role_id, $roles, TRUE) && isset($by_role[$role_id])) {
          return $by_role[$role_id];
        }
      }
      foreach ($departments as $department) {
        if (in_array($department->role_id, $roles, TRUE)) {
          return $department->name;
        }
      }
    }
    if (in_array('muteti_department_urol', $roles, TRUE)) return 'Urológia';
    if (in_array('muteti_department_onkorad', $roles, TRUE)) return 'Onkoradiológia';
    return 'Sebészet';
  }

}
