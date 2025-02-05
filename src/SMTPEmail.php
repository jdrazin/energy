<?php

namespace Src;
use Credentials;
use PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Copyright (c) 2016 Simple Matters Limited -- All rights reserved
 *
 */

class SMTPEmail
{
    public function email()
    {
        $mail = new PHPMailer();
        $mail->SMTPDebug = 3;                                 // Enable verbose debug output
        $mail->Debugoutput = 'html';
        $mail->isSMTP();                                      // Set mailer to use SMTP
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true]];
        $mail->Host = Credentials::E_HOST;       // Specify main and backup SMTP servers
        $mail->Username = Credentials::E_USERNAME;   // SMTP username
        $mail->Password = Credentials::E_PASSWORD;   // SMTP password
        $mail->SMTPAuth = true;                      // Enable SMTP authentication
        $mail->SMTPSecure = 'tls';                     // Enable TLS encryption, `ssl` also accepted
        $mail->Port = 587;                       // TCP port to connect to
        $mail->setFrom('info@shopsee.co.uk', 'info@shopsee.co.uk');
        $mail->addAddress('jonathan@drazin.net', 'Jonathan Drazin');     // Add a recipient
        $mail->addReplyTo('info@shopsee.co.uk', 'info@shopsee.co.uk');
        $mail->addBCC('info@shopsee.co.uk');
        $mail->isHTML(true);                                  // Set email format to HTML
        $mail->Subject = 'Here is the subject';
        $mail->Body = 'This is the HTML message body <b>in bold!</b>';
        $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';
        if (!$mail->send()) {
            echo 'Message could not be sent';
        } else {
            echo 'Message has been sent';
        }
    }
}
