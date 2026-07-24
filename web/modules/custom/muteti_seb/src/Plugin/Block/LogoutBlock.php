<?php

namespace Drupal\muteti_seb\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

#[Block(
  id: 'muteti_logout_block',
  admin_label: new TranslatableMarkup('Műtéti rendszer – Kijelentkezés'),
  category: new TranslatableMarkup('Műtéti rendszer'),
)]
final class LogoutBlock extends BlockBase {

  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($account->isAuthenticated())->cachePerUser();
  }

  public function build(): array {
    $link = Link::fromTextAndUrl(
      $this->t('Kijelentkezés'),
      Url::fromRoute('user.logout', [], [
        'attributes' => [
          'class' => ['muteti-logout-link'],
          'title' => $this->t('Kijelentkezés a műtéti rendszerből'),
        ],
      ])
    )->toRenderable();
    $link['#cache']['contexts'][] = 'user';
    return $link;
  }

}

