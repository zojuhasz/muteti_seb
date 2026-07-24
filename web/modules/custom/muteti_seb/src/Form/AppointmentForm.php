<?php

namespace Drupal\muteti_seb\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\muteti_seb\Service\DepartmentMode;
use Drupal\muteti_seb\Service\AuditLog;
use Drupal\muteti_seb\Service\UserDepartment;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AppointmentForm extends FormBase {
  private ?object $appointment = NULL;
  public function __construct(private readonly Connection $database) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('database')); }
  public function getFormId(): string { return 'muteti_appointment_form'; }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $date=NULL, ?string $slot=NULL): array {
    $department=UserDepartment::get($this->currentUser());
    $this->appointment=$this->database->select('muteti_appointment','a')->fields('a')->condition('department',$department)->condition('admission_date',$date)->condition('slot_type',$slot)->execute()->fetchObject() ?: NULL;
    $a=$this->appointment; $form['date']=['#type'=>'hidden','#value'=>$date]; $form['slot']=['#type'=>'hidden','#value'=>$slot];
    $form['department']=['#type'=>'hidden','#value'=>$department];
    $doctors=['0'=>'-']+$this->database->select('muteti_doctor','d')->fields('d',['id','name'])->condition('department',$department)->condition('active',1)->orderBy('name')->execute()->fetchAllKeyed();
    $mode = DepartmentMode::get($department);
    if ($mode === 'onko') {
      $treatment_storage = $this->entityTypeManager()->getStorage('node');
      $treatment_ids = $treatment_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'muteti_oncology_treatment')
        ->condition('status', 1)
        ->sort('title')
        ->execute();
      $treatments = [];
      foreach ($treatment_storage->loadMultiple($treatment_ids) as $treatment) {
        $name = trim((string) $treatment->label());
        if ($name !== '') {
          $treatments[] = $name;
        }
      }
      // Keep the form usable until the database update has created and
      // populated the treatment content type.
      if (!$treatments) {
        $treatments = $this->database->select('muteti_appointment', 'a')
          ->distinct()
          ->fields('a', ['operation_name'])
          ->condition('department', $department)
          ->condition('operation_name', '', '<>')
          ->isNotNull('operation_name')
          ->orderBy('operation_name')
          ->execute()
          ->fetchCol();
      }
      if (!empty($a->operation_name) && !in_array($a->operation_name, $treatments, TRUE)) {
        $treatments[] = $a->operation_name;
      }
      $treatments = array_values(array_unique(array_filter(array_map('trim', $treatments))));
      sort($treatments, SORT_NATURAL | SORT_FLAG_CASE);
      $form['operation_name'] = [
        '#type' => 'select',
        '#title' => $this->t('Kezelés'),
        '#required' => TRUE,
        '#empty_option' => $this->t('- Kezelés kiválasztása -'),
        '#options' => $treatments ? array_combine($treatments, $treatments) : [],
        '#default_value' => $a->operation_name ?? '',
      ];
      $form['patient_name']=['#type'=>'textfield','#title'=>$this->t('Beteg neve @code',['@code'=>date('n-j',strtotime($date)).'-'.$slot]),'#required'=>TRUE,'#default_value'=>$a->patient_name ?? ''];
      $form['taj']=['#type'=>'textfield','#title'=>$this->t('Kórlap'),'#default_value'=>$a->taj ?? ''];
      $form['contact']=['#type'=>'textfield','#title'=>$this->t('Elérhetőség'),'#default_value'=>$a->contact ?? ''];
      $form['notes']=['#type'=>'textarea','#title'=>$this->t('Egyéb info'),'#default_value'=>$a->notes ?? ''];
      $form['doctor_id']=['#type'=>'select','#title'=>$this->t('Orvos'),'#options'=>$doctors,'#default_value'=>$a->doctor_id ?? 0];
      $form['actions']=['#type'=>'actions'];
      $form['actions']['submit']=['#type'=>'submit','#value'=>$this->t('Mehet'),'#button_type'=>'primary'];
      return $this->applyAccessMode($form);
    }
    $anaesth_options = [
      'Local' => 'Local',
      'i.v. narc.' => 'i.v. narc.',
      'i.v. Laryng' => 'i.v. Laryng',
      'Spinal' => 'Spinal',
      'ITN' => 'ITN',
      'ITN+EDA' => 'ITN+EDA',
      'I.v. + N. obt blokad' => 'I.v. + N. obt blokad',
    ];
    if ($mode === 'urol') {
      $form['care_type'] = [
        '#type' => 'radios',
        '#title' => '1 napos sebészet',
        '#options' => ['normal' => 'Normál', 'one_day' => 'Egynapos', 'same_day' => 'Aznapi'],
        '#default_value' => $a->care_type ?? 'normal',
      ];
      $form['patient_name']=['#type'=>'textfield','#title'=>$this->t('Beteg neve @code',['@code'=>date('n-j',strtotime($date)).'-'.$slot]),'#required'=>TRUE,'#default_value'=>$a->patient_name ?? ''];
      foreach (['taj'=>'TAJ/Szül.dat.','contact'=>'Elérhetőség','ward_room'=>'Kórterem','diagnosis'=>'Diagnózis','operation_name'=>'Műtét'] as $key=>$label) {
        $form[$key]=['#type'=>'textfield','#title'=>$this->t($label),'#required'=>in_array($key,['diagnosis','operation_name'],TRUE),'#default_value'=>$a->{$key} ?? ''];
      }
      $form['anaesth']=['#type'=>'select','#title'=>'Anaesth','#options'=>$anaesth_options,'#empty_option'=>'?','#default_value'=>$a->anaesth ?? ''];
      $blood_options=['?'=>'?','A+'=>'A+','A-'=>'A-','B+'=>'B+','B-'=>'B-','0+'=>'0+','0-'=>'0-','AB+'=>'AB+','AB-'=>'AB-'];
      $form['blood_type']=['#type'=>'select','#title'=>'Vércsoport','#options'=>$blood_options,'#default_value'=>$a->blood_type ?? '?'];
      $form['notes']=['#type'=>'textarea','#title'=>$this->t('Egyéb info'),'#default_value'=>$a->notes ?? ''];
      foreach (['doctor_id'=>'Orvos','assistant1_id'=>'Asszisztens 1','assistant2_id'=>'Asszisztens 2','assistant3_id'=>'Asszisztens 3'] as $key=>$label) {
        $form[$key]=['#type'=>'select','#title'=>$this->t($label),'#options'=>$doctors,'#default_value'=>$a->{$key} ?? 0];
      }
      $form['actions']=['#type'=>'actions'];
      $form['actions']['submit']=['#type'=>'submit','#value'=>$this->t('Mehet'),'#button_type'=>'primary'];
      return $this->applyAccessMode($form);
    }
    $form['aznm']=['#type'=>'checkbox','#title'=>$this->t('AZNM.'),'#default_value'=>$a->aznm ?? 0];
    $form['patient_name']=['#type'=>'textfield','#title'=>$this->t('Beteg neve @code',['@code'=>date('n-j',strtotime($date)).'-'.$slot]),'#required'=>TRUE,'#default_value'=>$a->patient_name ?? ''];
    $form['birth_date']=['#type'=>'date','#title'=>$this->t('Születési dátum'),'#default_value'=>$a->birth_date ?? ''];
    foreach (['taj'=>'TAJ','contact'=>'Elérhetőség','ward_room'=>'Kórterem','diagnosis'=>'Diagnózis','operation_name'=>'Műtét'] as $key=>$label) $form[$key]=['#type'=>'textfield','#title'=>$this->t($label),'#required'=>in_array($key,['diagnosis','operation_name']), '#default_value'=>$a->{$key} ?? ''];
    $yes_no_options = ['Igen' => 'Igen', 'Nem' => 'Nem'];
    foreach (['laparoscope' => 'Laparoszkóp', 'mesh' => 'Háló'] as $key => $label) {
      $current = (string) ($a->{$key} ?? '');
      $form[$key] = [
        '#type' => 'select',
        '#title' => $this->t($label),
        '#options' => $yes_no_options,
        '#empty_option' => '-',
        '#default_value' => isset($yes_no_options[$current]) ? $current : '',
      ];
    }
    $laterality_options = ['D' => 'D', 'S' => 'S', 'U' => 'U', 'N' => 'N'];
    $current_laterality = (string) ($a->laterality ?? '');
    $form['laterality'] = [
      '#type' => 'select',
      '#title' => $this->t('Oldaliság'),
      '#options' => $laterality_options,
      '#empty_option' => '-',
      '#default_value' => isset($laterality_options[$current_laterality]) ? $current_laterality : '',
    ];
    $blood_options = [
      'A+' => 'A+', 'A-' => 'A-',
      'B+' => 'B+', 'B-' => 'B-',
      'AB+' => 'AB+', 'AB-' => 'AB-',
      '0+' => '0+', '0-' => '0-',
    ];
    $current_blood_type = (string) ($a->blood_type ?? '');
    $form['blood_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Vércsoport'),
      '#options' => $blood_options,
      '#empty_option' => '-',
      '#default_value' => isset($blood_options[$current_blood_type]) ? $current_blood_type : '',
    ];
    $form['anaesth'] = [
      '#type' => 'select',
      '#title' => 'Anaesth',
      '#options' => $anaesth_options,
      '#empty_option' => '-',
      '#default_value' => $a->anaesth ?? '',
    ];
    $form['notes']=['#type'=>'textarea','#title'=>$this->t('Egyéb info'),'#default_value'=>$a->notes ?? ''];
    foreach (['doctor_id'=>'Orvos','assistant1_id'=>'Asszisztens 1','assistant2_id'=>'Asszisztens 2','assistant3_id'=>'Asszisztens 3'] as $key=>$label) $form[$key]=['#type'=>'select','#title'=>$this->t($label),'#options'=>$doctors,'#default_value'=>$a->{$key} ?? 0];
    $form['actions']=['#type'=>'actions']; $form['actions']['submit']=['#type'=>'submit','#value'=>$this->t('Mentés'),'#button_type'=>'primary']; return $this->applyAccessMode($form);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $v=$form_state->getValues(); $fields=[];
    $slot = (string) ($v['slot'] ?? '');
    $required_permission = $this->appointment ? 'edit surgery appointment' : 'create surgery appointment';
    if (!$this->currentUser()->hasPermission($required_permission) || !$this->canManageSlot($slot)) {
      throw new AccessDeniedHttpException();
    }
    $department = (string) $v['department'];
    $mode = DepartmentMode::get($department);
    if ($mode === 'onko') {
      $fields = [
        'aznm' => 0,
        'patient_name' => trim((string) $v['patient_name']),
        'contact' => trim((string) $v['contact']),
        'taj' => trim((string) $v['taj']),
        'diagnosis' => '',
        'operation_name' => (string) $v['operation_name'],
        'notes' => trim((string) $v['notes']),
        'doctor_id' => (int) $v['doctor_id'] ?: NULL,
        'assistant1_id' => NULL,
        'assistant2_id' => NULL,
        'assistant3_id' => NULL,
      ];
    }
    elseif ($mode === 'urol') {
      $fields = [
        'aznm' => 0,
        'care_type' => (string) ($v['care_type'] ?? 'normal'),
        'patient_name' => trim((string) $v['patient_name']),
        'taj' => trim((string) $v['taj']),
        'contact' => trim((string) $v['contact']),
        'ward_room' => trim((string) $v['ward_room']),
        'diagnosis' => trim((string) $v['diagnosis']),
        'operation_name' => trim((string) $v['operation_name']),
        'anaesth' => (string) ($v['anaesth'] ?? '') ?: NULL,
        'blood_type' => (string) ($v['blood_type'] ?? '?'),
        'notes' => trim((string) $v['notes']),
        'doctor_id' => (int) $v['doctor_id'] ?: NULL,
        'assistant1_id' => (int) $v['assistant1_id'] ?: NULL,
        'assistant2_id' => (int) $v['assistant2_id'] ?: NULL,
        'assistant3_id' => (int) $v['assistant3_id'] ?: NULL,
      ];
    }
    else {
      foreach (['aznm','patient_name','birth_date','taj','contact','ward_room','diagnosis','operation_name','laparoscope','mesh','laterality','blood_type','anaesth','notes','doctor_id','assistant1_id','assistant2_id','assistant3_id'] as $key) $fields[$key]=$v[$key] ?: NULL;
      $fields['aznm'] = (int) ($v['aznm'] ?? 0);
    }
    $fields['changed']=\Drupal::time()->getRequestTime();
    $is_new = !$this->appointment;
    if ($this->appointment) $this->database->update('muteti_appointment')->fields($fields)->condition('id',$this->appointment->id)->execute();
    else { $fields += ['department'=>$department,'admission_date'=>$v['date'],'slot_type'=>$v['slot'],'created_by'=>(int)$this->currentUser()->id(),'created'=>$fields['changed']]; $this->database->insert('muteti_appointment')->fields($fields)->execute(); }
    AuditLog::write($is_new ? 'új felvitel' : 'módosítás', $department, (string) $v['date'], (string) $v['slot'], (string) (($v['ward_room'] ?? '') ?: ($v['taj'] ?? '')));
    $this->messenger()->addStatus($this->t('Az előjegyzés mentve.')); $form_state->setRedirect('muteti_seb.booking',[],['query'=>['week'=>$v['date']]]);
  }

  private function applyAccessMode(array $form): array {
    $required_permission = $this->appointment ? 'edit surgery appointment' : 'create surgery appointment';
    $slot = (string) ($form['slot']['#value'] ?? '');
    if ($this->currentUser()->hasPermission($required_permission) && $this->canManageSlot($slot)) {
      return $form;
    }
    unset($form['actions']);
    foreach ($form as $key => &$element) {
      if (is_string($key) && !str_starts_with($key, '#') && is_array($element) && !in_array($key, ['date', 'slot', 'department'], TRUE)) {
        $element['#disabled'] = TRUE;
      }
    }
    $form['readonly_notice'] = [
      '#weight' => -100,
      '#markup' => '<p><strong>Csak megtekintés.</strong></p>',
    ];
    return $form;
  }

  private function canManageSlot(string $slot): bool {
    if (!in_array($slot, ['S-1', 'S-2'], TRUE)) {
      return TRUE;
    }
    $roles = $this->currentUser()->getRoles();
    return in_array('muteti_orvos2', $roles, TRUE) || in_array('muteti_boss', $roles, TRUE);
  }
}
