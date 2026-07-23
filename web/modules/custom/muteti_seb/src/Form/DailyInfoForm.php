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
    $doctor_options = [];
    $doctor_rows = \Drupal::database()->select('muteti_doctor', 'd')
      ->fields('d', ['name'])
      ->condition('department', $department)
      ->condition('active', 1)
      ->orderBy('name')
      ->execute()
      ->fetchCol();
    foreach ($doctor_rows as $doctor_name) {
      $doctor_options[$doctor_name] = $doctor_name;
    }
    $doctor_select = static function (string $title, string $default) use ($doctor_options): array {
      $options = $doctor_options;
      // Preserve a legacy or combined imported value until the user replaces
      // it with one of the current department doctors.
      if ($default !== '' && !isset($options[$default])) {
        $options = [$default => $default.' (importált érték)'] + $options;
      }
      return [
        '#type' => 'select',
        '#title' => $title,
        '#options' => $options,
        '#empty_option' => '-',
        '#default_value' => $default,
      ];
    };
    $form['date'] = ['#type' => 'hidden', '#value' => $date];
    if ($mode === 'seb') {
      $form['responsible'] = $doctor_select('Aznapi műtét felelős', (string) ($record->responsible ?? ''));
      $form['acute_1'] = $doctor_select('Akut felelős 1', (string) ($record->acute_1 ?? ''));
      $form['acute_2'] = $doctor_select('Akut felelős 2', (string) ($record->acute_2 ?? ''));
      $form['ambulance'] = ['#type' => 'textfield', '#title' => 'Ambulancia felelős', '#default_value' => $record->ambulance ?? ''];
    }
    else {
      $form['acute_1'] = $doctor_select('Akut beteg ellátás 1', (string) ($record->acute_1 ?? ''));
      $form['acute_2'] = $doctor_select('Akut beteg ellátás 2', (string) ($record->acute_2 ?? ''));
    }
    if (!DepartmentMode::featureEnabled($department, 'availability_enabled')) {
      $form['other_absent'] = ['#type' => 'textarea', '#title' => 'Egyéb távollevők', '#default_value' => $record->other_absent ?? '', '#rows' => 3];
    }
    $form['start_time'] = [
      '#type' => 'textfield',
      '#title' => 'Műtétek kezdete',
      '#default_value' => $record->start_time ?? ($mode === 'urol' ? '08:00' : '08:30'),
      '#required' => TRUE,
      '#size' => 5,
      '#maxlength' => 5,
      '#description' => 'ÓÓ:PP formátumban, például 08:00.',
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => 'Mentés', '#button_type' => 'primary'];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $department = UserDepartment::get($this->currentUser());
    $mode = DepartmentMode::get($department);
    $date = $form_state->getValue('date');
    $fields = ['responsible', 'acute_1', 'acute_2', 'ambulance', 'other_absent'];
    $values = [];
    foreach ($fields as $field) {
      $value = trim((string) $form_state->getValue($field, ''));
      $values[$field] = $value === '-' ? '' : $value;
    }
    $start_time = trim((string) $form_state->getValue('start_time'));
    $values['start_time'] = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start_time)
      ? $start_time
      : ($mode === 'urol' ? '08:00' : '08:30');
    $values['changed'] = \Drupal::time()->getRequestTime();
    \Drupal::database()->merge('muteti_daily_info')
      ->keys(['department' => $department, 'date' => $date])->fields($values)->execute();
    $week = (new \DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d');
    $form_state->setRedirect('muteti_seb.surgery', [], ['query' => ['week' => $week, 'day' => $date]]);
  }
}
