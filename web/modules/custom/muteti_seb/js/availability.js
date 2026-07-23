(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.mutetiDoctorAvailability = {
    attach(context) {
      once('muteti-availability-toggle', '.muteti-availability-toggle, .muteti-away-toggle', context).forEach((button) => {
        button.addEventListener('click', async () => {
          const activeStatus = button.classList.contains('muteti-away-toggle') ? 'away' : 'absent';
          const nextStatus = button.dataset.status === activeStatus ? 'work' : activeStatus;
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
            document.querySelectorAll(
              `.muteti-availability-toggle[data-date="${result.date}"], .muteti-away-toggle[data-date="${result.date}"]`
            ).forEach((relatedButton) => {
              const isAbsence = relatedButton.classList.contains('muteti-availability-toggle');
              const isActive = result.status === (isAbsence ? 'absent' : 'away');
              relatedButton.dataset.status = result.status;
              relatedButton.textContent = isAbsence
                ? (isActive ? 'Távollevő vagyok' : 'Távollét?')
                : (isActive ? 'Idegenben vagyok' : 'Idegenben?');
              relatedButton.classList.toggle('is-absent', isAbsence && isActive);
              relatedButton.classList.toggle('is-away', !isAbsence && isActive);
              relatedButton.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            button.disabled = false;
          }
          catch (error) {
            button.disabled = false;
            window.alert(error.message || 'A napi állapot mentése sikertelen.');
          }
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
