(function (Drupal, once, drupalSettings) {
  'use strict';
  Drupal.behaviors.mutetiSurgeryBoard = {
    attach(context) {
      const bookingNavLinks = once('muteti-booking-scroll', '.muteti-booking-nav a', context);
      bookingNavLinks.forEach((link) => {
        link.addEventListener('click', () => sessionStorage.setItem('mutetiBookingScrollY', String(window.scrollY)));
      });
      if (context === document) {
        const savedScrollY = sessionStorage.getItem('mutetiBookingScrollY');
        if (savedScrollY !== null && document.querySelector('#muteti-booking-table')) {
          sessionStorage.removeItem('mutetiBookingScrollY');
          requestAnimationFrame(() => window.scrollTo(0, Number(savedScrollY)));
        }
      }
      const dayTypeSelects = once('muteti-day-type', '.muteti-day-type-select', context);
      dayTypeSelects.forEach((select) => {
        select.addEventListener('change', async () => {
          const previousValue = select.dataset.previousValue;
          select.disabled = true;
          try {
            const response = await fetch(drupalSettings.mutetiSeb.dayTypeEndpoint, {
              method: 'POST',
              headers: {'Content-Type': 'application/json'},
              credentials: 'same-origin',
              body: JSON.stringify({date: select.dataset.date, day_type: select.value})
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || `HTTP ${response.status}`);
            select.dataset.previousValue = select.value;
            const badge = select.closest('.muteti-day-card-shell').querySelector('.muteti-day-type');
            if (badge) badge.textContent = select.value;
            select.disabled = false;
          }
          catch (error) {
            select.value = previousValue;
            select.disabled = false;
            window.alert(error.message || 'A napfajta mentése sikertelen.');
          }
        });
      });
      const cards = once('muteti-drag', '.muteti-drag-card', context);
      const zones = once('muteti-drop', '.muteti-dropzone', context);
      let dragged = null;
      cards.forEach((card) => {
        card.addEventListener('dragstart', (event) => {
          dragged = card;
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', card.dataset.id);
        });
        card.addEventListener('dragover', (event) => {
          event.preventDefault();
          const zone = card.closest('.muteti-dropzone');
          if (dragged && dragged !== card && zone) zone.insertBefore(dragged, card);
        });
      });
      zones.forEach((zone) => {
        zone.addEventListener('dragover', (event) => { event.preventDefault(); zone.classList.add('is-over'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('is-over'));
        zone.addEventListener('drop', async (event) => {
          event.preventDefault(); zone.classList.remove('is-over');
          if (!dragged) return;
          if (event.target === zone || !event.target.closest('.muteti-drag-card')) zone.appendChild(dragged);
          const orderedIds = [...zone.querySelectorAll('.muteti-drag-card')].map((item) => Number(item.dataset.id));
          try {
            const response = await fetch(drupalSettings.mutetiSeb.endpoint, {
              method: 'POST', headers: {'Content-Type': 'application/json'}, credentials: 'same-origin',
              body: JSON.stringify({id: Number(dragged.dataset.id), room: zone.dataset.room || '', date: zone.dataset.date || '', ordered_ids: orderedIds})
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const result = await response.json();
            if (!result.ok) throw new Error(result.error || 'Unknown save error');
            dragged = null;
          }
          catch (error) {
            window.alert('A műtőbeosztás mentése sikertelen. Az oldal újratöltődik.');
            window.location.reload();
          }
        });
      });
    }
  };
})(Drupal, once, drupalSettings);
