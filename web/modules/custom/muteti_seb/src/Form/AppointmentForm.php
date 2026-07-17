<?php

namespace Drupal\muteti_seb\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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
    $form['aznm']=['#type'=>'checkbox','#title'=>$this->t('AZNM.'),'#default_value'=>$a->aznm ?? 0];
    $form['patient_name']=['#type'=>'textfield','#title'=>$this->t('Beteg neve @code',['@code'=>date('n-j',strtotime($date)).'-'.$slot]),'#required'=>TRUE,'#default_value'=>$a->patient_name ?? ''];
    $form['birth_date']=['#type'=>'date','#title'=>$this->t('Születési dátum'),'#default_value'=>$a->birth_date ?? ''];
    foreach (['taj'=>'TAJ','contact'=>'Elérhetőség','ward_room'=>'Kórterem','diagnosis'=>'Diagnózis','operation_name'=>'Műtét'] as $key=>$label) $form[$key]=['#type'=>'textfield','#title'=>$this->t($label),'#required'=>in_array($key,['diagnosis','operation_name']), '#default_value'=>$a->{$key} ?? ''];
    foreach (['laparoscope'=>'Laparoszkóp','mesh'=>'Háló','laterality'=>'Oldaliság','blood_type'=>'Vércsoport'] as $key=>$label) $form[$key]=['#type'=>'select','#title'=>$this->t($label),'#options'=>['?'=>'?','Igen'=>'Igen','Nem'=>'Nem','Bal'=>'Bal','Jobb'=>'Jobb','A+'=>'A+','A-'=>'A-','B+'=>'B+','B-'=>'B-','0+'=>'0+','0-'=>'0-','AB+'=>'AB+','AB-'=>'AB-'],'#default_value'=>$a->{$key} ?? '?'];
    $form['notes']=['#type'=>'textarea','#title'=>$this->t('Egyéb info'),'#default_value'=>$a->notes ?? ''];
    $doctors=['0'=>'-']+$this->database->select('muteti_doctor','d')->fields('d',['id','name'])->condition('department',$department)->condition('active',1)->orderBy('name')->execute()->fetchAllKeyed();
    foreach (['doctor_id'=>'Orvos','assistant1_id'=>'Asszisztens 1','assistant2_id'=>'Asszisztens 2','assistant3_id'=>'Asszisztens 3'] as $key=>$label) $form[$key]=['#type'=>'select','#title'=>$this->t($label),'#options'=>$doctors,'#default_value'=>$a->{$key} ?? 0];
    $form['actions']=['#type'=>'actions']; $form['actions']['submit']=['#type'=>'submit','#value'=>$this->t('Mentés'),'#button_type'=>'primary']; return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $v=$form_state->getValues(); $fields=[];
    foreach (['aznm','patient_name','birth_date','taj','contact','ward_room','diagnosis','operation_name','laparoscope','mesh','laterality','blood_type','notes','doctor_id','assistant1_id','assistant2_id','assistant3_id'] as $key) $fields[$key]=$v[$key] ?: NULL;
    $fields['changed']=\Drupal::time()->getRequestTime();
    if ($this->appointment) $this->database->update('muteti_appointment')->fields($fields)->condition('id',$this->appointment->id)->execute();
    else { $fields += ['department'=>UserDepartment::get($this->currentUser()),'admission_date'=>$v['date'],'slot_type'=>$v['slot'],'created_by'=>(int)$this->currentUser()->id(),'created'=>$fields['changed']]; $this->database->insert('muteti_appointment')->fields($fields)->execute(); }
    $this->messenger()->addStatus($this->t('Az előjegyzés mentve.')); $form_state->setRedirect('muteti_seb.booking',[],['query'=>['week'=>$v['date']]]);
  }
}
