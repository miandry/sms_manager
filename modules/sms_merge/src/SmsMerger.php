<?php

namespace Drupal\sms_merge;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates one sms node per new SMS found in json_sms nodes.
 */
class SmsMerger {

  public const SOURCE_BUNDLE = 'json_sms';
  public const TARGET_BUNDLE = 'sms';
  public const STATE_LAST_RUN = 'sms_merge.last_run';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected SmsParser $parser,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Merges every json_sms node that is new or changed since its last merge.
   *
   * @param int $limit
   *   Max json_sms nodes to process (0 = all).
   * @param bool $force
   *   Re-read unchanged nodes too (duplicates are still skipped).
   *
   * @return array{sources: int, created: int, duplicates: int, filtered: int, errors: int}
   */
  public function mergeAll(int $limit = 0, bool $force = FALSE): array {
    $total = ['sources' => 0, 'created' => 0, 'duplicates' => 0, 'filtered' => 0, 'errors' => 0];
    foreach ($this->pendingSourceIds($force) as $nid) {
      if ($limit && $total['sources'] >= $limit) {
        break;
      }
      $result = $this->mergeSource((int) $nid, $force);
      $total['sources']++;
      foreach (['created', 'duplicates', 'filtered', 'errors'] as $k) {
        $total[$k] += $result[$k];
      }
    }
    $this->recordRun($total);
    return $total;
  }

