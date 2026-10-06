<?php

namespace Drupal\sms_merge;

/**
 * Extracts sms field values from one JSON SMS item.
 *
 * Handles the MVola / Airtel Money / Orange Money wordings in French and
 * Malagasy (« Nandefa vola … », « Nahazo … », « Vous avez recu … »).
 */
class SmsParser {

  private const NUMBER = '(\d{1,3}(?:[ \x{00A0}.]\d{3})+|\d+)(?:[.,]\d+)?';

  /**
   * Type => message patterns, checked in order.
   */
  private const TYPES = [
    'retrait' => '/\b(retrait|nanala vola|withdraw)/iu',
    'depot' => '/\b(d[ée]p[ôo]t|nametraka vola|deposit)/iu',
    'paiement' => '/\b(paiement|nandoa|payment|pay[ée] )/iu',
    'envoye' => '/\b(nandefa vola|vous avez (?:envoy[ée]|transf[ée]r[ée])|sent|transfer(?:red)? to)/iu',
    'recu' => '/\b(nahazo|vous avez re[çc]u|received|recu de)/iu',
  ];

  /**
   * Unique key of an SMS: same phone, date, sender and text = same SMS.
   *
   * @param array<string, mixed> $item
   */
  public function key(array $item): string {
    $message = preg_replace('/\s+/u', ' ', trim((string) ($item['message'] ?? ''))) ?? '';
    return hash('sha256', implode('|', [
      preg_replace('/\D+/', '', (string) ($item['phone'] ?? '')),
      trim((string) ($item['date'] ?? '')),
      mb_strtolower(trim((string) ($item['sender'] ?? ''))),
      $message,
    ]));
  }

  /**
   * @param array<string, mixed> $item
   *   {sender, phone, message, amount, reference, date}.
   *
   * @return array<string, mixed>
   *   field_* values (date as local 'Y-m-d H:i:s', converted later).
   */
  public function parse(array $item): array {
    $message = trim((string) ($item['message'] ?? ''));
    $sender = trim((string) ($item['sender'] ?? ''));
    $type = $this->type($message);

    $amount = (float) ($item['amount'] ?? 0);
    if ($amount <= 0 && $type !== 'autre') {
      $amount = $this->firstAmount($message);
    }
    $reference = trim((string) ($item['reference'] ?? '')) ?: $this->reference($message);

    return [
      'field_expediteur' => $this->provider($sender),
      'field_type' => $type,
      'field_montant' => $amount,
      'field_frais' => $this->match('/(?:sarany|frais|fees?)\s*:?\s*' . self::NUMBER . '\s*Ar/iu', $message),
      'field_solde' => $this->balance($message),
      'field_reference' => $reference,
      'field_contact' => $this->contact($message),
      'field_telephone' => trim((string) ($item['phone'] ?? '')),
      'field_message' => $message,
      'date' => trim((string) ($item['date'] ?? '')),
      'sender' => $sender,
    ];
  }

  public function type(string $message): string {
    foreach (self::TYPES as $type => $pattern) {
      if (preg_match($pattern, $message)) {
        return $type;
      }
    }
    return 'autre';
  }

  public function provider(string $sender): string {
    $s = mb_strtolower(str_replace([' ', '-', '_'], '', $sender));
    return match (TRUE) {
      str_contains($s, 'mvola') || str_starts_with($s, 'mvepargne') => 'mvola',
      str_contains($s, 'orangemoney') => 'orange_money',
      str_contains($s, 'airtelmoney') => 'airtel_money',
      default => 'autre',
    };
  }

  private function firstAmount(string $message): float {
    return $this->match('/' . self::NUMBER . '\s*Ar\b/u', $message);
  }

  private function balance(string $message): float {
    return $this->match('/toe-?bolana(?:o|nao)(?:\s+dia)?\s*:?\s*' . self::NUMBER . '/iu', $message)
      ?: $this->match('/solde[^0-9]{0,40}?' . self::NUMBER . '\s*Ar/iu', $message)
      ?: $this->match('/balance[^0-9]{0,20}?' . self::NUMBER . '\s*Ar/iu', $message);
  }

  private function reference(string $message): string {
    if (preg_match('/\b(?:ref|trans\s?id|txn\s?id|id)\s*[:.]?\s*([A-Z0-9][A-Z0-9.\-]*[A-Z0-9])/iu', $message, $m)) {
      return $m[1];
    }
    return '';
  }

  /**
   * « Name 034xxxxxxx » counterpart of a transfer, when present.
   */
  private function contact(string $message): string {
    $phone = '((?:\+?261|0)\d{9})';
    $patterns = [
      "/amin['’](?:i|ny)\s+(.+?)\s+$phone/u",
      "/\b(?:de|vers|from|to)\s+([A-Z][\p{L} .'’-]{1,60}?)\s+$phone/u",
    ];
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message, $m)) {
        return trim($m[1]) . ' ' . $m[2];
      }
    }
    return '';
  }

  private function match(string $pattern, string $message): float {
    if (!preg_match($pattern, $message, $m)) {
      return 0.0;
    }
    return (float) preg_replace('/[^\d]/', '', $m[1]);
  }

}
