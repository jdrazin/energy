<?php

namespace Src;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Copyright (c) 2016 Simple Matters Limited -- All rights reserved
 *
 */

class SMTPEmail
{
    /**
     * @throws Exception
     */
    public function email($credentials, $content): void
    {
        $mail = new PHPMailer();
        $mail->SMTPDebug = 3;                             // Enable verbose debug output
        $mail->Debugoutput = 'html';
        $mail->isSMTP();                                  // Set mailer to use SMTP
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true]];
        $mail->Host         = $credentials['host'];       // Specify main and backup SMTP servers
        $mail->Port         = $credentials['host'];       // TCP port to connect to
        $mail->Username     = $credentials['user'];       // SMTP username
        $mail->Password     = $credentials['password'];   // SMTP password
        $mail->SMTPAuth     = true;                       // enable SMTP authentication
        $mail->SMTPSecure   = $credentials['security'];   // security method
        if ($from = $credentials['from']) {
            $mail->setFrom($from['email'], $from['name']);
        }
        if ($to    = $credentials['to'])    {
            $mail->addAddress($to['email'], $to['name']);
        }
        if ($bcc   = $credentials['bcc'])   {
            $mail->addBCC($bcc['email']);
        }
        if ($reply = $credentials['reply']) {
            $mail->addReplyTo($reply['email']);
        }
        $mail->isHTML($content['html'] ?? false);
        $mail->Subject = $content['subject']  ?? '';
        $mail->Body    = $content['bodyHTML'] ?? '';
        $mail->AltBody = $content['body']     ?? '';
        if (!$mail->send()) {
            echo 'Message could not be sent';
        } else {
            echo 'Message has been sent';
        }
    }
}
