<?php
/**
 * Sends the Hoja de Reclamación by e-mail.
 *
 * Two independent messages (company first, then consumer), each with the PDF.
 * With smtp.dry_run the full MIME
 * messages are written to storage/correo/<claim>-<empresa|consumidor>.eml, nothing is sent.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

final class Notifier
{
    private array $smtp;
    private array $provider;
    private int $responseDays;
    private string $storage;

    public function __construct(array $config)
    {
        $this->smtp = $config['smtp'];
        $this->provider = $config['provider'];
        $this->responseDays = $config['response_business_days'];
        $this->storage = $config['storage'];
    }

    public function dryRun(): bool
    {
        return $this->smtp['dry_run'] === true;
    }

    public function configured(): bool
    {
        return $this->dryRun() || $this->smtp['password'] !== '';
    }

    /**
     * Company notification: its own message to the internal mailbox, PDF attached.
     * Independent of the consumer copy so a failure of one never blocks the other.
     *
     * @return string 'sent' or the path of the .eml written in dry-run mode
     * @throws RuntimeException when sending fails
     */
    public function sendCompany(array $sheet, string $pdf): string
    {
        return $this->deliver($sheet, $pdf, 'empresa');
    }

    /**
     * Consumer copy (Reply-To = company mailbox).
     *
     * @return string 'sent' or the path of the .eml written in dry-run mode
     * @throws RuntimeException when sending fails
     */
    public function sendConsumer(array $sheet, string $pdf): string
    {
        return $this->deliver($sheet, $pdf, 'consumidor');
    }

    private function deliver(array $sheet, string $pdf, string $kind): string
    {
        if (!$this->configured()) {
            throw new RuntimeException('SMTP password is not configured.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->smtp['host'];
            $mail->Port = $this->smtp['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtp['username'];
            $mail->Password = $this->smtp['password'];
            $mail->SMTPSecure = $this->smtp['security'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = $this->smtp['timeout'];
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';

            if (!$this->smtp['verify_certificate']) {
                $mail->SMTPOptions = ['ssl' => [
                    'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
                ]];
            }

            $mail->setFrom($this->smtp['username'], $this->smtp['from_name']);
            if ($kind === 'empresa') {
                $mail->addAddress($this->smtp['internal_bcc']);
                $mail->addReplyTo($sheet['email'], $sheet['name']);
                $mail->Subject = sprintf('[Nuevo reclamo] %s %s - %s', $sheet['claim_type'], $sheet['claim_number'], $sheet['name']);
                $mail->isHTML(false);
                $mail->Body = $this->companyBody($sheet);
            } else {
                $mail->addAddress($sheet['email'], $sheet['name']);
                // The consumer's replies land in the company mailbox.
                $mail->addReplyTo($this->smtp['internal_bcc'], $this->provider['trade_name']);
                $mail->Subject = sprintf('%s %s registrado - %s', $sheet['claim_type'], $sheet['claim_number'], $this->provider['trade_name']);
                $mail->isHTML(true);
                $mail->Body = $this->htmlBody($sheet);
                $mail->AltBody = $this->textBody($sheet);
            }
            $mail->addStringAttachment(
                $pdf,
                'Hoja-de-Reclamacion-' . $sheet['claim_number'] . '.pdf',
                PHPMailer::ENCODING_BASE64,
                'application/pdf'
            );

            if ($this->dryRun()) {
                $mail->preSend(); // builds the MIME message without connecting
                $dir = $this->storage . '/correo';
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                    throw new RuntimeException('Cannot create the dry-run mail directory.');
                }
                $path = $dir . '/' . $sheet['claim_number'] . '-' . $kind . '.eml';
                if (file_put_contents($path, $mail->getSentMIMEMessage(), LOCK_EX) === false) {
                    throw new RuntimeException('Cannot write the dry-run message.');
                }
                return $path;
            }

            $mail->send();
            return 'sent';
        } catch (PHPMailerException $e) {
            // PHPMailer messages may include server details: log only, never show.
            throw new RuntimeException('SMTP failure: ' . $e->getMessage(), 0, $e);
        }
    }

    private function companyBody(array $h): string
    {
        return implode("\n", [
            'Nuevo reclamo registrado en el Libro de Reclamaciones.',
            '',
            'Número: ' . $h['claim_number'],
            'Tipo: ' . $h['claim_type'],
            'Fecha de registro: ' . $h['date_long'],
            'Consumidor: ' . $h['name'] . ' (' . $h['document_type'] . ' ' . $h['document_number'] . ')',
            'Correo del consumidor: ' . $h['email'],
            'Bien contratado: ' . $h['item_type'] . ' - ' . $h['item_description'],
            '',
            'La Hoja de Reclamación completa va adjunta en PDF.',
            'Plazo de respuesta: ' . $this->responseDays . ' días hábiles (Ley 29571, art. 24).',
        ]);
    }

    private function htmlBody(array $h): string
    {
        $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $rows = '';
        foreach ([
            'Número'            => $h['claim_number'],
            'Tipo'              => $h['claim_type'],
            'Fecha de registro' => $h['date_long'],
            'Consumidor'        => $h['name'],
            'Documento'         => $h['document_type'] . ' ' . $h['document_number'],
            'Bien contratado'   => $h['item_type'] . ' - ' . $h['item_description'],
        ] as $label => $value) {
            $rows .= '<tr>'
                . '<td style="padding:7px 14px 7px 0;color:#6e6a78;font-size:13px;white-space:nowrap;vertical-align:top">' . $e($label) . '</td>'
                . '<td style="padding:7px 0;color:#26212e;font-size:13px;font-weight:600">' . $e($value) . '</td>'
                . '</tr>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"></head>'
            . '<body style="margin:0;padding:24px;background:#f7f4fb;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e3dded">'
            . '<div style="background:#4a1e75;padding:20px 26px;border-bottom:4px solid #f89f00">'
            . '<div style="color:#fff;font-size:17px;font-weight:700">' . $e($this->provider['trade_name']) . '</div>'
            . '<div style="color:#f89f00;font-size:13px;font-weight:700;margin-top:3px">Libro de Reclamaciones</div>'
            . '</div>'
            . '<div style="padding:26px">'
            . '<p style="margin:0 0 16px;color:#26212e;font-size:15px">Hola ' . $e($h['name']) . ',</p>'
            . '<p style="margin:0 0 20px;color:#4a4655;font-size:14px;line-height:1.6">'
            . 'Registramos tu ' . $e(mb_strtolower($h['claim_type'])) . ' en nuestro Libro de Reclamaciones. '
            . 'Adjuntamos la Hoja de Reclamación en PDF como constancia.</p>'
            . '<table style="width:100%;border-collapse:collapse;background:#f7f4fb"><tbody>' . $rows . '</tbody></table>'
            . '<p style="margin:20px 0 0;color:#4a4655;font-size:14px;line-height:1.6">'
            . 'Te responderemos en un plazo no mayor de <strong>' . $this->responseDays . ' días hábiles</strong>, '
            . 'conforme al artículo 24 de la Ley 29571.</p>'
            . '<p style="margin:16px 0 0;color:#6e6a78;font-size:12px;line-height:1.6">'
            . 'Presentar este reclamo no limita tu derecho a acudir a otras vías de solución de controversias '
            . 'ni es requisito previo para denunciar ante el INDECOPI.</p>'
            . '</div>'
            . '<div style="padding:16px 26px;border-top:1px solid #e3dded;color:#6e6a78;font-size:12px">'
            . $e($this->provider['legal_name']) . ' - RUC ' . $e($this->provider['ruc']) . '<br>'
            . $e($this->provider['address']) . '<br>' . $e($this->provider['web'])
            . '</div></div></body></html>';
    }

    private function textBody(array $h): string
    {
        return implode("\n", [
            $this->provider['trade_name'] . ' - Libro de Reclamaciones',
            '',
            'Hola ' . $h['name'] . ',',
            '',
            'Registramos tu ' . mb_strtolower($h['claim_type']) . '. Adjuntamos la Hoja de Reclamación en PDF.',
            '',
            'Número: ' . $h['claim_number'],
            'Tipo: ' . $h['claim_type'],
            'Fecha de registro: ' . $h['date_long'],
            'Consumidor: ' . $h['name'] . ' (' . $h['document_type'] . ' ' . $h['document_number'] . ')',
            'Bien contratado: ' . $h['item_type'] . ' - ' . $h['item_description'],
            '',
            'Te responderemos en un plazo no mayor de ' . $this->responseDays . ' días hábiles,',
            'conforme al artículo 24 de la Ley 29571.',
            '',
            'Presentar este reclamo no limita tu derecho a acudir a otras vías de solución',
            'de controversias ni es requisito previo para denunciar ante el INDECOPI.',
            '',
            $this->provider['legal_name'] . ' - RUC ' . $this->provider['ruc'],
            $this->provider['address'],
            $this->provider['web'],
        ]);
    }
}
