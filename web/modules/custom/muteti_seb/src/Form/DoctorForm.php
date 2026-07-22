<?php

namespace Drupal\muteti_seb\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

final class DoctorForm extends ContentEntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $form['#attributes']['class'][] = 'muteti-doctor-entity-form';

    foreach (['background_color' => '#eef2f6', 'text_color' => '#111111'] as $field => $fallback) {
      if (isset($form[$field]['widget'][0]['value'])) {
        $form[$field]['widget'][0]['value']['#type'] = 'color';
        $form[$field]['widget'][0]['value']['#default_value'] = $this->entity->get($field)->value ?: $fallback;
        $form[$field]['widget'][0]['value']['#required'] = TRUE;
      }
    }

    $department_names = \Drupal::database()->select('muteti_department_config', 'd')
      ->fields('d', ['name'])
      ->orderBy('name')
      ->execute()
      ->fetchCol();
    if (isset($form['department']['widget'][0]['value'])) {
      $form['department']['widget'][0]['value']['#type'] = 'select';
      $form['department']['widget'][0]['value']['#options'] = array_combine($department_names, $department_names);
      $form['department']['widget'][0]['value']['#default_value'] = $this->entity->get('department')->value ?: array_key_first($form['department']['widget'][0]['value']['#options']);
    }

    $form['color_preview'] = [
      '#type' => 'container',
      '#weight' => 45,
      '#attributes' => ['class' => ['muteti-doctor-form-preview']],
      'label' => ['#markup' => '<strong>'.$this->t('Színminta').'</strong>'],
      'sample' => ['#markup' => '<div class="muteti-doctor-live-preview">'.$this->t('Orvos neve').'</div>'],
    ];

    $form['#attached']['library'][] = 'muteti_seb/doctor_form';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach (['background_color', 'text_color'] as $field) {
      $value = (string) $form_state->getValue([$field, 0, 'value']);
      if (!preg_match('/^#[0-9a-f]{6}$/i', $value)) {
        $form_state->setErrorByName($field, $this->t('Érvényes, hatjegyű színkód szükséges.'));
      }
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($status === SAVED_NEW
      ? $this->t('Az új orvos létrejött.')
      : $this->t('Az orvos adatai frissültek.'));
    $form_state->setRedirect('entity.muteti_doctor.collection');
    return $status;
  }

}
