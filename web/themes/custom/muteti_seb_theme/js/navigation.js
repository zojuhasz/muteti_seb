(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.mutetiCompactNavigation = {
    attach(context) {
      once('muteti-compact-navigation', '.muteti-menu-toggle', context).forEach((button) => {
        const navigation = document.getElementById(button.getAttribute('aria-controls'));
        if (!navigation) {
          return;
        }

        button.addEventListener('click', () => {
          const open = button.getAttribute('aria-expanded') === 'true';
          button.setAttribute('aria-expanded', open ? 'false' : 'true');
          navigation.classList.toggle('is-open', !open);
        });
      });
    }
  };
})(Drupal, once);

