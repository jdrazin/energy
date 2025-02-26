<?php //
declare(strict_types=1);
namespace Src;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

$credentials = [
                    'host'      =>  'smtp.gmail.com',
                    'port'      =>  587,
                    'user'      =>  'visionsrenewable@gmail.com',
                    'password'  =>  'ucdx rhhu wtyz ekxy',
                    'security'  =>  'tls',
                    'from'      =>  ['email' => 'visionsrenewable@gmail.com', 'name' => 'Renewable Visions'],
                    'to'        => 	['email' => 'jonathan@drazin.net',        'name' => 'Jonathan Drazin'],
                    'cc'        =>  [],
                    'bcc'       =>  [],
                    'reply'     =>  [],
                ];

$content =  [
                'subject'   => 'Test Subject A',
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