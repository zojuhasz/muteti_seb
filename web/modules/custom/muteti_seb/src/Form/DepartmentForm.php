<?php

namespace Drupal\muteti_seb\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DepartmentForm extends FormBase {

  private ?object $department = NULL;

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function getFormId(): string {
    return 'muteti_department_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $department = NULL): array {
    if ($department) {
      $this->department = $this->database->select('muteti_department_config', 'd')
        ->fields('d')
        ->condition('id', $department)
        ->execute()
        ->fetchObject() ?: NULL;
      if (!$this->department) {
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
      }
    }
    $record = $this->department;
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Osztály neve'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#default_value' => $record->name ?? '',
    ];
    $form['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('Gépi név'),
      '#required' => TRUE,
      '#default_value' => $record->machine_name ?? '',
      '#disabled' => (bool) $record,
      '#machine_name' => [
        'exists' => [self::class, 'machineNameExists'],
        'source' => ['name'],
      ],
    ];
    $form['mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Működési mód'),
      '#required' => TRUE,
      '#options' => [
        'seb' => 'seb – sebészeti működés',
        'urol' => 'urol – urológiai működés',
        'onko' => 'onko – onkológiai működés',
      ],
      '#default_value' => $record->mode ?? 'seb',
    ];
    if ($record) {
      $form['role'] = ['#type' => 'item', '#title' => $this->t('Felhasználói szerepkör'), '#markup' => $record->role_id];
      $form['id'] = ['#type' => 'hidden', '#value' => $record->id];
    }
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Mentés'), '#button_type' => 'primary'];
    return $form;
  }

  public static function machineNameExists(string $value): bool {
    return (bool) \Drupal::database()->select('muteti_department_config', 'd')
      ->condition('machine_name', $value)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $query = $this->database->select('muteti_department_config', 'd')
      ->condition('name', trim((string) $form_state->getValue('name')));
    if ($this->department) {
      $query->condition('id', $this->department->id, '<>');
    }
    if ($query->countQuery()->execute()->fetchField()) {
      $form_state->setErrorByName('name', $this->t('Ez az osztálynév már létezik.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) $form_state->getValue('name'));
    $mode = (string) $form_state->getValue('mode');
    if ($this->department) {
      $old_name = (string) $this->department->name;
      $this->database->update('muteti_department_config')
        ->fields(['name' => $name, 'mode' => $mode])
        ->condition('id', $this->department->id)
        ->execute();
      if ($old_name !== $name) {
        foreach (['muteti_appointment', 'muteti_doctor', 'muteti_day_type'] as $table) {
          $this->database->update($table)->fields(['department' => $name])->condition('department', $old_name)->execute();
        }
      }
      if ($role = Role::load($this->department->role_id)) {
        $role->set('label', 'Osztály: '.$name);
        $role->save();
      }
    }
    else {
      $machine_name = (string) $form_state->getValue('machine_name');
      $role_id = 'muteti_department_'.$machine_name;
      $role = Role::load($role_id) ?? Role::create(['id' => $role_id, 'label' => 'Osztály: '.$name]);
      $role->save();
      $this->database->insert('muteti_department_config')->fields([
        'name' => $name,
        'machine_name' => $machine_name,
        'mode' => $mode,
        'role_id' => $role_id,
      ])->execute();
    }
    DepartmentMode::reset();
    $this->messenger()->addStatus($this->t('Az osztály beállítása elmentve.'));
    $form_state->setRedirect('muteti_seb.departments');
  }

}
