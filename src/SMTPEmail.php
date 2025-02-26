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

class SMTPEmail
{
    /**
     */
    public function email($credentials, $content): void {
        $mail = new PHPMailer();
        try {

            $mail = new PHPMailer;
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'visionsrenewable@gmail.com';
            $mail->Password = 'mQ9ZLsrSAeSlAIwhLYP1';
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;
            $mail->setFrom('visionsrenewable@gmail.com', 'V R');
            $mail->addAddress('jonathan@drazin.net', 'J D');
            $mail->isHTML(false);
            $mail->Subject  = 'Test subject';
            $mail->Body     = 'Test body';
            $mail->AltBody  = 'Test alt body';
            $mail->send();











            $mail->SMTPDebug = SMTP::DEBUG_SERVER;            // Enable verbose debug output
            $mail->isSMTP();                                  // Set mailer to use SMTP
            $mail->SMTPOptions = [
                                        'ssl' => [
                                                'verify_peer'       => false,
                                                'verify_peer_name'  => false,
                                                'allow_self_signed' => true
                                                ]
                                  ];
            $mail->Host         = $credentials['host'];             // Specify main and backup SMTP servers
            $mail->SMTPAuth     = true;                             // enable SMTP authentication
            $mail->Username     = $credentials['user'];             // SMTP username
            $mail->Password     = $credentials['password'];         // SMTP password
            $mail->SMTPSecure   = PHPMailer::ENCRYPTION_SMTPS;      // security method
            $mail->Port         = $credentials['port'];             // TCP port to connect to
            if ($from = $credentials['from']) {
                $mail->setFrom($from['email'], $from['name']);
            }
            if ($to = $credentials['to']) {
                $mail->addAddress($to['email'], $to['name']);
            }
            if ($cc = $credentials['cc']) {
                $mail->addCC($cc['email']);
            }
            if ($bcc = $credentials['bcc']) {
                $mail->addBCC($bcc['email']);
            }
            if ($reply = $credentials['reply']) {
                $mail->addReplyTo($reply['email']);
            }
            $mail->isHTML($content['html'] ?? false);
            $mail->Subject  = $content['subject']   ?? '';
            $mail->Body     = $content['bodyHTML']  ?? '';
            $mail->AltBody  = $content['bodyAlt']   ?? '';
            $mail->send();
            echo 'Message has been sent';
    }
    catch (Exception $e) {
        echo 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
        }
    }
}
