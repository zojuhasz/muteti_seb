(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.mutetiDoctorAvailability = {
    attach(context) {
      once('muteti-availability-toggle', '.muteti-availability-toggle', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const nextStatus = button.dataset.status === 'absent' ? 'work' : 'absent';
          button.disabled = true;
          try {
            const response = await fetch(drupalSettings.mutetiSeb.availabilityEndpoint, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              credentials: 'same-origin',
              body: JSON.stringify({date: button.dataset.date, status: nextStatus})
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
            button.dataset.status = result.status;
            button.textContent = result.status === 'absent' ? 'Távollevő vagyok' : 'Távollét?';
            button.classList.toggle('is-absent', result.status === 'absent');
            button.setAttribute('aria-pressed', result.status === 'absent' ? 'true' : 'false');
            button.disabled = false;
          }
          catch (error) {
            button.disabled = false;
            window.alert(error.message || 'A távollét mentése sikertelen.');
          }
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
