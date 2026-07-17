(function (Drupal, once) {
  'use strict';
  Drupal.behaviors.mutetiDoctorForm = {
    attach(context) {
      once('muteti-doctor-preview', '#muteti-seb-doctor-form', context).forEach((form) => {
        const name = form.querySelector('[name="name"]');
        const background = form.querySelector('[name="background_color"]');
        const text = form.querySelector('[name="text_color"]');
        const preview = form.querySelector('.muteti-doctor-live-preview');
        const update = () => {
          preview.textContent = name.value || Drupal.t('Orvos neve');
          preview.style.backgroundColor = background.value;
          preview.style.color = text.value;
        };
        [name, background, text].forEach((element) => element.addEventListener('input', update));
        update();
      });
    }
  };
})(Drupal, once);
