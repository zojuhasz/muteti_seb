<?php

namespace Drupal\muteti_seb\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class DoctorForm extends FormBase {

  private const DEPARTMENTS = [
    'Sebészet' => 'Sebészet',
  ];

  public function __construct(private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function getFormId(): string {
    return 'muteti_seb_doctor_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?int $doctor = NULL): array {
    $record = $doctor
      ? $this->database->select('muteti_doctor', 'd')->fields('d')->condition('id', $doctor)->condition('department', 'Sebészet')->execute()->fetchObject()
      : NULL;
    if ($doctor && !$record) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $form['doctor_id'] = ['#type' => 'hidden', '#value' => $record->id ?? ''];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Orvos neve'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#default_value' => $record->name ?? '',
    ];
    $form['background_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Háttérszín'),
      '#default_value' => $record->background_color ?? '#eef2f6',
      '#required' => TRUE,
    ];
    $form['text_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Betűszín'),
      '#default_value' => $record->text_color ?? '#111111',
      '#required' => TRUE,
    ];
    $form['user_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Felhasználónév'),
      '#target_type' => 'user',
      '#default_value' => !empty($record->user_id) ? User::load($record->user_id) : NULL,
      '#description' => $this->t('A kapcsolt Drupal-felhasználó. Nem kötelező.'),
    ];
    $form['department'] = [
      '#type' => 'select',
      '#title' => $this->t('Osztály'),
      '#required' => TRUE,
      '#options' => self::DEPARTMENTS,
      '#default_value' => $record->department ?? 'Sebészet',
      '#description' => $this->t('Jelenleg csak a Sebészet választható. Később Urológia és Onkoradiológia is hozzáadható.'),
    ];
    $form['active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Aktív'),
      '#default_value' => isset($record->active) ? (bool) $record->active : TRUE,
    ];
    $form['preview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['muteti-doctor-form-preview']],
      'label' => ['#markup' => '<strong>'.$this->t('Színminta').'</strong>'],
      'sample' => ['#markup' => '<div class="muteti-doctor-live-preview">'.$this->t('Orvos neve').'</div>'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $record ? $this->t('Módosítás mentése') : $this->t('Orvos felvitele'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Mégse'),
      '#url' => \Drupal\Core\Url::fromRoute('muteti_seb.doctors'),
      '#attributes' => ['class' => ['button']],
    ];
    $form['#attached']['library'][] = 'muteti_seb/doctor_form';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['background_color', 'text_color'] as $field) {
      if (!preg_match('/^#[0-9a-f]{6}$/i', (string) $form_state->getValue($field))) {
        $form_state->setErrorByName($field, $this->t('Érvényes, hatjegyű színkód szükséges.'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('doctor_id');
    $user_value = $form_state->getValue('user_id');
    $user_id = is_array($user_value) ? (int) ($user_value['target_id'] ?? 0) : (int) $user_value;
    $fields = [
      'name' => trim((string) $form_state->getValue('name')),
      'background_color' => (string) $form_state->getValue('background_color'),
      'text_color' => (string) $form_state->getValue('text_color'),
      'user_id' => $user_id ?: NULL,
      'department' => (string) $form_state->getValue('department'),
      'active' => (int) (bool) $form_state->getValue('active'),
    ];

    if ($id) {
      $this->database->update('muteti_doctor')->fields($fields)->condition('id', $id)->execute();
      $this->messenger()->addStatus($this->t('Az orvos adatai frissültek.'));
    }
    else {
      $this->database->insert('muteti_doctor')->fields($fields)->execute();
      $this->messenger()->addStatus($this->t('Az új orvos létrejött.'));
    }
    $form_state->setRedirect('muteti_seb.doctors');
  }

}
