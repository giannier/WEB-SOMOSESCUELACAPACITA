<?php
/**
 * Validation and sanitizing of the Hoja de Reclamación fields
 * (Art. 5, D.S. 011-2011-PCM). Error messages are user-facing Spanish.
 * Nothing reaches the PDF or the e-mail without passing through here.
 */

declare(strict_types=1);

final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @var array<string,mixed> */
    private array $clean = [];

    private const DOC_TYPES = ['DNI', 'CE', 'Pasaporte', 'RUC'];
    private const ITEM_TYPES = ['Producto', 'Servicio'];
    private const CLAIM_TYPES = ['Reclamo', 'Queja'];

    // detail/request caps are what lets the sheet fit one A4 page (see ClaimPdf).
    private const MAX = [
        'name' => 120, 'document_number' => 20, 'address' => 200, 'phone' => 25, 'email' => 120,
        'guardian_name' => 120, 'guardian_address' => 200, 'guardian_phone' => 25, 'guardian_email' => 120,
        'item_description' => 300, 'detail' => 1800, 'request' => 700,
    ];

    public function validate(array $post): bool
    {
        $this->errors = [];
        $this->clean = [];

        $this->text($post, 'name', 'Nombre y apellidos', true);
        $this->choice($post, 'document_type', 'el tipo de documento', self::DOC_TYPES);
        $this->documentNumber($post);
        $this->text($post, 'address', 'Domicilio', true);
        $this->phone($post, 'phone', 'Teléfono');
        $this->email($post, 'email', 'Correo electrónico');

        $minor = !empty($post['is_minor']);
        $this->clean['is_minor'] = $minor;
        if ($minor) {
            $this->text($post, 'guardian_name', 'Nombre del padre o representante', true);
            $this->text($post, 'guardian_address', 'Domicilio del representante', true);
            $this->phone($post, 'guardian_phone', 'Teléfono del representante');
            $this->email($post, 'guardian_email', 'Correo del representante');
        } else {
            foreach (['guardian_name', 'guardian_address', 'guardian_phone', 'guardian_email'] as $f) {
                $this->clean[$f] = '';
            }
        }

        $this->choice($post, 'item_type', 'el tipo de bien', self::ITEM_TYPES);
        $this->text($post, 'item_description', 'Descripción del producto o servicio', true);
        $this->amount($post);

        $this->choice($post, 'claim_type', 'el tipo de solicitud', self::CLAIM_TYPES);
        $this->text($post, 'detail', 'Detalle', true, 20);
        $this->text($post, 'request', 'Pedido del consumidor', true, 10);

        // In the virtual book this replaces the signature (Art. 5).
        if (empty($post['consent_truth'])) {
            $this->errors['consent_truth'] = 'Debes confirmar que los datos son correctos.';
        }
        $this->clean['consent_truth'] = true;
        if (empty($post['consent_privacy'])) {
            $this->errors['consent_privacy'] = 'Debes aceptar la política de privacidad.';
        }
        $this->clean['consent_privacy'] = true;

        return $this->errors === [];
    }

    public function errors(): array { return $this->errors; }
    public function data(): array   { return $this->clean; }

    private function text(array $p, string $field, string $label, bool $required, int $min = 2): void
    {
        $raw = (string) ($p[$field] ?? '');
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $this->errors[$field] = $label . ' contiene caracteres no válidos.';
            $this->clean[$field] = '';
            return;
        }

        $v = $this->normalize($raw);
        // Only multi-line fields keep line breaks; the rest is single-line, so no
        // CR/LF can ever reach a mail header.
        if (!in_array($field, ['detail', 'request', 'item_description'], true)) {
            $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
        }

        if ($v === '') {
            if ($required) {
                $this->errors[$field] = $label . ' es obligatorio.';
            }
            $this->clean[$field] = '';
            return;
        }

        // Typical spam signals: many links, HTML anchors, BBCode.
        if (preg_match_all('~(?:https?://|www\.)~i', $v) > 2
            || stripos($v, '<a ') !== false || stripos($v, '[url=') !== false) {
            $this->errors[$field] = $label . ' no puede incluir enlaces.';
            return;
        }

        if (mb_strlen($v) < $min) {
            $this->errors[$field] = $label . ' es demasiado corto.';
        }
        $max = self::MAX[$field] ?? 300;
        if (mb_strlen($v) > $max) {
            $v = mb_substr($v, 0, $max);
        }
        $this->clean[$field] = $v;
    }

    private function choice(array $p, string $field, string $label, array $valid): void
    {
        $v = trim((string) ($p[$field] ?? ''));
        if (!in_array($v, $valid, true)) {
            $this->errors[$field] = 'Selecciona ' . $label . '.';
            $this->clean[$field] = '';
            return;
        }
        $this->clean[$field] = $v;
    }

    private function documentNumber(array $p): void
    {
        $type = $this->clean['document_type'] ?? '';
        $v = preg_replace('/[^A-Za-z0-9]/', '', (string) ($p['document_number'] ?? '')) ?? '';

        if ($v === '') {
            $this->errors['document_number'] = 'El número de documento es obligatorio.';
            $this->clean['document_number'] = '';
            return;
        }

        $lengths = ['DNI' => [8, 8], 'RUC' => [11, 11], 'CE' => [8, 12], 'Pasaporte' => [6, 12]];
        [$min, $max] = $lengths[$type] ?? [5, 20];
        $n = strlen($v);
        if ($n < $min || $n > $max) {
            $this->errors['document_number'] = $type === 'DNI'
                ? 'El DNI debe tener 8 dígitos.'
                : sprintf('El número de %s debe tener entre %d y %d caracteres.', $type ?: 'documento', $min, $max);
        }
        if (in_array($type, ['DNI', 'RUC'], true) && !ctype_digit($v)) {
            $this->errors['document_number'] = 'El ' . $type . ' solo admite números.';
        }
        $this->clean['document_number'] = strtoupper($v);
    }

    private function phone(array $p, string $field, string $label): void
    {
        $v = $this->normalize((string) ($p[$field] ?? ''));
        if ($v === '') {
            $this->errors[$field] = $label . ' es obligatorio.';
            $this->clean[$field] = '';
            return;
        }
        if (!preg_match('/^[+]?[0-9()\s.-]{6,25}$/', $v)) {
            $this->errors[$field] = $label . ' no tiene un formato válido.';
        }
        $this->clean[$field] = mb_substr($v, 0, self::MAX[$field] ?? 25);
    }

    private function email(array $p, string $field, string $label): void
    {
        // A line break inside an address would allow mail header injection.
        $v = str_replace(["\r", "\n", "\0", '%0a', '%0d'], '', trim((string) ($p[$field] ?? '')));
        if ($v === '') {
            $this->errors[$field] = $label . ' es obligatorio.';
            $this->clean[$field] = '';
            return;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > (self::MAX[$field] ?? 120)) {
            $this->errors[$field] = $label . ' no es válido.';
            $this->clean[$field] = '';
            return;
        }
        $this->clean[$field] = mb_strtolower($v);
    }

    private function amount(array $p): void
    {
        $raw = trim((string) ($p['amount'] ?? ''));
        if ($raw === '') {
            $this->clean['amount'] = null; // optional
            return;
        }
        $raw = str_replace([',', ' '], ['.', ''], $raw);
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 9999999) {
            $this->errors['amount'] = 'El monto reclamado no es válido.';
            $this->clean['amount'] = null;
            return;
        }
        $this->clean['amount'] = round((float) $raw, 2);
    }

    /**
     * Strips control chars and collapses whitespace. No htmlspecialchars here:
     * escaping happens at the output point (HTML e-mail); the PDF takes raw text.
     */
    private function normalize(string $v): string
    {
        $v = str_replace(["\0", "\r"], '', $v);
        $v = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}]/u', '', $v) ?? '';
        $v = preg_replace('/[ \t]+/', ' ', $v) ?? '';
        $v = preg_replace('/\n{3,}/', "\n\n", $v) ?? '';
        return trim($v);
    }
}
