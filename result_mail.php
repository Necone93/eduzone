<?php
require_once __DIR__ . '/admin_config.php';

function ez_result_mail_from(): string {
    return getenv('EDUZONE_MAIL_FROM') ?: 'no-reply@eduzone.rs';
}

/** Accepted means accepted by the local mail server, not delivered to the inbox. */
function ez_send_result_mail(string $subject, string $html, ?callable $transport = null): array {
    $from = ez_result_mail_from();
    $recipient = ADMIN_EMAIL;
    $reference = bin2hex(random_bytes(8));
    $accepted = false;
    $error = null;
    // The envelope sender is passed as a sendmail argument: allow only a simple address.
    if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._+-]*@[a-zA-Z0-9.-]+\z/', $from)
        || !filter_var($from, FILTER_VALIDATE_EMAIL)
        || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $error = 'Neispravna email adresa pošiljaoca ili primaoca.';
    } elseif ($transport === null && !function_exists('mail')) {
        $error = 'Hosting je isključio PHP mail() funkciju.';
    } else {
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = "From: EduZone <{$from}>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "X-EduZone-Reference: {$reference}\r\n";
        set_error_handler(function ($severity, $message) use (&$error) {
            $error = (string)$message;
            return true;
        });
        try {
            $send = $transport ?? 'mail';
            $accepted = (bool)$send($recipient, $encodedSubject,
                chunk_split(base64_encode($html)), $headers, '-f' . $from);
            if (!$accepted && $error === null) $error = 'Mail server nije prihvatio poruku.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            restore_error_handler();
        }
    }
    error_log('EduZone result mail ' . json_encode([
        'reference' => $reference,
        'status' => $accepted ? 'accepted' : 'failed',
        'error' => $error,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    return ['accepted' => $accepted, 'error' => $error, 'reference' => $reference];
}