  /**
   * json_sms node IDs to process, oldest first.
   *
   * @return int[]
   */
  public function pendingSourceIds(bool $force = FALSE): array {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::SOURCE_BUNDLE)
      ->sort('nid')
      ->execute();
    if ($force || !$ids) {
      return array_map('intval', array_values($ids));
    }
    $known = $this->database->select('sms_merge_source', 's')
      ->fields('s', ['nid', 'body_hash'])
      ->condition('nid', $ids, 'IN')
      ->execute()
      ->fetchAllKeyed();
    $pending = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      if (($known[$node->id()] ?? NULL) !== $this->bodyHash($node)) {
        $pending[] = (int) $node->id();
      }
    }
    return $pending;
  }

  /**
   * Accepted senders (« Expéditeur » of the JSON item), normalized.
   *
   * @return string[]
   *   Empty = every sender is accepted.
   */
  public function allowedSenders(): array {
    $raw = (string) $this->configFactory->get('sms_merge.settings')->get('allowed_senders');
    $list = array_filter(array_map([$this, 'normalizeSender'], preg_split('/[\r\n,;]+/', $raw) ?: []));
    return array_values(array_unique($list));
  }

  public function senderAllowed(string $sender): bool {
    $allowed = $this->allowedSenders();
    return !$allowed || in_array($this->normalizeSender($sender), $allowed, TRUE);
  }

  /**
   * « Orange Money », « orange-money », « OrangeMoney » => « orangemoney ».
   */
  public function normalizeSender(string $sender): string {
    return mb_strtolower(preg_replace('/[\s\-_.]+/u', '', trim($sender)) ?? '');
  }

  /**
   * Forgets the merged state of every json_sms node (re-read at next merge).
   */
  public function resetSources(): void {
    $this->database->truncate('sms_merge_source')->execute();
  }

  /**
   * @return array{created: int, duplicates: int, filtered: int, errors: int, items: int, error: string}
   */
  public function mergeSource(int $nid, bool $force = FALSE): array {
    $result = ['created' => 0, 'duplicates' => 0, 'filtered' => 0, 'errors' => 0, 'items' => 0, 'error' => ''];
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::SOURCE_BUNDLE) {
      return $result;
    }
    $hash = $this->bodyHash($node);
    if (!$force && $this->database->query('SELECT body_hash FROM {sms_merge_source} WHERE nid = :nid', [':nid' => $nid])->fetchField() === $hash) {
      return $result;
    }

    $items = json_decode((string) $node->get('body')->value, TRUE);
    if (!is_array($items)) {
      $result['errors'] = 1;
      $result['error'] = 'JSON invalide : ' . json_last_error_msg();
      $this->logger->warning('json_sms @nid : @error', ['@nid' => $nid, '@error' => $result['error']]);
      $this->saveSource($nid, $hash, 0, $result['error']);
      return $result;
    }
    // A single SMS object instead of a list.
    if (isset($items['message'])) {
      $items = [$items];
    }

    foreach ($items as $item) {
      if (!is_array($item) || trim((string) ($item['message'] ?? '')) === '') {
        continue;
      }
      if (!$this->senderAllowed((string) ($item['sender'] ?? ''))) {
        $result['filtered']++;
        continue;
      }
      $result['items']++;
      try {
        $this->mergeItem($item, $nid) ? $result['created']++ : $result['duplicates']++;
      }
      catch (\Throwable $e) {
        $result['errors']++;
        $this->logger->error('json_sms @nid : SMS non fusionné (@msg)', ['@nid' => $nid, '@msg' => $e->getMessage()]);
      }
    }
    $this->saveSource($nid, $hash, $result['items'], $result['errors'] ? $result['errors'] . ' SMS en erreur' : '');
    if ($result['created']) {
      $this->logger->info('json_sms @nid : @created SMS créés, @dup doublons ignorés.', [
        '@nid' => $nid,
        '@created' => $result['created'],
        '@dup' => $result['duplicates'],
      ]);
    }
    return $result;
  }

  /**
   * Creates the sms node unless this SMS was already merged.
   *
   * @return bool
   *   TRUE if created, FALSE if duplicate.
   */
  public function mergeItem(array $item, int $sourceNid): bool {
    $key = $this->parser->key($item);
    $values = $this->parser->parse($item);
    $dateUtc = $this->toUtc($values['date']);

    // SMS created before this module (or by hand) with the same data.
    if ($this->existingSms($values, $dateUtc)) {
      $this->claim($key, $sourceNid);
      return FALSE;
    }
    // The primary key on the hash makes concurrent runs (cron + button) safe.
    if (!$this->claim($key, $sourceNid)) {
      return FALSE;
    }

    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $sms = $storage->create(['type' => self::TARGET_BUNDLE, 'status' => 1, 'uid' => 1] + [
        'title' => $this->title($values),
      ]);
      foreach ($values as $field => $value) {
        if (str_starts_with($field, 'field_') && $sms->hasField($field) && $value !== '' && $value !== NULL) {
          $sms->set($field, $value);
        }
      }
      if ($dateUtc && $sms->hasField('field_date_sms')) {
        $sms->set('field_date_sms', $dateUtc);
      }
      $sms->save();
    }
    catch (\Throwable $e) {
      $this->database->delete('sms_merge_item')->condition('hash', $key)->execute();
      throw $e;
    }
    $this->database->update('sms_merge_item')->fields(['sms_nid' => $sms->id()])->condition('hash', $key)->execute();
    return TRUE;
  }

  /**
   * Re-applies title() to every merged sms node, from its source JSON item.
   *
   * @return int
   *   Number of renamed nodes.
   */
  public function retitleMerged(): int {
    $map = $this->database->query('SELECT hash, sms_nid FROM {sms_merge_item} WHERE sms_nid > 0')->fetchAllKeyed();
    $storage = $this->entityTypeManager->getStorage('node');
    $renamed = 0;
    foreach ($storage->loadMultiple($this->pendingSourceIds(TRUE)) as $source) {
      $items = json_decode((string) $source->get('body')->value, TRUE);
      foreach (is_array($items) ? $items : [] as $item) {
        if (!is_array($item) || !($nid = $map[$this->parser->key($item)] ?? NULL)) {
          continue;
        }
        $sms = $storage->load($nid);
        $title = $this->title($this->parser->parse($item));
        if ($sms && $sms->label() !== $title) {
          $sms->setTitle($title)->save();
          $renamed++;
        }
      }
    }
    return $renamed;
  }

  /**
   * @return array{json_sms: int, sms: int, merged: int, pending: int, last_run: array<string, mixed>|null}
   */
  public function stats(): array {
    $count = fn(string $bundle) => (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)->condition('type', $bundle)->count()->execute();
    return [
      'json_sms' => $count(self::SOURCE_BUNDLE),
      'sms' => $count(self::TARGET_BUNDLE),
      'merged' => (int) $this->database->query('SELECT COUNT(*) FROM {sms_merge_item}')->fetchField(),
      'pending' => count($this->pendingSourceIds()),
      'last_run' => $this->state->get(self::STATE_LAST_RUN),
    ];
  }

  public function recordRun(array $total): void {
    $this->state->set(self::STATE_LAST_RUN, $total + ['time' => $this->time->getRequestTime()]);
  }

  protected function claim(string $key, int $sourceNid): bool {
    try {
      $this->database->insert('sms_merge_item')->fields([
        'hash' => $key,
        'source_nid' => $sourceNid,
        'created' => $this->time->getRequestTime(),
      ])->execute();
      return TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      return FALSE;
    }
  }

  protected function existingSms(array $values, ?string $dateUtc): bool {
    if (!$dateUtc || $values['field_telephone'] === '') {
      return FALSE;
    }
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TARGET_BUNDLE)
      ->condition('field_date_sms', $dateUtc)
      ->condition('field_telephone', $values['field_telephone'])
      ->range(0, 1);
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', self::TARGET_BUNDLE);
    if (isset($definitions['field_message'])) {
      $query->condition('field_message', $values['field_message']);
    }
    return (bool) $query->execute();
  }

  protected function saveSource(int $nid, string $hash, int $items, string $error): void {
    $this->database->merge('sms_merge_source')
      ->key('nid', $nid)
      ->fields([
        'body_hash' => $hash,
        'items' => $items,
        'processed' => $this->time->getRequestTime(),
        'error' => mb_substr($error, 0, 255),
      ])
      ->execute();
  }

  protected function bodyHash(NodeInterface $node): string {
    return hash('sha256', (string) $node->get('body')->value);
  }

  /**
   * Local date of the phone (site timezone) => UTC storage string.
   */
  protected function toUtc(string $date): ?string {
    if ($date === '') {
      return NULL;
    }
    try {
      $tz = $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC';
      $local = new \DateTime($date, new \DateTimeZone($tz));
    }
    catch (\Exception) {
      return NULL;
    }
    return $local->setTimezone(new \DateTimeZone('UTC'))->format(DateTimeItemInterface::DATETIME_STORAGE_FORMAT);
  }

  /**
   * « Type - Ref », e.g. « Envoyé - 8255319355 ».
   *
   * Without a reference (promos, alerts): « Type - Sender date ».
   */
  public function title(array $values): string {
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', self::TARGET_BUNDLE);
    $types = isset($definitions['field_type']) ? $definitions['field_type']->getSetting('allowed_values') : [];
    $type = (string) ($types[$values['field_type']] ?? ucfirst((string) $values['field_type']));
    $suffix = (string) $values['field_reference'];
    if ($suffix === '') {
      $suffix = trim(($values['sender'] ?: 'SMS') . ' ' . $values['date']);
    }
    return mb_substr($type . ' - ' . $suffix, 0, 255);
  }

}
