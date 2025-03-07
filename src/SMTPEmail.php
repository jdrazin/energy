<?php

namespace Src;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Copyright (c) 2016 Simple Matters Limited -- All rights reserved
 *
 */

class SMTPEmail extends Root
{
    private array $api;
    public function __construct()
    {
        parent::__construct();
    }
    public function email($content): void {
        $mail = new PHPMailer();
        try {
            $mail->SMTPDebug    = SMTP::DEBUG_SERVER;                 // Enable verbose debug output
            $smtp_settings      = $this->apis['smtp'];
            $mail->isSMTP();                                          // Set mailer to use SMTP
            $mail->Host         = $smtp_settings['host'];             // Specify main and backup SMTP servers
            $mail->SMTPAuth     = true;                               // enable SMTP authentication
            $mail->Username     = $smtp_settings['user'];             // SMTP username
            $mail->Password     = $smtp_settings['password'];         // SMTP password
            $mail->SMTPSecure   = $smtp_settings['security'];         // security method
            $mail->Port         = $smtp_settings['port'];             // TCP port to connect to
            $mail->setFrom($smtp_settings['user']);
            $mail->addAddress($smtp_settings['to']);
            $mail->isHTML($content['html'] ?? false);
            $mail->Subject  = $content['subject']   ?? 'No subject';
            $mail->Body     = $content['bodyHTML']  ?? 'No HTML body';
            $mail->AltBody  = $content['bodyAlt']   ?? 'No alt body';
            $mail->send();
            echo 'Message has been sent';
    }
    catch (Exception $e) {
        echo 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
        }
    }
}
