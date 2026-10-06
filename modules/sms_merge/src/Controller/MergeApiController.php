<?php

namespace Drupal\sms_merge\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\sms_merge\SmsMerger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON API of the SMS Manager front: unmerged lots and manual merge.
 *
 * The Vue app authenticates with the api_solutions token (Bearer header or
 * auth_token cookie), not with a Drupal session.
 */
class MergeApiController extends ControllerBase {

  public function __construct(
    protected SmsMerger $merger,
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('sms_merge.merger'),
      $container->get('database'),
    );
  }

  /**
   * GET /sms-merge/api/status.
   */
  public function status(Request $request): JsonResponse {
    $account = $this->account($request);
    if (!$account) {
      return $this->error('Authentification requise.', 401);
    }
    return new JsonResponse(['status' => 'ok'] + $this->payload($account));
  }

  /**
   * POST /sms-merge/api/run {force?: bool}.
   */
  public function run(Request $request): JsonResponse {
    $account = $this->account($request);
    if (!$account) {
      return $this->error('Authentification requise.', 401);
    }
    // A cross-site HTML form cannot send JSON without a CORS preflight.
    if (!str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
      return $this->error('Content-Type application/json requis.', 415);
    }
    if (!$account->hasPermission('administer sms merge')) {
      return $this->error('Vous n\'avez pas la permission de fusionner les SMS.', 403);
    }
    $body = json_decode((string) $request->getContent(), TRUE) ?: [];
    $force = !empty($body['force']);

    // Sources are processed one by one; a long run must not hit max_execution_time.
    @set_time_limit(0);
    $lock = \Drupal::lock();
    if (!$lock->acquire('sms_merge.run', 600)) {
      return $this->error('Une fusion est déjà en cours, réessayez dans un instant.', 409);
    }
    try {
      $result = $this->merger->mergeAll(0, $force);
    }
    catch (\Throwable $e) {
      $this->getLogger('sms_merge')->error('Fusion API : @msg', ['@msg' => $e->getMessage()]);
      return $this->error('La fusion a échoué. Consultez le journal Drupal.', 500);
    }
    finally {
      $lock->release('sms_merge.run');
    }

    return new JsonResponse([
      'status' => 'ok',
      'result' => $result,
      'message' => $this->summary($result),
    ] + $this->payload($account));
  }

  /**
   * Unmerged lots, merge errors and counters.
   */
  protected function payload(AccountInterface $account): array {
    $pending = $this->merger->pendingSourceIds();
    $errors = $this->database->query("SELECT nid, error FROM {sms_merge_source} WHERE error <> ''")->fetchAllKeyed();
    $stats = $this->merger->stats();
    $labels = [];
    $ids = array_unique(array_merge($pending, array_map('intval', array_keys($errors))));
    foreach ($this->entityTypeManager()->getStorage('node')->loadMultiple($ids) as $node) {
      $labels[(int) $node->id()] = $node->label();
    }
    return [
      'pending' => array_map(fn(int $nid) => ['id' => $nid, 'title' => $labels[$nid] ?? ''], $pending),
      'pending_count' => count($pending),
      'errors' => array_map(
        fn($nid, $error) => ['id' => (int) $nid, 'title' => $labels[(int) $nid] ?? '', 'error' => $error],
        array_keys($errors),
        $errors,
      ),
      'stats' => [
        'json_sms' => $stats['json_sms'],
        'sms' => $stats['sms'],
        'merged' => $stats['merged'],
      ],
      'last_run' => $stats['last_run'],
      'can_merge' => $account->hasPermission('administer sms merge'),
    ];
  }

  protected function summary(array $r): string {
    if (!$r['sources']) {
      return 'Aucun nouveau lot à fusionner.';
    }
    $parts = [
      $r['created'] . ' SMS créé(s)',
      $r['duplicates'] . ' doublon(s) ignoré(s)',
    ];
    if ($r['filtered']) {
      $parts[] = $r['filtered'] . ' filtré(s) (expéditeur)';
    }
    if ($r['errors']) {
      $parts[] = $r['errors'] . ' erreur(s)';
    }
    return $r['sources'] . ' lot(s) traité(s) : ' . implode(', ', $parts) . '.';
  }

  /**
   * Account of the api_solutions token, else the Drupal session user.
   */
  protected function account(Request $request): ?AccountInterface {
    $token = NULL;
    if (preg_match('/Bearer\s+(\S+)/i', (string) $request->headers->get('Authorization'), $m)) {
      $token = $m[1];
    }
    $token = $token ?: $request->cookies->get('auth_token');
    if ($token && \Drupal::hasService('api_solutions.api_crud')) {
      $user = \Drupal::service('api_solutions.api_crud')->validateBearerToken($token);
      if ($user && $user->isActive()) {
        return $user;
      }
    }
    $current = $this->currentUser();
    return $current->isAuthenticated() ? $current : NULL;
  }

  protected function error(string $message, int $code): JsonResponse {
    return new JsonResponse(['status' => 'error', 'message' => $message], $code);
  }

}
