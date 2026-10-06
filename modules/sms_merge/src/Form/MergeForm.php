<?php

namespace Drupal\sms_merge\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\sms_merge\SmsMerger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Status, cron settings and the manual « Fusionner maintenant » button.
 */
class MergeForm extends FormBase {

  public function __construct(
    protected SmsMerger $merger,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('sms_merge.merger'),
      $container->get('date.formatter'),
    );
  }

  public function getFormId(): string {
    return 'sms_merge_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $stats = $this->merger->stats();
    $config = $this->config('sms_merge.settings');
    $last = $stats['last_run'];

    $form['status'] = [
      '#type' => 'table',
      '#header' => [$this->t('Indicateur'), $this->t('Valeur')],
      '#rows' => [
        [$this->t('Lots json_sms'), $stats['json_sms']],
        [$this->t('Lots nouveaux ou modifiés à fusionner'), ['data' => ['#markup' => '<strong>' . $stats['pending'] . '</strong>']]],
        [$this->t('Contenus sms'), $stats['sms']],
        [$this->t('SMS déjà fusionnés (clés anti-doublon)'), $stats['merged']],
        [
          $this->t('Dernière fusion'),
          $last ? $this->t('@date — @created créé(s), @dup doublon(s) ignoré(s), @filtered hors expéditeurs, @err erreur(s)', [
            '@date' => $this->dateFormatter->format($last['time'], 'short'),
            '@created' => $last['created'],
            '@dup' => $last['duplicates'],
            '@filtered' => $last['filtered'] ?? 0,
            '@err' => $last['errors'],
          ]) : $this->t('Jamais'),
        ],
      ],
    ];

    $form['senders'] = [
      '#type' => 'details',
      '#title' => $this->t('Expéditeurs'),
      '#open' => TRUE,
    ];
    $form['senders']['allowed_senders'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Expéditeurs acceptés'),
      '#default_value' => (string) $config->get('allowed_senders'),
      '#rows' => 4,
      '#placeholder' => "MVola\nOrange Money",
      '#description' => $this->t('Un expéditeur par ligne (champ <code>sender</code> du JSON, ex. <em>MVola</em>, <em>Orange Money</em>). Seuls ces SMS sont fusionnés ; majuscules, espaces et tirets sont ignorés. Laisser vide pour tout accepter.'),
    ];
    $form['senders']['save_senders'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enregistrer les expéditeurs'),
      '#submit' => ['::saveSettings'],
    ];

    $form['merge'] = [
      '#type' => 'details',
      '#title' => $this->t('Fusion manuelle'),
      '#open' => TRUE,
    ];
    $form['merge']['force'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Relire aussi les lots déjà fusionnés'),
      '#description' => $this->t('Les SMS déjà présents restent ignorés : aucun doublon n’est créé.'),
    ];
    $form['merge']['run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Fusionner maintenant'),
      '#button_type' => 'primary',
      '#submit' => ['::runMerge'],
    ];

    $form['cron'] = [
      '#type' => 'details',
      '#title' => $this->t('Cron'),
      '#open' => FALSE,
    ];
    $form['cron']['cron_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Fusion automatique à chaque cron'),
      '#default_value' => (bool) $config->get('cron_enabled'),
    ];
    $form['cron']['cron_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Lots json_sms traités par passage'),
      '#default_value' => (int) ($config->get('cron_limit') ?: 50),
      '#min' => 1,
      '#max' => 1000,
    ];
    $form['cron']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enregistrer les réglages'),
      '#submit' => ['::saveSettings'],
    ];
    $form['cron']['help'] = [
      '#markup' => '<p>' . $this->t('Le cron du site (<a href=":url">Cron</a>) doit tourner régulièrement, par ex. <code>*/15 * * * * drush cron</code>.', [
        ':url' => Url::fromRoute('system.cron_settings')->toString(),
      ]) . '</p>',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function saveSettings(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->getEditable('sms_merge.settings');
    $senders = implode("\n", array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $form_state->getValue('allowed_senders')) ?: [])));
    $sendersChanged = $senders !== (string) $config->get('allowed_senders');
    $config
      ->set('cron_enabled', (bool) $form_state->getValue('cron_enabled'))
      ->set('cron_limit', (int) $form_state->getValue('cron_limit'))
      ->set('allowed_senders', $senders)
      ->save();
    if ($sendersChanged) {
      // Every lot must be re-read with the new sender list.
      $this->merger->resetSources();
    }
    $this->messenger()->addStatus($this->t('Réglages enregistrés.'));
  }

  public function runMerge(array &$form, FormStateInterface $form_state): void {
    $force = (bool) $form_state->getValue('force');
    $ids = $this->merger->pendingSourceIds($force);
    if (!$ids) {
      $this->messenger()->addStatus($this->t('Aucun lot json_sms nouveau ou modifié : rien à fusionner.'));
      return;
    }
    $operations = [];
    foreach ($ids as $nid) {
      $operations[] = [[static::class, 'batchOperation'], [$nid, $force]];
    }
    batch_set([
      'title' => $this->t('Fusion de @count lot(s) json_sms', ['@count' => count($ids)]),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
    ]);
  }

  public static function batchOperation(int $nid, bool $force, array &$context): void {
    $context['results'] += ['sources' => 0, 'created' => 0, 'duplicates' => 0, 'filtered' => 0, 'errors' => 0];
    $result = \Drupal::service('sms_merge.merger')->mergeSource($nid, $force);
    $context['results']['sources']++;
    foreach (['created', 'duplicates', 'filtered', 'errors'] as $k) {
      $context['results'][$k] += $result[$k];
    }
    $context['message'] = t('Lot json_sms @nid : @created créé(s), @dup doublon(s).', [
      '@nid' => $nid,
      '@created' => $result['created'],
      '@dup' => $result['duplicates'],
    ]);
  }

  public static function batchFinished(bool $success, array $results): void {
    $results += ['sources' => 0, 'created' => 0, 'duplicates' => 0, 'filtered' => 0, 'errors' => 0];
    \Drupal::service('sms_merge.merger')->recordRun($results);
    $messenger = \Drupal::messenger();
    $message = t('@sources lot(s) traité(s) : @created SMS créé(s), @dup doublon(s) ignoré(s), @filtered hors expéditeurs acceptés, @err erreur(s).', [
      '@sources' => $results['sources'],
      '@created' => $results['created'],
      '@dup' => $results['duplicates'],
      '@filtered' => $results['filtered'],
      '@err' => $results['errors'],
    ]);
    $success && !$results['errors'] ? $messenger->addStatus($message) : $messenger->addWarning($message);
  }

}
