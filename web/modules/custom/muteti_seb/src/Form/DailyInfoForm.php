<?php

namespace Drupal\muteti_seb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;

final class DailyInfoForm extends FormBase {

  public function getFormId(): string {
    return 'muteti_daily_info_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $date = NULL): array {
    $department = UserDepartment::get($this->currentUser());
    $mode = DepartmentMode::get($department);
    $record = \Drupal::database()->select('muteti_daily_info', 'i')
      ->fields('i')->condition('department', $department)->condition('date', $date)->execute()->fetchObject();
    $form['date'] = ['#type' => 'hidden', '#value' => $date];
    if ($mode === 'seb') {
      $form['responsible'] = ['#type' => 'textfield', '#title' => 'Aznapi műtét felelős', '#default_value' => $record->responsible ?? ''];
      $form['acute_1'] = ['#type' => 'textfield', '#title' => 'Akut felelős 1', '#default_value' => $record->acute_1 ?? ''];
      $form['acute_2'] = ['#type' => 'textfield', '#title' => 'Akut felelős 2', '#default_value' => $record->acute_2 ?? ''];
      $form['ambulance'] = ['#type' => 'textfield', '#title' => 'Ambulancia felelős', '#default_value' => $record->ambulance ?? ''];
    }
    else {
      $form['acute_1'] = ['#type' => 'textfield', '#title' => 'Akut beteg ellátás', '#default_value' => $record->acute_1 ?? ''];
    }
    if (!DepartmentMode::featureEnabled($department, 'availability_enabled')) {
      $form['other_absent'] = ['#type' => 'textarea', '#title' => 'Egyéb távollevők', '#default_value' => $record->other_absent ?? '', '#rows' => 3];
    }
    $form['start_time'] = ['#type' => 'time', '#title' => 'Műtétek kezdete', '#default_value' => $record->start_time ?? ($mode === 'urol' ? '08:00' : '08:30'), '#required' => TRUE];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => 'Mentés', '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $department = UserDepartment::get($this->currentUser());
    $date = $form_state->getValue('date');
    $fields = ['responsible', 'acute_1', 'acute_2', 'ambulance', 'other_absent'];
    $values = [];
    foreach ($fields as $field) {
      $values[$field] = trim((string) $form_state->getValue($field, ''));
    }
    $values['start_time'] = $form_state->getValue('start_time');
    $values['changed'] = \Drupal::time()->getRequestTime();
    \Drupal::database()->merge('muteti_daily_info')
      ->keys(['department' => $department, 'date' => $date])->fields($values)->execute();
    $week = (new \DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
    $form_state->setRedirect('muteti_seb.surgery', [], ['query' => ['week' => $week, 'day' => $date]]);
  }
}
