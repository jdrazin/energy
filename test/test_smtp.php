<?php //
declare(strict_types=1);
namespace Src;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

$credentials = [
                    'host'      =>  'smtp-relay.brevo.com',
                    'port'      =>  587,
                    'user'      =>  '86b611001@smtp-brevo.com',
                    'password'  =>  'YzS12IFnJfsK0EG6',
                    'security'  =>  'tls',
                    'from'      =>  ['email' => 'jonathan@drazin.net', 'name' => 'Jonathan Drazin'],
                    'to'        => 	['email' => 'jonathan@drazin.net', 'name' => 'Jonathan Drazin'],
                    'bcc'       =>  [],
                    'reply'     =>  [],
                ];

$content =  [
                'subject'   => 'Test Subject',
                'html'      => false,
                'bodyHTML'  => 'This is the HTML message body <b>in bold!</b>',
                'bodyAlt'   => 'This is the body in plain text for non-HTML mail clients',
            ];

try {
  (new SMTPEmail())->email($credentials, $content);       // traverse all tariffs
  exit(0);
}
catch (Exception $e) {

}