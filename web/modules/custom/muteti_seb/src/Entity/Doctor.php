<?php

namespace Drupal\muteti_seb\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\muteti_seb\DoctorListBuilder;
use Drupal\muteti_seb\Form\DoctorForm;

#[ContentEntityType(
  id: 'muteti_doctor',
  label: new TranslatableMarkup('Orvos'),
  label_collection: new TranslatableMarkup('Orvosok'),
  handlers: [
    'list_builder' => DoctorListBuilder::class,
    'form' => [
      'add' => DoctorForm::class,
      'edit' => DoctorForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'muteti_doctor',
  admin_permission: 'administer surgery system',
  entity_keys: [
    'id' => 'id',
    'label' => 'name',
  ],
  links: [
    'collection' => '/admin/content/muteti-orvos-registry',
    'add-form' => '/admin/content/muteti-orvos-registry/add',
    'edit-form' => '/admin/content/muteti-orvos-registry/{muteti_doctor}/edit',
    'delete-form' => '/admin/content/muteti-orvos-registry/{muteti_doctor}/delete',
  ],
)]
final class Doctor extends ContentEntityBase {

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Azonosító'))
      ->setReadOnly(TRUE);
    $fields['legacy_nid'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Drupal 7 azonosító'))
      ->setReadOnly(TRUE);
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Felhasználónév'))
      ->setDescription(new TranslatableMarkup('A kapcsolt Drupal-felhasználó. Nem kötelező.'))
      ->setSetting('target_type', 'user')
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
      ]);
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Orvos neve'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 100)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0]);
    $fields['background_color'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Háttérszín'))
      ->setSetting('max_length', 10)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 20]);
    $fields['background_gif'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Háttér-GIF URI'))
      ->setSetting('max_length', 255)
      ->setReadOnly(TRUE);
    $fields['text_color'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Betűszín'))
      ->setSetting('max_length', 10)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 30]);
    $fields['department'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Osztály'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 50)
      ->setDefaultValue('Sebészet')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 40]);
    $fields['active'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Aktív'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 50]);

    return $fields;
  }

}
