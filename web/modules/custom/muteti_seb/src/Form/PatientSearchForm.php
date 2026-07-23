<?php

namespace Drupal\muteti_seb\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\UserDepartment;

final class PatientSearchForm extends FormBase {

  public function getFormId(): string {
    return 'muteti_patient_search_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $is_oncology = DepartmentMode::get(UserDepartment::get($this->currentUser())) === 'onko';
    $identifier = $is_oncology ? 'kórlapja' : 'TAJ-száma';
    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'muteti-patient-search-form';
    $form['q'] = [
      '#type' => 'search',
      '#title' => $this->t('Beteg neve vagy @identifier', ['@identifier' => $identifier]),
      '#default_value' => trim((string) \Drupal::request()->query->get('q', '')),
      '#required' => TRUE,
      '#size' => 40,
      '#maxlength' => 100,
      '#attributes' => [
        'placeholder' => $is_oncology ? $this->t('Név vagy kórlap') : $this->t('Név vagy TAJ'),
        'autocomplete' => 'off',
        'minlength' => 2,
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Keresés'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (mb_strlen(trim((string) $form_state->getValue('q')), 'UTF-8') < 2) {
      $form_state->setErrorByName('q', $this->t('Legalább két karaktert adj meg.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('muteti_seb.patient_search', [], [
      'query' => ['q' => trim((string) $form_state->getValue('q'))],
    ]);
  }

}
